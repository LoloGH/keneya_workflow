<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Type de service, administrable depuis /admin (v3.2.1, point 10).
 *
 * Trois types sont poses a l'installation et restent structurants pour
 * l'application — c'est leur `slug`, jamais leur libelle, que le code
 * interroge. Tout type cree ensuite par l'admin n'a aucun comportement code :
 * il ne fait que porter son nom et son indicateur de peage.
 */
class ServiceKind extends Model
{
    use HasFactory, RecordsActivity;

    public const SLUG_CLINIQUE = 'clinique';

    public const SLUG_PLATEAU_TECHNIQUE = 'plateau_technique';

    public const SLUG_CAISSE = 'caisse';

    /** Les types que l'application connait par leur slug et ne doit pas perdre. */
    public const BUILT_IN_SLUGS = [
        self::SLUG_CLINIQUE,
        self::SLUG_PLATEAU_TECHNIQUE,
        self::SLUG_CAISSE,
    ];

    protected $fillable = ['name', 'slug', 'requires_payment_gate'];

    protected function casts(): array
    {
        return ['requires_payment_gate' => 'boolean'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function isCaisse(): bool
    {
        return $this->slug === self::SLUG_CAISSE;
    }

    /**
     * Un type pose a l'installation : ni renommable en profondeur (son slug est
     * fige), ni supprimable, parce que du code s'appuie dessus.
     */
    public function isBuiltIn(): bool
    {
        return in_array($this->slug, self::BUILT_IN_SLUGS, true);
    }

    /**
     * Slug unique derive du nom. Les collisions sont suffixees plutot que
     * refusees : l'admin nomme, l'application se debrouille.
     */
    public static function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name, '_') ?: 'type';
        $slug = $base;
        $suffixe = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'_'.$suffixe++;
        }

        return $slug;
    }

    public static function auditLabel(): string
    {
        return 'Type de service';
    }
}
