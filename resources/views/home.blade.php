@extends('layouts.customer')

@section('title', 'Home')

@section('content')
    {{-- ================= HERO ================= --}}
    <section class="home-hero">
        <div class="home-hero-copy">
            <svg class="home-hero-edge" viewBox="0 0 100 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                <path d="M90,0 C76,150 98,300 87,420 C76,540 97,700 88,820 C84,898 90,958 90,1000" />
            </svg>

            <svg class="home-botanical home-botanical-top" viewBox="0 0 240 240" fill="none" aria-hidden="true" focusable="false">
                <g stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" />
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" transform="rotate(72 120 96)" />
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" transform="rotate(144 120 96)" />
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" transform="rotate(216 120 96)" />
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" transform="rotate(288 120 96)" />
                    <circle cx="120" cy="96" r="10" />
                    <path d="M120 106 C118 140 116 176 110 214" />
                    <path d="M116 162 C92 156 76 140 72 118 C96 120 112 138 116 162 Z" />
                    <path d="M114 188 C140 182 156 166 160 144 C136 146 120 164 114 188 Z" />
                </g>
            </svg>

            <svg class="home-botanical home-botanical-bottom" viewBox="0 0 240 240" fill="none" aria-hidden="true" focusable="false">
                <g stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" />
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" transform="rotate(90 120 96)" />
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" transform="rotate(180 120 96)" />
                    <path d="M120 96 C106 86 102 64 114 48 C126 32 148 34 152 50 C156 66 138 88 120 96 Z" transform="rotate(270 120 96)" />
                    <circle cx="120" cy="96" r="9" />
                    <path d="M120 106 C118 142 116 178 112 220" />
                </g>
            </svg>

            <p class="home-kicker">WELCOME TO</p>

            <h1 class="home-hero-title">Balai ti Arjud</h1>

            <p class="home-script">Glow & Co. Beauty Lounge</p>

            <p class="home-description">
                Where beauty meets hospitality. Book your services online and let our therapists
                pamper you.
            </p>

            <div class="home-hero-buttons">
                <a href="{{ route('appointments.create') }}" class="btn-primary btn-lg">
                    <i class="fas fa-calendar-check" aria-hidden="true"></i>
                    Book an Appointment
                </a>
                <a href="{{ route('services.index') }}" class="btn-secondary btn-lg">
                    Explore Our Services
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                </a>
            </div>

            {{-- Trust row --}}
            <div class="home-features">
                @foreach ([
                    ['Professional Therapists', 'M9.75 11.25 11.25 13.5 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
                    ['Safe & Clean Environment', 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
                    ['Quality Products', 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z'],
                    ['Relax · Rejuvenate · Feel Beautiful', 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z'],
                ] as [$label, $path])
                    <div class="home-feature">
                        <span class="home-feature-icon">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg>
                        </span>
                        <span>{{ $label }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="home-hero-media">
            <img src="{{ asset('images/hero.jpg') }}" alt="Inside Balai ti Arjud beauty lounge"
                 fetchpriority="high">
            <div class="home-gold-arc" aria-hidden="true"></div>
        </div>
    </section>

    <button type="button" class="home-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" aria-label="Back to top">
        &#8679;
    </button>

    {{-- ================= PROMOS ================= --}}
    @if ($promos->isNotEmpty())
        <section class="home-section" aria-labelledby="home-promos-heading">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="home-head">
                    <p class="home-eyebrow">SPECIAL OFFERS</p>
                    <h2 class="home-title" id="home-promos-heading">Current Promos</h2>
                    <p class="home-sub">
                        Limited-time offers the salon is running right now.
                    </p>
                </div>

                {{-- The same `.home-promo-*` card the Promo page uses, so a promo
                     looks identical wherever it appears. Stacked rather than a
                     grid: the card is two-thirds text by design, and three of
                     them side by side would each be too narrow to read.

                     The card sizes to its own copy — the picture column is fixed
                     and the text column grows — so a short offer here is short
                     and a long one is given the room it needs. --}}
                <div class="home-promo-grid">
                    @foreach ($promos as $offer)
                        <article class="home-promo-card">
                            <div class="home-promo-media">
                                @if ($offer->hasImage())
                                    <img src="{{ $offer->image_url }}" alt="{{ $offer->title }}" loading="lazy">
                                @else
                                    <span class="home-promo-flourish" aria-hidden="true">&#10048;</span>
                                @endif
                                <x-ui.badge status="gold" label="Limited Offer" />
                            </div>

                            <div class="home-promo-body">
                                <h3>{{ $offer->title }}</h3>
                                <p>{{ $offer->description }}</p>

                                <div class="home-promo-foot">
                                    <p class="home-promo-validity">Valid {{ $offer->validity_label }}</p>
                                    <a href="{{ route('home') }}#services" class="btn-primary btn-sm shrink-0">
                                        Book Now
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="home-more">
                    <a href="{{ route('promos.index') }}" class="btn-secondary">
                        See all offers
                    </a>
                </div>
            </div>
        </section>
    @endif

    {{-- ================= MOST BOOKED ================= --}}
    <section id="services" class="home-section scroll-mt-24" aria-labelledby="home-most-booked-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="home-head">
                <p class="home-eyebrow">MOST BOOKED</p>
                <h2 class="home-title" id="home-most-booked-heading">Services our clients love</h2>

            </div>

            <div class="home-service-grid">
                @foreach ($mostBooked as $service)
                    <x-ui.service-card :service="$service" />
                @endforeach
            </div>

            <div class="home-more">
                <a href="{{ route('services.index') }}" class="btn-secondary">
                    Explore our services
                </a>
            </div>
        </div>
    </section>
@endsection
