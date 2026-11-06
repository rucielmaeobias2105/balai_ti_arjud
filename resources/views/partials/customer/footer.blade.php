{{--
    Site footer: brand, quick links, visit us, and a bottom bar.

    Three columns rather than the old two, and the middle one is a column of
    links instead of a hole. The brand column previously held the logo and
    nothing else — the description beside it had been commented out and left
    the logo stranded across two of four columns — so the layout had a gap
    where the "Explore" links used to be.

    Every contact fact below is read from `config/salon.php` through
    `x-salon.contact-row`, the same component the About page's Find Us card
    uses, and with the same `compact` size. That is not tidiness: those strings
    used to be typed out separately here and had already drifted, the phone
    being the worst case — the link dialled one number while the text beside it
    read another.

    The bottom bar was commented out for the same period the brand description
    was, and is back: a footer with no copyright and no way to the staff portal
    reads as an unfinished page.
--}}
<footer class="mt-auto border-t border-primary/10 bg-cream">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8 lg:py-14">
        <div class="grid gap-10 md:grid-cols-3 md:gap-8">
            {{-- Brand --}}
            <div>
                <x-brand.logo size="md" image="images/logo.png" />

                <p class="mt-4 max-w-sm text-sm leading-relaxed text-ink-muted">
                    {{ config('salon.tagline') }}
                </p>
            </div>

            {{-- Quick links --}}
            <nav aria-labelledby="footer-links-heading">
                <h4 id="footer-links-heading" class="mb-4 font-display text-sm font-semibold uppercase tracking-wider text-primary">
                    Quick Links
                </h4>

                {{-- Public routes only. A signed-out visitor must not meet a
                     link that bounces them to the login page from the one place
                     on the site that is supposed to be reachable without
                     signing in. --}}
                <ul class="space-y-2.5 text-sm text-ink-muted">
                    @foreach ([
                        ['Home', route('home')],
                        ['Services', route('services.index')],
                        ['Promo Offers', route('promos.index')],
                        ['About Us', route('about')],
                        ['Contact Us', route('contact.create')],
                    ] as [$label, $url])
                        <li>
                            <a href="{{ $url }}" class="transition hover:text-primary">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            {{-- Visit us --}}
            <div>
                <h4 class="mb-4 font-display text-sm font-semibold uppercase tracking-wider text-primary">
                    Visit Us
                </h4>

                <div class="space-y-3.5">
                    <x-salon.contact-row
                        compact
                        icon="heroicon-o-map-pin"
                        label="Location"
                    >{{ config('salon.address') }}</x-salon.contact-row>

                    <x-salon.contact-row
                        compact
                        icon="heroicon-o-phone"
                        label="Contact Number"
                        :href="'tel:'.config('salon.phone_e164')"
                    >{{ config('salon.phone_display') }}</x-salon.contact-row>

                    <x-salon.contact-row
                        compact
                        icon="heroicon-o-envelope"
                        label="Email"
                        :href="'mailto:'.config('salon.email')"
                    >{{ config('salon.email') }}</x-salon.contact-row>

                    {{-- The Facebook mark through `badge` rather than `icon`:
                         Heroicons has no brand glyphs, and this gets the same
                         circle as the three rows above it. --}}
                    <x-salon.contact-row
                        compact
                        label="Facebook"
                        :href="config('salon.facebook_url')"
                    >
                        <x-slot:badge>
                            <x-salon.facebook-icon class="h-5 w-5" />
                        </x-slot:badge>
                        {{ config('salon.facebook_label') }}
                    </x-salon.contact-row>
                </div>
            </div>
        </div>

        <div class="mt-10 flex flex-col items-center justify-between gap-3 border-t border-primary/10 pt-6 sm:flex-row">
            <p class="text-xs text-ink-muted">
                &copy; {{ now()->year }} {{ config('salon.name') }}. All rights reserved.
            </p>

            <a href="{{ route('login') }}" class="text-xs text-ink-muted/70 transition hover:text-primary">
            
            </a>
        </div>
    </div>
</footer>
