<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une administration de soin, individuellement marquable (v3.2.1, point 11).
 *
 * La recurrence est resolue a la creation, pas a la lecture : « toutes les 8h
 * pendant 3 jours » produit neuf lignes. Chaque administration doit pouvoir
 * etre marquee faite ou manquee separement — une regle de recurrence calculee
 * a la volee ne le permettrait pas.
 */
class CareTask extends Model
{
    use HasFactory, RecordsActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_MISSED = 'missed';

    protected $fillable = [
        'hospitalization_id',
        'care_task_type_id',
        'instructions',
        'prescribed_by_doctor_id',
        'assigned_to_user_id',
        'scheduled_at',
        'status',
        'completed_by_user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function hospitalization(): BelongsTo
    {
        return $this->belongsTo(Hospitalization::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CareTaskType::class, 'care_task_type_id');
    }

    public function prescribedByDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'prescribed_by_doctor_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * En retard : l'heure est passee et le soin n'est toujours pas marque.
     *
     * Volontairement calcule a l'affichage plutot que bascule par une tache de
     * fond : cette version ne depend d'aucun scheduler.
     */
    public function isLate(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->scheduled_at->isPast();
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DONE => 'Fait',
            self::STATUS_MISSED => 'Manque',
            default => $this->isLate() ? 'En retard' : 'A faire',
        };
    }

    public static function auditLabel(): string
    {
        return 'Soin programme';
    }
}
