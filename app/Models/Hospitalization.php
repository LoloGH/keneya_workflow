<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sejour d'un patient hospitalise (v3.2.1, point 11).
 */
class Hospitalization extends Model
{
    use HasFactory, RecordsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCHARGED = 'discharged';

    protected $fillable = [
        'patient_id',
        'service_id',
        'visit_id',
        'room_id',
        'admitted_by_doctor_id',
        'admitted_at',
        'discharged_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function admittedByDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'admitted_by_doctor_id');
    }

    public function careTasks(): HasMany
    {
        return $this->hasMany(CareTask::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function pendingCareTasks(): int
    {
        return $this->careTasks()->where('status', CareTask::STATUS_PENDING)->count();
    }

    public function statusLabel(): string
    {
        return $this->isActive() ? 'En cours' : 'Sortie';
    }

    public static function auditLabel(): string
    {
        return 'Hospitalisation';
    }
}
