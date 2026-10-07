<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | InfinityFree terminates TLS in its own frontend and hands Apache a plain
    | HTTP request carrying `X-Forwarded-Proto: https`. Laravel ignores that
    | header unless the address it came from is trusted, so on the deployed site
    | `Request::isSecure()` is false and every generated URL comes back as
    | `http://`. Browsers then refuse to load the stylesheet and the script
    | bundle on an `https://` page (mixed content) and the site renders with no
    | styling and no JavaScript at all, with nothing in the log to explain it.
    |
    | Set `TRUSTED_PROXIES=*` in the server's `.env` and the header is believed.
    | `*` means "trust whoever is calling", which is the only thing that can be
    | configured here: InfinityFree runs every account behind a shared, changing
    | set of frontend addresses and publishes none of them. The cost is that a
    | visitor can spoof `X-Forwarded-For` and therefore the IP half of a rate
    | limiter key. Nothing in this app reads the client IP for an access
    | decision that matters - logins throttle on IP *and* username, so a
    | spoofed address only moves the attacker into a different bucket - so
    | trading that for working TLS is the right way round.
    |
    | Locally there is no proxy, so the variable stays unset and no header is
    | trusted. Only set it on the server.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Headers
    |--------------------------------------------------------------------------
    |
    | Null means "let `Illuminate\Http\Middleware\TrustProxies` use its default",
    | which is every `X-Forwarded-*` header it knows about - including
    | `X-Forwarded-Proto`, the one that matters here. Listed explicitly so the
    | set is visible rather than implied.
    |
    */

    'headers' => env('TRUSTED_PROXY_HEADERS'),

];