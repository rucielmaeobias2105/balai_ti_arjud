<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * The `public` disk is the web-served `public/storage` directory itself,
         * not `storage/app/public`.
         *
         * Laravel's default pairs those two with a symbolic link, and that is
         * fine on a laptop and fatal on shared hosting: InfinityFree does not
         * allow `php artisan storage:link`, and a symlink cannot travel through
         * FTP or a git checkout anyway. The link is also gitignored, so the
         * repository never carried it — meaning every uploaded promo, technician
         * photo and avatar resolved to `/storage/...` and 404'd on the live site
         * while working perfectly on the developer's machine.
         *
         * Writing uploads straight into the served directory removes the link
         * from the picture entirely. Nothing has to be created at deploy time, so
         * the app behaves the same on XAMPP, on a local server and on any host
         * that will simply serve files out of `public/`.
         *
         * The URL is built from the request rather than from `APP_URL` — see
         * `User::profile_photo_url` for why that distinction matters — so a
         * deployed site serves its uploads from whatever host it is actually
         * reached on.
         */
        'public' => [
            'driver' => 'local',
            'root' => public_path('storage'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Nothing here, and that is a decision rather than an omission. See the
    | `public` disk above: uploads are written straight into `public/storage`,
    | so there is no link to create. This block is left in place because Laravel's
    | own comments live here and because anyone who runs `storage:link` out of
    | habit should find it does nothing rather than silently re-join the two
    | directories this project deliberately stopped using.
    |
    | Note `links` is declared, and empty, rather than left out. Laravel 11 fills
    | in any key this file omits from its own bundled copy — which still ships the
    | `public/storage` -> `storage/app/public` symlink — so omitting it would let
    | `php artisan storage:link` recreate exactly the link this project removed.
    | On Windows that silently replaces a real, tracked directory with a symlink
    | and every committed file inside it disappears from the working tree.
    |
    */

    'links' => [],

];
