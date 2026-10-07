<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TermsCategory;
use App\Http\Controllers\Controller;
use App\Models\TermsAndCondition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin Flow 10 — Terms & Conditions Editor.
 *
 * One row per category. A save overwrites that row in place; publishing is what
 * decides whether customers see the text.
 */
class TermsController extends Controller
{
    public function index(): View
    {
        $terms = TermsAndCondition::query()
            ->with('admin')
            ->orderBy('category')
            ->get()
            ->groupBy(fn (TermsAndCondition $term) => $term->category->value);

        return view('admin.terms.index', [
            'grouped' => $terms,
            'categories' => TermsCategory::options(),
            'published' => collect(TermsCategory::cases())
                ->mapWithKeys(fn (TermsCategory $c) => [$c->value => TermsAndCondition::publishedFor($c)])
                ->all(),
        ]);
    }

    public function create(Request $request): View
    {
        $resolved = TermsCategory::tryFrom($request->query('category')) ?? TermsCategory::Booking;

        return view('admin.terms.create', [
            // The shared form partial always operates on a model. A category that
            // already has a row edits that row rather than pre-filling an empty
            // one, so "New" for a policy that exists lands the admin on the text
            // that is actually live instead of a blank box that would wipe it.
            'term' => TermsAndCondition::forCategory($resolved) ?? new TermsAndCondition(['category' => $resolved]),
            'category' => $resolved,
            'categories' => TermsCategory::options(),
        ]);
    }

/**
 * A save writes the text. It does not publish.
 *
 * Publishing is a separate action — `publish()`, below — because that is the
 * only honest shape for the question an admin is actually asking. "I have
 * edited the terms" and "I have decided customers should now see this version"
 * are two different decisions, and folding the second into the first means every
 * typo fix silently changes what the public is reading.
 *
 * This matters most here, where a policy is one row and not a history: with the
 * `is_published` checkbox still on this form, saving an edit to a live policy
 * sent `is_published` as false and quietly took the policy down. The checkbox is
 * gone from this page for the same reason.
 */
public function store(Request $request): RedirectResponse
{
    $data = $request->validate([
        'category' => ['required', Rule::in(TermsCategory::values())],
        'content' => ['required', 'string', 'min:10'],
    ]);

    $category = TermsCategory::from($data['category']);
    $existing = TermsAndCondition::forCategory($category);

    if ($existing && $existing->content === $data['content']) {
        return back()->withInput()->withErrors([
            'content' => 'This is identical to the current saved text.',
        ]);
    }

    $term = DB::transaction(function () use ($data, $category, $request, $existing) {
        /*
         * Publication state is carried across untouched.
         *
         * A live row keeps `is_published` and its original `published_at`, so
         * editing the text does not unpublish the policy and does not rewrite the
         * date the customer-facing version last went live. A brand new row starts
         * unpublished and is published by the button beside it.
         */
        $attributes = [
            'category' => $category,
            'content' => $data['content'],
            'is_published' => $existing?->is_published ?? false,
            'published_at' => $existing?->published_at,
            'created_by' => $existing?->created_by ?? $request->user('admin')->id,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return TermsAndCondition::create($attributes);
    });

    return redirect()
        ->route('admin.terms.index')
        ->with('status', $term->is_published
            ? "{$category->label()} terms saved and still live for customers."
            : "{$category->label()} terms saved. Publish when you are ready.");
}

    public function edit(TermsAndCondition $term): View
    {
        return view('admin.terms.edit', [
            'term' => $term,
            'categories' => TermsCategory::options(),
        ]);
    }

    public function update(Request $request, TermsAndCondition $term): View|RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(TermsCategory::values())],
            'content' => ['required', 'string', 'min:10'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $category = TermsCategory::from($data['category']);
        $publish = $request->boolean('is_published');

        if ($term->is_published && ! $publish) {
            /*
             * A live policy cannot be quietly demoted from the edit form.
             *
             * This used to read "Unpublish directly from the list", and it sent
             * the admin to a screen that no longer exists, on the one path where
             * the checkbox had just been unticked. The checkbox is the publish
             * control, so the message now says what to do with it instead.
             */
            return back()->withInput()->withErrors([
                'is_published' => 'This policy is live. Leave "Publish this policy" ticked and save to keep it live for customers.',
            ]);
        }

        if ($term->is_published && $term->category !== $category) {
            /*
             * Moving a live policy to another category would unpublish one
             * category and publish another in a single save — the same surprise
             * as demoting it — so it is refused rather than half-done.
             */
            return back()->withInput()->withErrors([
                'category' => 'This policy is live and cannot be moved to a different category.',
            ]);
        }

        if ($term->category !== $category && TermsAndCondition::forCategory($category)) {
            // A category owns exactly one row, so a draft can only move into one
            // that does not already have a row.
            return back()->withInput()->withErrors([
                'category' => 'That category already has terms. Delete them before moving this policy across.',
            ]);
        }

        $term->update([
            'category' => $category,
            'content' => $data['content'],
            'is_published' => $publish,
            'published_at' => $publish ? ($term->published_at ?? now()) : null,
        ]);

        return back()->with('status', "{$category->label()} terms saved.");
    }

/**
 * Publish a policy to customers, retiring whatever was published before it.
 *
 * This is the action the checkbox was pretending to be, and it is deliberately
 * not a checkbox: publishing is a decision, and a decision is a button somebody
 * presses on purpose.
 *
 * `publishedFor()` reads the first published row for a category and
 * `terms_and_conditions` has a unique index on `category`, so today there is
 * only ever one row per policy and the "retire the previous version" half of
 * this is a no-op. It is written anyway, for the installs where it is not: a
 * database that still carries a superseded row from before that index existed
 * would have `publishedFor()` return whichever of the two came back first, and
 * two rows both claiming to be live is precisely the state publishing is
 * supposed to prevent. The `updating` sweep is one extra UPDATE on a table that
 * holds three short documents.
 */
public function publish(TermsAndCondition $term): RedirectResponse
{
    DB::transaction(function () use ($term) {
        TermsAndCondition::query()
            ->where('category', $term->category)
            ->whereKeyNot($term->getKey())
            ->update([
                'is_published' => false,
                'published_at' => null,
            ]);

        $term->update([
            'is_published' => true,
            'published_at' => now(),
        ]);
    });

    return back()->with('status', "{$term->category->label()} terms are now live for customers.");
}

    public function destroy(TermsAndCondition $term): RedirectResponse
    {
        if ($term->is_published) {
            return back()->withErrors([
                'term' => 'Unpublish this policy before deleting it.',
            ]);
        }

        $term->delete();

        return back()->with('status', "{$term->category->label()} terms deleted.");
    }
}
