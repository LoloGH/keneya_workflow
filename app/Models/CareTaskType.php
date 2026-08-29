<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogue des types de soins (v3.2.1, point 11).
 *
 * Administrable comme les types de service et de personnel : la liste
 * « serum, injection, pansement… » s'enrichira, elle n'a pas sa place figee
 * dans le code.
 */
class CareTaskType extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['name'];

    public function tasks(): HasMany
    {
        return $this->hasMany(CareTask::class);
    }

    public static function auditLabel(): string
    {
        return 'Type de soin';
    }
}
