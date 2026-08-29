<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory, RecordsActivity;

    /** Nom des deux caisses, referencees par le routage sous condition de paiement. */
    public const CAISSE_TICKET = 'Caisse Ticket';

    public const CAISSE_SERVICES = 'Caisse Services';

    protected $fillable = ['name', 'service_kind_id'];

    public function serviceKind(): BelongsTo
    {
        return $this->belongsTo(ServiceKind::class);
    }

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    public function incomingReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'to_service_id');
    }

    public function outgoingReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'from_service_id');
    }

    /**
     * Les services vers lesquels on oriente reellement un patient.
     *
     * La caisse en est exclue : elle est une etape de routage decidee par
     * RouteThroughCaisse, jamais une destination qu'on choisit dans un
     * formulaire. C'est aussi ce qui empeche d'affecter un medecin a une
     * caisse — les medecins n'encaissent jamais.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCareServices(Builder $query): void
    {
        $query->whereHas(
            'serviceKind',
            fn (Builder $kind) => $kind->where('slug', '!=', ServiceKind::SLUG_CAISSE),
        );
    }

    /** @param  Builder<self>  $query */
    public function scopeOfKindSlug(Builder $query, string $slug): void
    {
        $query->whereHas('serviceKind', fn (Builder $kind) => $kind->where('slug', $slug));
    }

    public function isCaisse(): bool
    {
        return $this->serviceKind?->slug === ServiceKind::SLUG_CAISSE;
    }

    /**
     * Ce service se regle-t-il avant d'etre realise ?
     *
     * Ce n'est plus « est-ce un plateau technique » : l'admin coche
     * `requires_payment_gate` sur le type, et c'est cet indicateur seul qui
     * decide du passage par la Caisse Services sur un renvoi.
     */
    public function requiresPaymentGate(): bool
    {
        return (bool) $this->serviceKind?->requires_payment_gate;
    }

    /**
     * Un service de soins au sens ou l'on y rend visite a quelqu'un.
     *
     * Volontairement adosse au type d'origine « clinique » et non a une regle
     * deduite : un type cree par l'admin ne se voit pas attribuer d'office une
     * obligation de patient visite qu'il n'a jamais demandee.
     */
    public function isClinique(): bool
    {
        return $this->serviceKind?->slug === ServiceKind::SLUG_CLINIQUE;
    }

    /** La caisse ou l'on regle son ticket de consultation. */
    public static function caisseTicket(): ?self
    {
        return static::caisseNamed(self::CAISSE_TICKET);
    }

    /** La caisse ou l'on regle un acte avant qu'il soit realise. */
    public static function caisseServices(): ?self
    {
        return static::caisseNamed(self::CAISSE_SERVICES);
    }

    private static function caisseNamed(string $nom): ?self
    {
        return static::ofKindSlug(ServiceKind::SLUG_CAISSE)->where('name', $nom)->first();
    }

    public function kindLabel(): string
    {
        return $this->serviceKind?->name ?? '—';
    }

    public static function auditLabel(): string
    {
        return 'Service';
    }
}
