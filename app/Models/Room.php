<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Salle d'hospitalisation (v3.2.1, point 11).
 *
 * L'occupation n'est jamais stockee : elle se compte a la volee sur les
 * hospitalisations actives. Un compteur en base finirait par mentir.
 */
class Room extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['name', 'service_id', 'capacity'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function hospitalizations(): HasMany
    {
        return $this->hasMany(Hospitalization::class);
    }

    public function occupancy(): int
    {
        return $this->hospitalizations()->where('status', Hospitalization::STATUS_ACTIVE)->count();
    }

    public function isFull(): bool
    {
        return $this->occupancy() >= $this->capacity;
    }

    /** « 3/4 lits occupes ». */
    public function occupancyLabel(): string
    {
        return sprintf('%d/%d lits occupes', $this->occupancy(), $this->capacity);
    }

    public static function auditLabel(): string
    {
        return 'Salle';
    }
}
