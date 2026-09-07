<?php

namespace App\Models;

use App\Services\OnDutyRoster;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'staff_type_id',
        'name',
        'email',
        'mobile',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Rattachement principal du medecin. Un medecin rattache a plusieurs
     * services possede plusieurs lignes `doctors` : voir doctors() et
     * doctorFor().
     */
    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    /**
     * Tous les rattachements de ce medecin (cas rare du multi-service).
     */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    /**
     * Le numero auquel joindre cette personne par SMS (v3.2.9, point 1).
     *
     * `users.mobile` d'abord : c'est le numero de la personne, quel que soit
     * son role. A defaut, celui de sa fiche medecin — les numeros saisis avant
     * l'existence de cette colonne continuent ainsi de servir, sans ressaisie.
     * Nul si l'on ne sait pas la joindre : mieux vaut zero destinataire qu'un
     * envoi dans le vide.
     */
    public function smsNumber(): ?string
    {
        if (filled($this->mobile)) {
            return (string) $this->mobile;
        }

        $fiche = $this->doctors()->whereNotNull('phone')->first();

        return filled($fiche?->phone) ? (string) $fiche->phone : null;
    }

    /**
     * Le rattachement de ce medecin au service donne, ou null s'il n'y est pas
     * rattache. C'est le seul point d'entree utilise par l'interface /service :
     * un medecin ne peut agir que dans un service qui lui appartient.
     */
    public function doctorFor(int $serviceId): ?Doctor
    {
        return $this->doctors()->where('service_id', $serviceId)->first();
    }

    public function receptionist(): HasOne
    {
        return $this->hasOne(Receptionist::class);
    }

    public function cashier(): HasOne
    {
        return $this->hasOne(Cashier::class);
    }

    /**
     * Le rattachement de ce compte a un type de personnel generique
     * (v3.2.1, point 10). Nul pour les quatre roles fixes, qui ont leurs
     * propres tables.
     */
    public function staffMember(): HasOne
    {
        return $this->hasOne(StaffMember::class);
    }

    /**
     * Le type de personnel qui decrit ce compte (v3.2.2).
     *
     * Trois sources, dans cet ordre :
     *  1. le rattachement explicite (`users.staff_type_id`), choisi par l'admin
     *     quand plusieurs types partagent le meme role ;
     *  2. le type du personnel generique, porte par `staff_members` ;
     *  3. a defaut, le type d'origine du role — de sorte qu'un compte cree
     *     avant le v3.2.2, ou par un seeder, ait toujours un type.
     */
    public function staffType(): ?StaffType
    {
        if ($this->staff_type_id) {
            return $this->explicitStaffType()->first();
        }

        if ($type = $this->staffMember?->staffType) {
            return $type;
        }

        return ($role = $this->scopedRole())
            ? StaffType::where('matched_role', $role)->orderBy('id')->first()
            : null;
    }

    public function explicitStaffType(): BelongsTo
    {
        return $this->belongsTo(StaffType::class, 'staff_type_id');
    }

    /**
     * Raccourci de lecture des capacites, partage par les quatre interfaces
     * fixes et l'interface generique : une seule mecanique, pas deux.
     */
    public function hasCapability(string $capability): bool
    {
        return (bool) $this->staffType()?->can($capability);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * La cloche de ce compte (v3.2.3, point 2).
     *
     * Propriete du compte et non du dossier patient : elle part avec lui
     * quand il est supprime.
     */
    public function staffNotifications(): HasMany
    {
        return $this->hasMany(StaffNotification::class);
    }

    /**
     * Ce compte est-il de garde sur ce service, maintenant ?
     *
     * S'appuie sur le planning deja en place plutot que sur une assignation
     * dediee : une rotation d'equipe se lit la, et nulle part ailleurs.
     */
    public function isOnDutyFor(int $serviceId, ?Carbon $moment = null): bool
    {
        // Une seule regle de garde dans l'application : elle vit dans
        // OnDutyRoster, qui repond aussi a la question inverse — qui est de
        // garde sur ce service ?
        return app(OnDutyRoster::class)->isOnDuty($this, $serviceId, $moment);
    }

    /**
     * Le role applicatif de l'utilisateur, parmi les trois roles cloisonnes.
     * Un utilisateur n'est cense en porter qu'un seul ; le premier reconnu fait foi.
     */
    /**
     * Tout compte reellement rattache a l'etablissement, quelle que soit sa
     * table de rattachement (v3.2.4).
     *
     * Les plannings interrogeaient les roles Spatie : un type de personnel sans
     * role — un infirmier, un brancardier — n'en porte aucun, il etait donc
     * introuvable dans les menus, et personne ne pouvait lui poser de creneau.
     * Or c'est le planning qui decide de sa garde, donc de tout ce qu'il voit.
     *
     * Le rattachement est le bon critere : il existe pour les quatre chemins,
     * la ou le role n'existe que pour trois.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStaff(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('doctors')
            ->orWhereHas('receptionist')
            ->orWhereHas('cashier')
            ->orWhereHas('staffMember'));
    }

    public function scopedRole(): ?string
    {
        $names = $this->getRoleNames();

        foreach (Roles::all() as $role) {
            if ($names->contains($role)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Route nommee de l'unique interface autorisee pour cet utilisateur.
     *
     * Nulle pour un type de personnel generique, dont l'interface se designe
     * par une URL portant son slug : voir homeUrl().
     */
    public function homeRoute(): ?string
    {
        return Roles::homeRoute($this->scopedRole());
    }

    /**
     * L'URL de l'unique interface autorisee, roles fixes et types generiques
     * confondus. C'est le point d'entree unique de toute redirection.
     */
    public function homeUrl(): ?string
    {
        if ($route = $this->homeRoute()) {
            return route($route);
        }

        return $this->staffType()?->homeUrl();
    }

    /**
     * Libelle affiche dans la barre de marque : le role fixe, ou le nom du
     * type de personnel.
     */
    public function roleLabel(): string
    {
        return Roles::label($this->scopedRole()) ?: (string) $this->staffType()?->name;
    }
}
