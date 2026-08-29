<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rattachement d'un compte a un type de personnel et a son service.
 *
 * Sur le modele de `doctors` / `receptionists` / `cashiers`, mais pour les
 * types generiques crees par l'admin : le service est choisi a la creation de
 * la personne, exactement comme `doctors.service_id`.
 */
class StaffMember extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['user_id', 'staff_type_id', 'service_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staffType(): BelongsTo
    {
        return $this->belongsTo(StaffType::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function name(): string
    {
        return (string) $this->user?->name;
    }

    public static function auditLabel(): string
    {
        return 'Membre du personnel';
    }
}
