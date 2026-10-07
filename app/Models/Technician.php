<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Someone who serves a customer.
 *
 * Deliberately not an `Admin`: an admin holds the panel login, a technician is
 * on the price list of people a customer can ask for. Nothing here can sign in.
 */
class Technician extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'photo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * The active technicians, in the order the admin wants them offered.
     *
     * This is what the booking form's picker reads: a technician who is off
     * today is switched off rather than deleted, and stops being offered
     * without disturbing the appointments that already name them.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function active()
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $q) use ($term) {
            $q->where('name', 'like', '%'.str_replace('%', '\%', $term).'%');
        });
    }

    /**
     * `url()` and not `asset()`, for the reason set out on
     * `User::profile_photo_url`: `asset()` builds from `APP_URL`, so a deployed
     * site whose `APP_URL` still says `localhost` renders every technician photo
     * as a request to the wrong origin.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? url('storage/'.$this->photo_path) : null;
    }

    /**
     * Up to two initials, for the avatar stand-in when no photo was uploaded.
     */
    public function getInitialsAttribute(): string
    {
        $letters = collect(explode(' ', (string) $this->name))
            ->filter()
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->take(2);

        return $letters->implode('') ?: '?';
    }
}
