<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A bookable service category, and the colour the admin calendar paints it.
 *
 * Categories used to be a free-text `services.category` string, which had
 * nowhere to keep a colour. They are rows now so the calendar legend and the
 * chips inside each date cell can both read the colour from the database.
 */
class ServiceCategory extends Model
{
    protected $fillable = [
        'name',
        'color',
        'sort_order',
        'is_active',
        'photo',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * The active categories, in the order the admin arranged them.
     *
     * This is what the service form's Category dropdown offers: a category an
     * admin has switched off stays on every service that already points at it,
     * but is no longer proposed for a new one.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function active()
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** Free-text search on the category name, for the admin list. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function ($q) use ($term) {
            $q->where('name', 'like', '%'.str_replace('%', '\%', $term).'%');
        });
    }

    /**
     * The one image that represents this category on the Services page.
     *
     * Resolved in three steps, and the order matters:
     *
     *   1. the photo an admin uploaded against the category itself. It wins
     *      outright: it was chosen for this category, so nothing below it can
     *      be a better answer, and it is the only step the salon controls
     *      without a developer;
     *   2. if any service in the category has uploaded a photo, that photo is
     *      next. It is the most accurate picture there is otherwise, and it
     *      means uploading photos service by service gradually replaces the
     *      configured fallbacks;
     *   3. otherwise the `category_images` entry for this name, looked up with
     *      the same forgiving normalisation the palette uses, so a config key
     *      written as "Spa Services" still matches a category stored as "Spa".
     *
     * Falls back to `default_category_image`, so the answer is always a URL and
     * the layout never has to cope with a category that cannot be pictured —
     * which is every category created before the photo field existed.
     */
    public function imageUrl(): string
    {
        return asset($this->imagePath());
    }

    /** The resolved file, relative to the public root. */
    public function imagePath(): string
    {
        if ($own = $this->uploadedPhoto()) {
            return 'storage/'.$own;
        }

        /*
         * The string "NULL" is filtered along with SQL NULL on purpose.
         *
         * `whereNotNull()` only rules out the absence of a value, and a service
         * row whose photo was cleared by a script, an import or a hand-edited
         * query is quite likely to hold the literal four characters "NULL"
         * rather than a real null — which is exactly what is in this project's
         * database today. Left unchecked that string is a perfectly good
         * non-null `photo_path`, so the category's representative image comes out
         * as `storage/NULL`: a 404 on every category section of the Services page.
         */
        $fromService = Service::query()
            ->where('category', $this->name)
            ->whereNotNull('photo_path')
            ->where('photo_path', '!=', '')
            ->whereRaw("photo_path <> 'NULL'")
            ->value('photo_path');

        if ($fromService) {
            return 'storage/'.$fromService;
        }

        return static::configuredImageFor($this->name)
            ?? (string) config('salon.default_category_image', 'images/hero.jpg');
    }

    /**
     * The absolute URL of the photo uploaded against this category, if any.
     *
     * `url()` and not `asset()`, for the reason set out on
     * `User::profile_photo_url`: `asset()` builds from `APP_URL`, so a deployed
     * site whose `APP_URL` still says `localhost` renders every category photo
     * as a request to the wrong origin.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        $path = $this->uploadedPhoto();

        return $path ? url('storage/'.$path) : null;
    }

    /**
     * This category's own uploaded photo path, or null.
     *
     * The same literal-"NULL" guard the service lookup in `imagePath()` applies,
     * for the same reason: a `photo` column cleared by a hand-edited query or an
     * import is just as likely to hold four characters rather than a real null,
     * and `storage/NULL` is a 404 rather than a picture.
     */
    private function uploadedPhoto(): ?string
    {
        $path = (string) ($this->photo ?? '');

        if ($path === '' || $path === 'NULL') {
            return null;
        }

        return $path;
    }

    /**
     * The configured image for a category name, or null when there is no entry.
     *
     * Tries every plausible spelling of the name against every configured key,
     * so a rename on either side does not silently fall back to the default.
     */
    public static function configuredImageFor(string $name): ?string
    {
        $configured = (array) config('salon.category_images', []);

        if ($configured === []) {
            return null;
        }

        foreach (static::paletteCandidates($name) as $candidate) {
            foreach ($configured as $key => $path) {
                if (static::paletteKey((string) $key) === $candidate) {
                    return (string) $path;
                }
            }
        }

        return null;
    }


    /**
     * The salon palette, keyed by a normalised category name.
     *
     * This is the single place the salon price list's colours live. Rows in
     * `service_categories` copy from it on creation, so a new category takes
     * the next unused colour rather than an arbitrary one — but the calendar
     * reads the stored column, never this array, so a colour an admin has
     * changed stays changed.
     *
     * @return array<string, string>
     */
    public static function palette(): array
    {
        return [
            'manicure-pedicure' => '#E11D48',
            'nail-art-extension' => '#EC4899',
            'spa' => '#8B5CF6',
            'nail-care-packages' => '#B45309',
            'brow-lash-extension' => '#4F46E5',
            'hair-waxing-removal' => '#F59E0B',
            'bleaching-services' => '#64748B',
            'glutathione-push-drip' => '#0D9488',
            'massage' => '#16A34A',
            'co-spa-packages' => '#0284C7',
            'hair-care-services' => '#EA580C',
            'threading' => '#65A30D',
            'kiddie' => '#D946EF',
        ];
    }

    /**
     * Reduce a category name to a palette key.
     *
     * The stored names are the salon's own wording and do not always match the
     * price list exactly — "Spa Services" for "Spa", "Bleaching" for
     * "Bleaching Services", "Glutathione Push OR Drip" for "Push/Drip". Folding
     * the connector words off first is what lets those line up.
     */
    public static function paletteKey(string $name): string
    {
        return static::paletteCandidates($name)[0];
    }

    /**
     * Every palette key a name could plausibly be filed under, best first.
     *
     * The stored names are the salon's own wording and do not always match the
     * price list: the list says "Bleaching Services" and the catalogue says
     * "Bleaching", the list says "Hair Care Services" and the catalogue says
     * "Hair Care". Trying each plausible suffix rather than one trimmed form
     * is what lets those line up instead of silently consuming a palette slot.
     *
     * @return array<int, string>
     */
    public static function paletteCandidates(string $name): array
    {
        $base = Str::of($name)
            ->lower()
            ->replace([' & ', ' or ', '/'], '-')
            ->replace(['-', '_'], '-')
            ->squish()
            ->replace(' ', '-')
            ->value();

        // The price list is written in full ("Spa Services", "Nail Care
        // Packages") while the catalogue often drops the noun ("Spa",
        // "Bleaching"). Try both directions, then both nouns, so either
        // wording lands on the colour meant for it.
        $trimmed = preg_replace('/-(services?|packages?)$/', '', $base) ?? $base;

        $candidates = [
            $trimmed,
            $base,
            $trimmed.'-services',
            $trimmed.'-packages',
            $base.'-services',
            $base.'-packages',
        ];

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * The palette colour for a name, or null when the name is not in the list.
     */
    public static function paletteColorFor(string $name): ?string
    {
        $palette = static::palette();

        foreach (static::paletteCandidates($name) as $key) {
            if (isset($palette[$key])) {
                return $palette[$key];
            }
        }

        return null;
    }

    /**
     * First palette colour not already used by another category.
     *
     * Compared case-insensitively so a stored `#e11d48` still counts as
     * `#E11D48` and is not handed out twice.
     *
     * @param  array<int, string>  $used
     */
    public static function nextColorFromPalette(array $used = []): string
    {
        $taken = array_map('strtolower', $used);

        foreach (static::palette() as $color) {
            if (! in_array(strtolower($color), $taken, true)) {
                return $color;
            }
        }

        // Palette exhausted: reuse the first colour rather than invent a hue
        // that would not belong to the salon's scheme.
        return (string) array_values(static::palette())[0];
    }

    /**
     * A readable label for a colour chip, derived once so the calendar view
     * does not have to work the alpha maths itself.
     */
    public function getTintAttribute(): string
    {
        return $this->withAlpha(0.16);
    }

    /** A deeper shade of the same colour, for the chip's text. */
    public function getShadeAttribute(): string
    {
        return $this->darken(0.22);
    }

    public function withAlpha(float $alpha): string
    {
        [$r, $g, $b] = $this->toRgb();

        return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha);
    }

    /**
     * Darken toward black, used for chip text so a light tint still carries
     * readable contrast. A stored colour that is not a hex triple falls back
     * to the brand maroon rather than rendering an invalid value.
     */
    public function darken(float $amount): string
    {
        [$r, $g, $b] = $this->toRgb();

        $mix = function (int $channel) use ($amount): int {
            return (int) round($channel * (1 - $amount));
        };

        return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
    }

    /** @return array{int, int, int} */
    private function toRgb(): array
    {
        $hex = ltrim((string) $this->color, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [122, 36, 27]; // brand maroon
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * The colour a service should be painted with on the calendar.
     *
     * Prefers the linked category row, falls back to a palette lookup on the
     * legacy `category` string, then to the brand maroon. A service whose
     * category has no row yet therefore still renders, just without a legend
     * entry of its own.
     */
    public static function colorForService(Service $service): string
    {
        if ($service->serviceCategory) {
            return $service->serviceCategory->color;
        }

        $key = static::paletteKey((string) $service->category);

        return static::palette()[$key] ?? '#7A241B';
    }
}
