<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promo extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Where an uploaded promo picture is stored, on the `public` disk.
     *
     * Promotions get their own directory rather than sharing one with services
     * or categories: they are deleted on a different schedule and an admin
     * clearing out old offers should not have to know which other uploads
     * happen to be sitting in the same folder.
     */
    public const IMAGE_DIRECTORY = 'promos';

    protected $fillable = [
        'title',
        'description',
        'starts_at',
        'ends_at',
        'image_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
            'notified' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today());
    }

    public function scopeWithinValidity(Builder $query): Builder
    {
        return $query
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today());
    }

    public function isCurrentlyValid(): bool
    {
        return (bool) $this->is_active
            && today()->betweenIncluded($this->starts_at->copy()->startOfDay(), $this->ends_at->copy()->endOfDay());
    }

    /**
     * The uploaded picture's path on the public disk, or null if there is none.
     *
     * Guards the two ways a nullable string column comes to hold something that
     * is not a path: an empty string, and the four characters `NULL` left behind
     * by a hand-edited query or an import. Both would otherwise be turned into
     * `storage/NULL`, which is a 404 in the middle of the customer's promo card
     * rather than the flourish the card is supposed to fall back to.
     */
    public function imagePath(): ?string
    {
        $path = (string) ($this->image_path ?? '');

        if ($path === '' || $path === 'NULL') {
            return null;
        }

        return $path;
    }

    public function hasImage(): bool
    {
        return $this->imagePath() !== null;
    }

    /**
     * The absolute URL of the uploaded picture, or null when there is none.
     *
     * Null rather than a fallback image on purpose: the customer promo card and
     * the admin thumbnail each have their own placeholder, and a URL pointing at
     * a shared default here would leave neither of them any way to tell a promo
     * with a real picture from one without.
     */
    /**
     * The absolute URL of the uploaded picture, or null when there is none.
     *
     * Null rather than a fallback image on purpose: the customer promo card and
     * the admin thumbnail each have their own placeholder, and a URL pointing at
     * a shared default here would leave neither of them any way to tell a promo
     * with a real picture from one without.
     *
     * `url()` and not `asset()`, for the reason set out on
     * `User::profile_photo_url`: `asset()` builds from `APP_URL`, so on a
     * deployed host whose `APP_URL` is still `localhost` — the default in
     * `.env.example`, and the value most shared hosts start from — every picture
     * points at a different origin and renders broken. `url()` follows whatever
     * host the browser actually asked for.
     */
    public function getImageUrlAttribute(): ?string
    {
        $path = $this->imagePath();

        return $path ? url('storage/'.$path) : null;
    }

    public function getValidityLabelAttribute(): string
    {
        return $this->starts_at->format('M j, Y').' – '.$this->ends_at->format('M j, Y');
    }
}
