<?php

namespace App\Models;

use App\Enums\ItemTag;
use App\Support\PriceFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'service_category_id',
        'price',
        'base_price',
        'duration_minutes',
        'description',
        'photo_path',
        'is_active',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            // `price` is the advertised string ("100+", "249/499") and is shown
            // verbatim; `base_price` is the figure the booking totals multiply.
            'price' => 'string',
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * Keep `service_category_id` in step with the free-text `category`, and
     * `base_price` in step with the advertised `price`.
     *
     * On the category: the service form still accepts a typed category, so a
     * name can arrive with no `service_categories` row behind it. The admin
     * calendar legend reads that table, and a category with no row would
     * silently be missing from it and from the chips. Creating the row on save —
     * taking the next unused palette colour — is what keeps the two in step
     * whichever code path wrote the service.
     *
     * On the price: the form has one price field, so there is no second value
     * to keep in step and nothing to fall out of date. The first figure is the
     * amount the customer is told the service starts at — "100+" starts at 100
     * — so this is the price being quoted, not a guess at one. A price with no
     * digits in it leaves `base_price` null, and the booking form then refuses
     * the service rather than charging zero.
     */
    protected static function booted(): void
    {
        static::saving(function (self $service): void {
            if ($service->isDirty('price') || $service->isDirty('base_price')) {
                $service->base_price = PriceFormatter::firstFigure($service->price);
            }
        });

        static::saving(function (self $service) {
            $name = trim((string) $service->category);

            if ($name === '') {
                return;
            }

            // `firstOrCreate` takes an array, not a closure, so the defaults are
            // computed before the lookup. That costs one extra query on the
            // create path and none on the common one, where the row exists.
            $category = ServiceCategory::query()->firstOrCreate(
                ['name' => $name],
                [
                    'color' => ServiceCategory::nextColorFromPalette(
                        ServiceCategory::query()->pluck('color')->all()
                    ),
                    'sort_order' => (int) ServiceCategory::max('sort_order') + 1,
                ],
            );

            $service->service_category_id = $category->id;
        });
    }

    /**
     * The booking lines this service appears on.
     *
     * Lines rather than appointments, because one appointment can carry several
     * services and a customer who books a manicure and a pedicure together has
     * booked both.
     */
    public function appointmentLines(): HasMany
    {
        return $this->hasMany(AppointmentService::class, 'service_id');
    }

    /**
     * The busiest services, for the landing page.
     *
     * Counts booking lines rather than appointments so a multi-service booking
     * credits every service on it, and drops cancelled appointments: a booking
     * the salon released is not evidence that anybody wanted the service.
     *
     * Soft-deleted appointments are excluded by the `SoftDeletes` global scope on
     * `Appointment`, so a removed booking cannot keep a service in the list.
     *
     * `is_active` is required — a service the salon has switched off must not be
     * advertised on the front page, however many times it was booked before.
     */
    public function scopeMostBooked(Builder $query, int $limit = 6): Builder
    {
        return $query
            ->where('is_active', true)
            ->withCount([
                'appointmentLines as bookings_count' => fn (Builder $lines) => $lines
                    ->whereHas('appointment', fn (Builder $appointment) => $appointment
                        ->where('status', '!=', 'cancelled')),
            ])
            ->orderByDesc('bookings_count')
            ->orderBy('name')
            ->limit($limit);
    }

    /**
     * The busiest services, falling back to the shop's own choices when nothing
     * has been booked yet.
     *
     * A freshly seeded or freshly deployed site has no bookings, and a "Most
     * Booked" heading over an empty grid reads as broken rather than new. So the
     * fallback is the featured services, then anything available, which is what
     * the catalogue page shows anyway.
     */
    public static function mostBookedOrFeatured(int $limit = 6)
    {
        $booked = static::query()->mostBooked($limit)->get();

        if ($booked->isNotEmpty()) {
            return $booked;
        }

        return static::query()
            ->where('is_active', true)
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function variants()
    {
        // Ordered by base_price, not price: with price as a string column this
        // would be a lexical sort, putting "100+" ahead of "50+".
        return $this->hasMany(ServiceVariant::class)->orderBy('base_price');
    }

    /**
     * The category row that carries this service's calendar colour.
     *
     * Optional: a service can still exist with only the legacy
     * `category` string, so callers must null-check. Use
     * `categoryColor()` when a colour is needed rather than this relation.
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /** The colour the admin calendar paints this service's chip. */
    public function categoryColor(): string
    {
        return ServiceCategory::colorForService($this);
    }

    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'service_inventory')
            ->withPivot('quantity_per_service')
            ->withTimestamps();
    }

    public function appointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_service')
            ->withPivot(['service_variant_id', 'service_name', 'price', 'duration_minutes', 'quantity'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        return $query->when($category, fn (Builder $q) => $q->where('category', $category));
    }

    /** Free-text search across name + description. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $q) use ($term) {
            $like = '%'.str_replace('%', '\%', $term).'%';

            $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like));
        });
    }

    /** @return array<int, string> */
    public static function categories(): array
    {
        return static::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    /**
     * `url()` and not `asset()`, for the reason set out on
     * `User::profile_photo_url`: `asset()` builds from `APP_URL`, so a deployed
     * site whose `APP_URL` still says `localhost` renders every service photo as
     * a request to the wrong origin.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? url('storage/'.$this->photo_path) : null;
    }

    public function getDurationLabelAttribute(): string
    {
        $minutes = (int) $this->duration_minutes;

        /*
         * Empty, not "0 min".
         *
         * Most services in this salon were listed on a price sheet that gives no
         * duration, so the column is null for them. Casting null with `(int)`
         * makes 0, and "0 min" is a claim — that a treatment takes no time at all
         * — rather than an admission that the figure is missing. It also reads as
         * a bug to a customer, on the row they are about to book.
         *
         * Callers guard on `duration_minutes` or on this being empty, so nothing
         * renders a stray separator in place of a duration.
         */
        if ($minutes <= 0) {
            return '';
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours} hr" : "{$hours} hr {$rest} min";
    }

    /** True when every linked item is sold out. */
    public function isUnavailable(): bool
    {
        $items = $this->inventoryItems;

        return $items->isNotEmpty()
            && $items->every(fn (InventoryItem $item) => $item->status_tag === ItemTag::SoldOut || $item->quantity <= 0);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
