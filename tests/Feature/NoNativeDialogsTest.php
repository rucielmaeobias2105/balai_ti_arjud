<?php

namespace Tests\Feature;

use App\Enums\InquiryTopic;
use App\Models\ContactMessage;
use App\Models\ServiceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Guards the removal of every native browser dialog.
 *
 * `confirm()`, `alert()` and `prompt()` were being phased out screen by screen,
 * and that is the failure mode this file exists to stop: the last native call is
 * almost never in the place being worked on. The audit below is deliberately not
 * a list of the screens that were converted — a new `onsubmit="return
 * confirm(…)"` on a screen nobody remembers converting would pass every one of
 * those. It reads the whole of `resources/views` and `resources/js` instead, so
 * the guarantee is a property of the codebase rather than of anyone's memory.
 *
 * The audit has to see through comments, because this project documents *why*
 * each dialog was replaced in the very file that used to call it — and
 * `x-ui.confirm-dialog`'s own docblock is mostly about `confirm()`. Comments are
 * blanked rather than deleted so a violation is still reported at its real line
 * number.
 */
class NoNativeDialogsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A call, not a mention: `confirm(`, `window.alert(`, `prompt (` and so on.
     *
     * The leading lookbehind rejects `window.confirm` behind a dot so that a
     * namespaced or method-chained reference is not matched twice, and rejects
     * `$confirm(` / `myAlert(` so a project's own variable cannot raise a false
     * positive. `window.` is consumed as part of the match rather than left to
     * the lookbehind because `window.alert(` must still be caught.
     */
    private const NATIVE_DIALOG = '/(?<![.\w$])(?:window\s*\.\s*)?(?:confirm|alert|prompt)\s*\(/';

    /* ------------------------------------------------------------------ */
    /* The codebase-wide audit                                            */
    /* ------------------------------------------------------------------ */

    public function test_no_view_or_script_calls_a_native_browser_dialog(): void
    {
        $violations = [];

        foreach ($this->sources() as $file) {
            $contents = $this->withoutComments(File::get($file));

            if (! preg_match_all(self::NATIVE_DIALOG, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$call, $offset]) {
                $violations[] = sprintf(
                    '%s:%d  %s',
                    $this->relative($file),
                    substr_count(substr($contents, 0, $offset), "\n") + 1,
                    trim($this->lineAt($contents, $offset)),
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "These call a native dialog. Use x-ui.confirm-dialog for a Yes/No\n".
            "question, x-ui.dialog for a message, and a real field for input:\n".
            implode("\n", $violations),
        );
    }

    /**
     * The three file trees the audit covers.
     *
     * Blade, because that is where `onsubmit` attributes live; JS, because an
     * `alert()` can be raised from an Alpine handler instead of a template.
     */
    private function sources(): array
    {
        $files = [];

        foreach (['views', 'js'] as $directory) {
            $files = array_merge(
                $files,
                File::allFiles(resource_path($directory)),
            );
        }

        $this->assertNotEmpty($files, 'The audit found no files to read, so it proved nothing.');

        // `allFiles` hands back SplFileInfo objects; the audit wants paths.
        return array_values(array_filter(
            array_map(fn ($file) => $file->getPathname(), $files),
            fn ($path) => in_array(pathinfo($path, PATHINFO_EXTENSION), ['blade', 'php', 'js'], true),
        ));
    }

    /**
     * Blank out comments while keeping every line break.
     *
     * Blade `{{-- --}}` and HTML `<!-- -->` comments come first, then JS block
     * and line comments — Blade views carry inline `<script>` blocks, so they
     * need all four. A line comment is only recognised at the start of a line or
     * after whitespace, because `https://` in an `href` is not a comment and
     * treating it as one would delete the rest of that line's assertions.
     *
     * Newlines are preserved so `PREG_OFFSET_CAPTURE` still maps to the line a
     * human would jump to.
     */
    private function withoutComments(string $contents): string
    {
        // A UTF-8 BOM would sit in front of a leading `//` and stop the line
        // comment pattern below from anchoring, which would report the text
        // inside a comment as a live call.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        // `preg_replace_callback` hands the callback the full match on a stock PHP and
        // the matches array on some builds, so the argument is accepted either
        // way rather than the test only passing on one of them.
        $blank = function ($match): string {
            $text = is_array($match) ? $match[0] : $match;

            return str_repeat("\n", substr_count($text, "\n"));
        };

        $contents = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $contents);
        $contents = preg_replace_callback('/<!--.*?-->/s', $blank, $contents);
        $contents = preg_replace_callback('#/\*.*?\*/#s', $blank, $contents);
        $contents = preg_replace_callback('/(?:^|\s)\/\/.*$/m', $blank, $contents);

        return $contents;
    }

    private function lineAt(string $contents, int $offset): string
    {
        $line = substr($contents, $offset);
        $end = strpos($line, "\n");

        return $end === false ? $line : substr($line, 0, $end);
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    /* ------------------------------------------------------------------ */
    /* The switch that lost its prompt                                    */
    /* ------------------------------------------------------------------ */

    /**
     * `x-ui.table-toggle` had a `confirm` prop that hung
     * `onsubmit="return confirm(…)"` on its form — and nothing passed it, so it
     * was a live native dialog waiting for the first caller to hide a service or
     * un-book a technician. Both are reversible from the same switch.
     *
     * Asserted against the component source rather than a page, because the whole
     * point is that no page uses it: there is nothing to assert against in a
     * rendered page.
     */
    public function test_the_table_toggle_has_no_native_confirmation_branch(): void
    {
        $source = File::get(resource_path('views/components/ui/table-toggle.blade.php'));
        $code = $this->withoutComments($source);

        $this->assertStringNotContainsString(
            'onsubmit',
            $code,
            'The toggle should submit straight through; there is no prompt to cancel.',
        );
        $this->assertStringNotContainsString(
            "'confirm' =>",
            $code,
            'A confirm prop with no dialog behind it is the thing that was removed.',
        );
        $this->assertStringNotContainsString(
            'confirm(',
            $code,
            'table-toggle must not call a native confirm.',
        );

        // And the form still works without JavaScript, which is why the prompt
        // could simply go rather than be rebuilt.
        $this->assertStringContainsString('method="POST"', $code);
        $this->assertStringContainsString("@method('PATCH')", $code);
    }

    /* ------------------------------------------------------------------ */
    /* Messages inbox                                                      */
    /* ------------------------------------------------------------------ */

    public function test_deleting_a_message_uses_the_shared_confirmation_dialog(): void
    {
        $message = ContactMessage::create([
            'name' => 'Rosa Diaz',
            'email' => 'rosa@example.test',
            'topic' => InquiryTopic::GeneralInquiry,
            'message' => 'Do you take walk-ins on a Sunday?',
        ]);

        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.messages.index'))
            ->assertOk()
            ->getContent();

        // The row button asks, and it carries the row's own URL so one dialog
        // can serve every message on the page.
        $this->assertStringContainsString('confirm-delete-message', $html);
        $this->assertMatchesRegularExpression(
            '/id: '.$message->id.', action:/',
            $html,
            'The trigger should hand the dialog the id and the URL to post to.',
        );

        // The dialog itself.
        $this->assertStringContainsString('x-on:confirm-delete-message.window', $html);
        $this->assertStringContainsString('Delete this message?', $html);
        $this->assertStringContainsString('>Yes, delete it</', $html);
        $this->assertStringContainsString('>Cancel</', $html);

        // One DELETE form for the whole inbox. A form per row would scale with
        // the page and repeat the CSRF token once per message; the row buttons
        // carry no form at all now.
        $this->assertSame(
            1,
            substr_count($html, 'name="_method" value="DELETE"'),
            'The inbox should have one DELETE form, behind the dialog.',
        );

        // The per-row "Save & Mark Read" PATCH form is untouched by that.
        $this->assertStringContainsString('name="admin_reply"', $html);
    }

    public function test_the_message_delete_route_still_deletes(): void
    {
        $message = ContactMessage::create([
            'name' => 'Rosa Diaz',
            'email' => 'rosa@example.test',
            'topic' => InquiryTopic::GeneralInquiry,
            'message' => 'Do you take walk-ins on a Sunday?',
        ]);

        $this->actingAs($this->makeAdmin(), 'admin')
            ->delete(route('admin.messages.destroy', $message))
            ->assertRedirect();

        $this->assertDatabaseMissing('contact_messages', ['id' => $message->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Service variants                                                    */
    /* ------------------------------------------------------------------ */

    public function test_removing_a_service_variant_uses_the_shared_confirmation_dialog(): void
    {
        $service = $this->makeService();
        $variant = ServiceVariant::create([
            'service_id' => $service->id,
            'name' => 'Long Hair',
            'price' => '799',
            'base_price' => '799.00',
            'duration_minutes' => 60,
            'is_default' => false,
        ]);

        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.services.variants', $service))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('confirm-remove-variant', $html);

        // The name travels with the event, so the sentence reads "Remove Long
        // Hair?" rather than "Remove 1 variant?" — the part that stops the
        // wrong row being removed.
        $this->assertStringContainsString("name: 'Long Hair'", $html);

        $this->assertStringContainsString('x-on:confirm-remove-variant.window', $html);
        $this->assertStringContainsString('Remove this variant?', $html);
        $this->assertStringContainsString('>Yes, remove it</', $html);
        $this->assertStringContainsString('>Cancel</', $html);

        // "Remove" is reversible in the sense that it is a delete of a style
        // option, not a customer record, but the page's own toggle idiom is not
        // used here — this stays a danger-toned dialog.
        $this->assertSame(
            1,
            substr_count($html, 'name="_method" value="DELETE"'),
            'The variants page should have one DELETE form, behind the dialog.',
        );

        // The per-variant PATCH edit form is untouched.
        $this->assertStringContainsString('name="price"', $html);

        $this->assertSame('Long Hair', $variant->fresh()->name);
    }

    /* ------------------------------------------------------------------ */
    /* Terms editor                                                        */
    /* ------------------------------------------------------------------ */

    public function test_the_terms_editor_asks_for_a_link_in_a_modal_not_a_prompt(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.terms.create'))
            ->assertOk()
            ->getContent();

        // The toolbar button now dispatches; the editor applies what comes back.
        // Alpine's `@click` shorthand is what the toolbar uses throughout.
        $this->assertStringContainsString('@click="insertLink()"', $html);
        $this->assertStringContainsString('terms-insert-link-confirmed', $html);
        $this->assertStringContainsString('applyLink($event.detail.url)', $html);

        // A real field, not a prompt string: it can be pasted, corrected and read
        // aloud, and `type="url"` rejects a bare "example.com".
        $this->assertStringContainsString('id="terms-link-url"', $html);
        $this->assertStringContainsString('type="url"', $html);
        $this->assertMatchesRegularExpression('/>\s*Insert link\s*</', $html);

        // It is the shared dialog shell, so it gets the toned header band, the
        // icon slot and backdrop dismissal rather than a sixth hand-rolled
        // skeleton. The icon name itself disappears into the rendered SVG, so
        // the shell's own class is what is asserted.
        $this->assertStringContainsString('Insert Link', $html);
        $this->assertStringContainsString('modal-panel', $html);
        $this->assertStringContainsString('bg-info', $html, 'The info tone should render its header band.');
    }

    /**
     * The empty-policy refusal.
     *
     * This one was doubly broken and the fix is the interesting part. The old
     * guard hung `alert()` on a `submit` listener and `return`ed from the
     * callback — and `addEventListener` ignores a return value, so the empty
     * form posted anyway and the "modal" was decoration. The replacement has to
     * be on Alpine's `submit` binding, where `preventDefault()` actually
     * cancels.
     */
    public function test_the_terms_editor_refuses_to_save_an_empty_policy(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.terms.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'x-on:submit="guardSubmit($event)"',
            $html,
            'The guard must be an Alpine submit binding, not a DOM listener.',
        );
        $this->assertStringContainsString('event.preventDefault()', $html);
        $this->assertStringContainsString('terms-content-empty', $html);

        // The message the alert used to raise, now in the modal's body.
        $this->assertStringContainsString('Add Terms Content', $html);
        $this->assertStringContainsString('Please enter the terms content before saving.', $html);
        $this->assertStringContainsString('>Got it</', $html);
        $this->assertStringContainsString('modal-panel', $html, 'It should be the shared dialog shell.');

        // And no leftover DOM-listener guard, which would double-fire.
        $this->assertStringNotContainsString(
            "addEventListener('submit'",
            $html,
            'The old listener could not cancel a submit and would fire alongside the new guard.',
        );
    }

    /**
     * Both dialogs sit inside the terms `<form>`, so every button inside them has
     * to be `type="button"`. A single `type="submit"` in the link dialog would
     * post the policy — quietly saving whatever the editor happened to contain —
     * from a dialog whose only job is to read a URL.
     */
    public function test_the_terms_dialog_buttons_do_not_submit_the_policy_form(): void
    {
        $html = $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.terms.create'))
            ->assertOk()
            ->getContent();

        foreach (['Cancel', 'Insert link', 'Got it'] as $label) {
            $this->assertMatchesRegularExpression(
                '/<button type="button"[^>]*>\s*'.preg_quote($label, '/').'/',
                $html,
                "The \"{$label}\" button must be type=\"button\".",
            );
        }

        // Exactly one submit inside the terms form: the page's own Save. The count is
        // scoped to the form rather than the page, because the admin layout has
        // its own submit controls (search, menus) that this test has no business
        // asserting about.
        $start = strpos($html, 'x-data="richTextEditor()"');
        $this->assertNotFalse($start, 'The terms form should carry the editor scope.');

        $form = substr($html, $start, strpos(substr($html, $start), '</form>') - $start);

        $this->assertSame(
            1,
            substr_count($form, '<button type="submit"'),
            'Only Save should submit the terms form.',
        );
    }
}