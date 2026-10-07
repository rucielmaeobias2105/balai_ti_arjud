<?php

namespace Tests\Feature;

use App\Models\Promo;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uploads have to be reachable after a plain `git clone` onto shared hosting.
 *
 * Every other image test fakes the disk, which is the right way to test that an
 * upload is *written*. This one is about where the file ends up and whether the
 * web server can be handed the same path, because that is the half a fake
 * cannot see and the half that was broken in production.
 *
 * The fault being guarded against: the `public` disk used to be rooted at
 * `storage/app/public` and served through `public/storage`, the two joined only
 * by a symbolic link. That link is gitignored, so it existed on the developer's
 * machine and nowhere else. Every promo, technician photo, category picture and
 * customer avatar worked locally and 404'd on InfinityFree, which cannot run
 * `storage:link` and cannot receive a symlink over FTP in any case.
 */
class UploadedFilesSurviveCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The disk and the served directory are the same place
    |--------------------------------------------------------------------------
    */

    public function test_the_public_disk_is_rooted_in_the_served_directory(): void
    {
        $this->assertSame(
            public_path('storage'),
            config('filesystems.disks.public.root'),
            'Uploads must be written into the directory the web server serves.',
        );
    }

    /**
     * The disk root is not enough on its own — it has to be reachable over HTTP
     * at the path the models build, which is what this checks.
     */
    public function test_the_served_directory_sits_inside_public(): void
    {
        $root = config('filesystems.disks.public.root');

        $this->assertStringStartsWith(
            public_path(),
            $root,
            'An uploads directory outside public/ cannot be served without a link, which is the thing being removed.',
        );
    }

    /**
     * No symlink is configured, and this is asserted rather than assumed.
     *
     * Laravel 11 backfills any key this config omits from its own bundled copy,
     * which still ships `public/storage` -> `storage/app/public`. So declaring
     * `links` as empty is load-bearing: without it, one `php artisan storage:link`
     * on a Windows machine replaces this real, tracked directory with a symlink
     * and every committed file inside it vanishes from the working tree.
     */
    public function test_no_symbolic_link_is_configured(): void
    {
        $this->assertSame(
            [],
            config('filesystems.links'),
            'A link here would re-join the two directories this project deliberately merged.',
        );
    }

    /** A real directory, not a link. */
    public function test_the_uploads_directory_is_a_real_directory_not_a_symlink(): void
    {
        $this->assertDirectoryExists(
            public_path('storage'),
            'The uploads directory must be committed so a fresh clone can serve it.',
        );

        $this->assertFalse(
            is_link(public_path('storage')),
            'A symlink here cannot survive an FTP upload or a git checkout.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The folders exist in a fresh checkout
    |--------------------------------------------------------------------------
    */

    /**
     * Every directory an upload is written into has to exist before the first
     * one is written, or the store fails on a missing path.
     *
     * Git cannot track an empty directory, so each of these folders carries a
     * `.gitignore` placeholder in the repository. That is the mechanism, so it
     * is the thing asserted — a directory that exists only because someone made
     * it by hand on one machine will be missing on the next.
     *
     * The list is derived from the code rather than typed out, so adding a
     * fourth upload location without its folder fails here instead of in
     * production.
     *
     * @return array<string, array{0: string}>
     */
    public static function uploadDirectories(): array
    {
        $directories = array_unique([
            Promo::IMAGE_DIRECTORY,
            'categories',
            'technician-photos',
            'profile-photos',
            'photos',
        ]);

        $cases = [];

        foreach ($directories as $directory) {
            $cases[$directory] = [$directory];
        }

        return $cases;
    }

    /**
     * @dataProvider uploadDirectories
     */
    public function test_each_upload_directory_is_committed_with_a_placeholder(string $directory): void
    {
        $path = public_path('storage/'.$directory);

        $this->assertDirectoryExists($path, 'The uploads directory must exist in a fresh checkout.');
        $this->assertFileExists(
            $path.'/.gitignore',
            'git cannot track an empty directory, so the placeholder is what makes this folder survive a clone.',
        );
    }

    /** Uploaded files stay out of the repository while their folders stay in. */
    public function test_uploads_are_ignored_but_their_folders_are_not(): void
    {
        $ignored = $this->gitCheckIgnore('public/storage/promos/uploaded-by-a-customer.jpg');

        $this->assertTrue(
            $ignored,
            'A customer-uploaded file must not be committable.',
        );

        foreach (['promos', 'categories', 'technician-photos', 'profile-photos'] as $directory) {
            $this->assertFalse(
                $this->gitCheckIgnore('public/storage/'.$directory.'/.gitignore'),
                'The placeholder for '.$directory.' must be tracked, or the folder is lost on clone.',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | A saved upload resolves to a real file
    |--------------------------------------------------------------------------
    */

    /**
     * The end-to-end claim: a path written by the disk resolves to a file under
     * `public/`, and the model's URL points at it.
     *
     * This is the test that would have failed before. `Storage::fake()` would
     * have passed it, because a fake is a temp directory and every path in it
     * exists by construction — which is exactly why a faked disk could not catch
     * a directory that is missing from a deployment.
     */
    public function test_a_saved_upload_resolves_to_a_file_the_server_can_serve(): void
    {
        $path = 'promos/'.uniqid('checkout').'.jpg';

        \Illuminate\Support\Facades\Storage::disk('public')->put($path, 'not-really-a-jpeg');

        // Not faked on purpose — see the docblock.

        $this->assertFileExists(
            public_path('storage/'.$path),
            'A file the disk reports as saved must exist under public/, or it cannot be served.',
        );

        // `forceFill()` returns the model, but chained onto `create()` it is the
        // save's boolean that comes back — so the instance is held explicitly
        // rather than chained.
        $promo = Promo::create([
            'title' => 'Anniversary',
            'description' => 'A multi-paragraph offer.',
            'starts_at' => today(),
            'ends_at' => today()->addMonth(),
            'is_active' => true,
        ]);

        $promo->forceFill(['image_path' => $path])->save();

        $this->assertStringContainsString(
            'storage/'.$path,
            $promo->fresh()->image_url,
            'The model must address the upload at the path the server serves.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Image URLs follow the request, not APP_URL
    |--------------------------------------------------------------------------
    */

    /**
     * Uploaded images are addressed with `url()` rather than `asset()`.
     *
     * `asset()` builds from `APP_URL`, which on a shared host is very often still
     * the `localhost` that `.env.example` ships with. A photo addressed that way
     * is a request to a different origin from the page asking for it, and the
     * browser shows a broken image for a file sitting on disk exactly where it
     * belongs. `User::profile_photo_url` already worked this way; these three
     * did not, and together they are every uploaded image on the site.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function imageUrlAccessors(): array
    {
        return [
            'promo' => [Promo::class, 'image_url', 'image_path'],
            'technician' => [Technician::class, 'photo_url', 'photo_path'],
            // A category's own column is `photo`, not `photo_path` — see
            // `ServiceCategory::uploadedPhoto()`.
            'category' => [ServiceCategory::class, 'photo_url', 'photo'],
            'customer avatar' => [User::class, 'profile_photo_url', 'profile_photo_path'],
        ];
    }

    /**
     * @dataProvider imageUrlAccessors
     *
     * @param  class-string  $model
     */
    public function test_an_image_url_follows_the_host_that_was_actually_requested(string $model, string $attribute, string $column): void
    {
        $instance = new $model;
        $instance->forceFill([$column => 'promos/x.jpg']);

        $url = $instance->{$attribute};

        $this->assertNotNull($url, 'A row with an uploaded image must produce a URL.');

        // `url()` resolves against the current request, which these tests make as
        // `http://localhost`. The distinction that matters is that the host comes
        // from the request and not from `APP_URL`, so APP_URL is set to something
        // else entirely and the URL must still not mention it.
        config(['app.url' => 'https://stale-configured-host.example']);

        $this->assertStringNotContainsString(
            'stale-configured-host.example',
            $instance->{$attribute},
            'An uploaded image addressed through APP_URL breaks on any host where APP_URL is wrong.',
        );
    }

    /**
     * The same four accessors, asserted through the rendered page rather than the
     * model.
     *
     * Worth the extra setup: it proves the value survives to the markup, which
     * is the only part a browser ever sees.
     */
    public function test_a_rendered_customer_page_addresses_its_image_without_app_url(): void
    {
        config(['app.url' => 'https://stale-configured-host.example']);

        Promo::create([
            'title' => 'Anniversary Special',
            'description' => 'A long offer.',
            'starts_at' => today()->subDay(),
            'ends_at' => today()->addMonth(),
            'is_active' => true,
        ])->forceFill(['image_path' => 'promos/shipped.jpg'])->save();

        $html = $this->get(route('promos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('storage/promos/shipped.jpg', $html);
        $this->assertStringNotContainsString(
            'stale-configured-host.example/storage/',
            $html,
            'The page must not point its pictures at the host named in APP_URL.',
        );
    }

    /** Runs `git check-ignore` and reports whether the path is ignored. */
    private function gitCheckIgnore(string $path): bool
    {
        $root = base_path();

        // `-q` so a tracked file exits 1 with no output, which is the answer.
        exec(sprintf('git -C %s check-ignore -q %s 2>&1', escapeshellarg($root), escapeshellarg($path)), $output, $status);

        return $status === 0;
    }
}
