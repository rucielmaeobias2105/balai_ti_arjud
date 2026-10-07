@extends('layouts.customer')

@section('title', 'About Us')

@section('content')
    {{-- Two sections, following the MCA Café About layout but in Balai ti
         Arjud's own language: no hero banner, no page-header, straight into
         the story. The section headings use the same serif display face as the
         rest of the site. --}}
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

        {{-- Our Story: text beside the salon photo, stacking on mobile. --}}
        <section class="grid items-center gap-10 lg:grid-cols-2 lg:gap-14">
            <div>
                <h2 class="font-display text-2xl font-bold tracking-tight text-primary sm:text-3xl">
                    Our Story
                </h2>

                <div class="mt-5 space-y-4 text-sm leading-relaxed text-ink-muted">
                    <p>
                        What started as a small neighbourhood salon has grown into a full-service beauty
                        lounge offering hair, nails, lashes, facials, massage and spa treatments all
                        under one roof.
                    </p>
                    <p>
                        We believe a beauty appointment should feel like a break from a busy week, not
                        another task to get through. That means unhurried consultations, therapists who
                        listen, and products we genuinely stand behind.
                    </p>
                    <p>
                        Booking online is our way of making that easier: reserve your slot, tell us about
                        any sensitivities, and arrive to a chair that's already prepared for you.
                    </p>
                </div>
            </div>

            <div>
                <img src="{{ asset('images/hero.jpg') }}" alt="Inside Balai ti Arjud beauty lounge"
                     class="h-full max-h-[26rem] w-full rounded-card object-cover shadow-panel">
            </div>
        </section>

        {{-- Find Us: centred heading, then one wide card split between the
             address details and an embedded map. Hours are intentionally absent
             — they live on the contact page.

             The three facts under the trading name are the same ones the contact
             page and the footer show, so all three read `config/salon.php`
             through `x-salon.contact-row`. They used to be written out here
             and drifted: the footer's phone link dialled a number none of these
             strings mentioned. --}}
        <section class="mt-16 sm:mt-20">
            <div class="flex items-center justify-center gap-2">
                <span class="text-gold-dark">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                </span>
                <h2 class="font-display text-2xl font-bold tracking-tight text-primary sm:text-3xl">Find Us</h2>
            </div>

            <div class="mt-7 grid gap-6 lg:grid-cols-2">
                {{-- The embed URL lives in config/salon.php so the address can
                     be repointed without touching markup. --}}
                @php $mapUrl = config('salon.map_embed_url'); @endphp

                {{-- Details --}}
                <div class="bta-card p-6 sm:p-8">
                    <div class="flex items-center gap-2.5">
                        <span class="text-gold-dark">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 21a1.5 1.5 0 0 1-1.5-1.5V18a1.5 1.5 0 0 1 1.5-1.5h2.25a1.5 1.5 0 0 1 1.5 1.5v1.5A1.5 1.5 0 0 1 13.5 21ZM3.75 18.75v-6A2.25 2.25 0 0 1 6 10.5h1.5A2.25 2.25 0 0 1 9.75 12.75v-3A2.25 2.25 0 0 1 12 7.5h1.5a2.25 2.25 0 0 1 2.25 2.25v3a2.25 2.25 0 0 1 2.25 2.25v1.5a2.25 2.25 0 0 1-2.25 2.25H3.75Z"/></svg>
                        </span>
                        <h3 class="font-display text-lg font-semibold text-primary">
                            {{ config('salon.name') }}
                        </h3>
                    </div>

                    <div class="mt-5 space-y-4">
                        <x-salon.contact-row
                            icon="heroicon-o-map-pin"
                            label="Location"
                        >{{ config('salon.address') }}</x-salon.contact-row>

                        <x-salon.contact-row
                            icon="heroicon-o-phone"
                            label="Contact Number"
                            :href="'tel:'.config('salon.phone_e164')"
                        >{{ config('salon.phone_display') }}</x-salon.contact-row>

                        <x-salon.contact-row
                            icon="heroicon-o-envelope"
                            label="Email"
                            :href="'mailto:'.config('salon.email')"
                        >{{ config('salon.email') }}</x-salon.contact-row>

                        {{-- The link points at the profile; the words are the
                         page's name. It used to print the URL as its own
                         text, which showed a visitor a query-string
                         identifier where they expected a name.

                         The mark goes through `badge` rather than `icon`
                         because Heroicons has no brand glyphs, so this row
                         takes the same circular badge the three above it do —
                         which is the whole of "uniform" here. It was the one
                         row in the card with no circle.

                         `target="_blank"` and `rel="noopener noreferrer"`
                         come from `x-salon.contact-row`, which adds both to
                         anything that is not a `tel:` or `mailto:` link. --}}
                        <x-salon.contact-row
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

                {{-- Street View facing the shopfront. Sizing, corners and
                     layout are unchanged; only the src moved to the config
                     value. Nothing else may target this frame, so it is the
                     sole map on the page. --}}
                <div class="bta-card overflow-hidden p-0">
                    <div class="aspect-[4/3] w-full lg:aspect-auto lg:h-full lg:min-h-[16rem]">
                        <iframe
                            title="Street view of Balai ti Arjud, Glow & Co. Beauty Lounge"
                            src="{{ $mapUrl }}"
                            class="h-full w-full border-0"
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            allowfullscreen></iframe>
                    </div>
                </div>
            </div>

        </section>
    </div>
@endsection
