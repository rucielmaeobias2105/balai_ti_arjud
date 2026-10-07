<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\User;
use App\Notifications\PromoAnnouncementNotification;
use App\Support\DataTable;
use App\Support\PromoBanner;
use App\Support\SendsNotificationsQuietly;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin Flow 13 — Promo / Announcements Management.
 * Active promos surface to customers as a footer banner and a notification.
 */
class PromoController extends Controller
{
    use SendsNotificationsQuietly;

    /** Columns the list lets an admin sort by. */
    private const SORTABLE = ['title', 'starts_at', 'ends_at', 'is_active', 'created_at'];

    public function index(Request $request): View
    {
        [$sort, $direction] = DataTable::sort($request, self::SORTABLE, 'starts_at');

        // Rendered whole and filtered and paged in the browser, so a search is
        // not a round trip per keystroke. `search` only seeds the search box.
        $promos = DataTable::applySort(
            Promo::query(),
            $sort,
            $direction,
            self::SORTABLE,
            'starts_at',
        )
            ->orderByDesc('starts_at')
            ->get();

        return view('admin.promos.index', [
            'promos' => $promos,
            'search' => $request->input('search'),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    public function create(): View
    {
        return view('admin.promos.create', [
            'promo' => new Promo,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePromo($request);

        $promo = Promo::create([
            ...$data,
            'image_path' => $this->storeImage($request),
            'is_active' => $request->boolean('is_active'),
        ]);

        PromoBanner::flush();

        return redirect()
            ->route('admin.promos.index')
            ->with('status', "Promo \"{$promo->title}\" created.");
    }

    public function edit(Promo $promo): View
    {
        return view('admin.promos.edit', [
            'promo' => $promo,
        ]);
    }

    public function update(Request $request, Promo $promo): RedirectResponse
    {
        $data = $this->validatePromo($request, $promo);

        /*
         * `image_path` is touched only when this request actually says something
         * about the picture: a new file replaces the old one, and `remove_image`
         * clears it. Without that condition, "rename this promo" would silently
         * empty its picture, because an absent file input is not the same thing
         * as an empty one and the admin did not ask for either.
         */
        if ($image = $this->storeImage($request)) {
            $this->deleteImage($promo);
            $data['image_path'] = $image;
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($promo);
            $data['image_path'] = null;
        }

        $promo->update([
            ...$data,
            'is_active' => $request->boolean('is_active'),
        ]);

        PromoBanner::flush();

        return redirect()
            ->route('admin.promos.index')
            ->with('status', "Promo \"{$promo->title}\" updated.");
    }

    public function destroy(Promo $promo): RedirectResponse
    {
        $title = $promo->title;

        // Delete the file before the row. The row is soft-deleted, so it can be
        // restored — and a restored promo whose file has been deleted from disk
        // would show a broken picture. Either way the upload is unreferenced now.
        $this->deleteImage($promo);

        $promo->delete();

        PromoBanner::flush();

        return redirect()
            ->route('admin.promos.index')
            ->with('status', "Promo \"{$title}\" deleted.");
    }

    /**
     * Push the promo to customers as a notification.
     *
     * THE REQUEST HAS A CLOCK, AND THE LIST HAS NO TOP
     *
     * This runs inside the request: no queue, and InfinityFree has no worker to
     * run one if there were. Each recipient costs one SMTP conversation on top
     * of one row written, and `MAIL_TIMEOUT` bounds a *failing* conversation at
     * ten seconds — so a mail server that accepts the connection and then goes
     * quiet costs ten seconds per customer until the list ends or the host's
     * PHP execution limit does. Fifty customers is five hundred seconds: a 502
     * for the admin, and a promo half-announced.
     *
     * The half-announced part is what makes a time limit necessary rather than
     * merely nice. `notified` is written after the loop, so a request killed
     * mid-way leaves the button pressable again, and pressing it again sends a
     * second copy to every customer who already got the first one.
     *
     * So the two halves of the notification are decided per recipient: the
     * in-app row is written for everybody, and the email is attempted only
     * while the budget lasts. `PromoAnnouncementNotification::via()` is what
     * turns `$withMail` off. Past the deadline the loop still runs — it is now
     * just one cheap INSERT each — so the flag, the count and the message below
     * all describe what actually happened instead of what was hoped for.
     *
     * The budget is a config value rather than a constant so a host with a
     * generous limit can raise it, and so the exhausted path can be tested
     * without waiting for it.
     *
     * @see config/mail.php for the budget and its default.
     */
    public function announce(Request $request, Promo $promo): RedirectResponse
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['all', 'active', 'recent'])],
        ]);

        $users = match ($data['audience']) {
            'active' => User::query()->where('is_active', true)->get(),
            'recent' => User::query()
                ->where('is_active', true)
                ->where('created_at', '>=', now()->subDays(90))
                ->get(),
            default => User::query()->where('is_active', true)->get(),
        };

        $deadline = microtime(true) + (int) config('mail.promo_announce_budget', 15);
        $emailed = 0;

        foreach ($users as $user) {
            $withMail = microtime(true) < $deadline;
            $emailed += $withMail ? 1 : 0;

            $this->notifyQuietly($user, new PromoAnnouncementNotification($promo, $withMail), 'promo announcement');
        }

        $promo->forceFill(['notified' => true])->save();

        $status = "Promo announced to {$users->count()} customer(s).";

        if ($emailed < $users->count()) {
            $status .= ' Emailing stopped at the time limit — every customer still received the announcement in their account.';
        }

        return back()->with('status', $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePromo(Request $request, ?Promo $promo = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:3000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],

            // Optional: a promo with no picture is a perfectly good promo, and
            // the customer card falls back to a flourish. 4 MB and JPG/PNG/WEBP
            // match every other photo field in the panel, so an admin who has
            // already uploaded a service photo is not surprised here.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
        ], [
            'ends_at.after_or_equal' => 'The end date cannot be before the start date.',
            'image.image' => 'The promo image must be an image.',
            'image.mimes' => 'The promo image must be a JPG, PNG or WEBP file.',
            'image.max' => 'The promo image may not be larger than 4 MB.',
        ]);

        // Neither is a column: the file is moved to disk by `storeImage()` and
        // `remove_image` is an instruction, not data.
        unset($data['image'], $data['remove_image']);

        return $data;
    }

    /** The uploaded image's path on the public disk, or null if none arrived. */
    protected function storeImage(Request $request): ?string
    {
        return $request->hasFile('image')
            ? $request->file('image')->store(Promo::IMAGE_DIRECTORY, 'public')
            : null;
    }

    protected function deleteImage(Promo $promo): void
    {
        if ($path = $promo->imagePath()) {
            Storage::disk('public')->delete($path);
        }
    }
}
