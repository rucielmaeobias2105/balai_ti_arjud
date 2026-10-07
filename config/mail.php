<?php

use App\Support\SmtpScheme;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Promo Announcement Budget (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a single promo announcement may spend sending email before it
    | stops sending it and finishes the rest of the list in-app only.
    |
    | `MAIL_TIMEOUT` bounds one send; this bounds the whole run. Both exist
    | because notifications are sent inside the request that triggered them,
    | with no queue and no worker to fall back on — see
    | `PromoController::announce()`, which is the only place this is read.
    |
    | The default leaves room for a couple of dozen healthy sends and, if the
    | mail server is not answering, stops well inside a 30-second PHP
    | execution limit rather than spending the whole list's worth of timeouts
    | and returning a 502 to the admin.
    |
    */

    'promo_announce_budget' => (int) env('MAIL_ANNOUNCE_BUDGET', 15),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',

            /*
             * `scheme`, not `encryption`.
             *
             * Laravel 11 dropped the `encryption` key from the SMTP mailer and
             * reads `scheme` instead. So `MAIL_ENCRYPTION=tls` — the setting every
             * pre-Laravel-11 guide recommends — would be read by nothing, and the
             * transport would fall back to its port-derived default while the
             * setting looked like it worked.
             *
             * `SmtpScheme` maps the familiar name onto the one the transport reads:
             * `tls` -> `smtp` (STARTTLS, port 587), `ssl` -> `smtps` (port 465).
             * With both variables unset the scheme stays null and Laravel derives
             * it from the port, which is the behaviour that was already relied on.
             */
            'scheme' => SmtpScheme::forEncryption(
                env('MAIL_ENCRYPTION'),
                env('MAIL_SCHEME'),
            ),

            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),

            /*
             * Bounded on purpose, because notifications are sent inside the
             * request that caused them.
             *
             * This was `null`, which means "whatever PHP's
             * `default_socket_timeout` is" — 60 seconds on a stock build. On
             * shared hosting that is not a slow email, it is a 502: the mail
             * server accepts the TCP connection and then goes quiet (port 25 is
             * commonly blocked or silently dropped), the request sits inside a
             * PHP execution limit, and the visitor gets a gateway error for a
             * booking that was written to the database correctly and is now
             * waiting for mail that was never going to arrive.
             *
             * Ten seconds is long enough for a real handshake and an SMTP
             * conversation with Gmail or any ordinary relay, and short enough
             * that a dead one fails inside the hosting plan's request budget. It
             * only changes how long a *failing* send takes — a working one is
             * unaffected — and `SendsNotificationsQuietly` turns the failure into
             * a log line rather than a 500 either way.
             *
             * Override with MAIL_TIMEOUT on a host that needs longer.
             */
            'timeout' => (int) env('MAIL_TIMEOUT', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
