<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\ChangedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentStatusHistory extends Model
{
    use HasFactory;

    /** Explicit: the table is `appointment_status_history` (singular). */
    protected $table = 'appointment_status_history';

    protected $fillable = [
        'appointment_id',
        'from_status',
        'to_status',
        'changed_by',
        'changed_by_id',
        'changed_by_name',
        'note',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => AppointmentStatus::class,
            'to_status' => AppointmentStatus::class,
            'changed_by' => ChangedBy::class,
            'archived_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Label for whoever made the change.
     *
     * `changed_by_id` is ambiguous (an admin id or a customer id depending on
     * `changed_by`), so the denormalised `changed_by_name` is the source of
     * truth and the enum is the fallback. Deliberately not a relation.
     */
    public function actorLabel(): string
    {
        if (filled($this->changed_by_name)) {
            return $this->changed_by_name;
        }

        return $this->changed_by?->label() ?? 'System';
    }

    public function getArrowAttribute(): string
    {
        $from = $this->from_status?->label() ?? 'Created';

        return "{$from} → {$this->to_status->label()}";
    }
}
