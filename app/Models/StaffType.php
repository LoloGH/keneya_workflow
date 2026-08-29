<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Type de personnel administrable (v3.2.1, point 10).
 *
 * Un type adosse a un role (`matched_role`) reutilise une des quatre interfaces
 * existantes. Un type sans role recoit l'interface generique /staff/{slug},
 * composee des seules briques cochees dans `capabilities`.
 *
 * Ce n'est pas un generateur de code : les capacites listees ici sont celles
 * que l'application sait deja faire. Un metier qui demande une logique
 * entierement nouvelle demande toujours du developpement.
 */
class StaffType extends Model
{
    use HasFactory, RecordsActivity;

    /** File d'attente du service de rattachement, avec « Appeler le suivant ». */
    public const CAP_QUEUE = 'has_queue';

    public const CAP_SEND_REFERRAL = 'can_send_referral';

    public const CAP_RECEIVE_REFERRAL = 'can_receive_referral';

    public const CAP_VIEW_DOSSIER = 'can_view_dossier';

    public const CAP_ACCEPT_PAYMENT = 'can_accept_payment';

    public const CAP_CLOSE_VISIT = 'can_close_visit';

    public const CAP_PRINT_TICKET = 'can_print_ticket';

    /** Soins programmes des patients hospitalises (v3.2.1, point 11). */
    public const CAP_CARE_TASKS = 'has_care_tasks';

    /**
     * Libelle de chaque capacite et section qu'elle fait apparaitre.
     *
     * C'est cette table qui alimente l'apercu montre a l'admin au moment de
     * creer un type : il doit comprendre ce qu'il vient de creer avant qu'un
     * membre du personnel ne s'y connecte.
     *
     * @var array<string, array{label: string, section: string}>
     */
    public const CAPABILITIES = [
        self::CAP_QUEUE => [
            'label' => 'File d\'attente du service',
            'section' => 'File d\'attente — appeler le patient suivant',
        ],
        self::CAP_SEND_REFERRAL => [
            'label' => 'Envoyer un patient vers un autre service',
            'section' => 'Envoyer vers un service',
        ],
        self::CAP_RECEIVE_REFERRAL => [
            'label' => 'Recevoir des renvois et saisir un resultat',
            'section' => 'Renvois recus',
        ],
        self::CAP_VIEW_DOSSIER => [
            'label' => 'Consulter le dossier d\'un patient',
            'section' => 'Dossier patient',
        ],
        self::CAP_ACCEPT_PAYMENT => [
            'label' => 'Encaisser un paiement',
            'section' => 'Encaissement',
        ],
        self::CAP_CLOSE_VISIT => [
            'label' => 'Cloturer un dossier',
            'section' => 'Cloture de dossier (dans la file)',
        ],
        self::CAP_PRINT_TICKET => [
            'label' => 'Imprimer un ticket',
            'section' => 'Impression du ticket (dans la file)',
        ],
        self::CAP_CARE_TASKS => [
            'label' => 'Executer les soins programmes',
            'section' => 'Soins programmes des patients hospitalises',
        ],
    ];

    protected $fillable = ['name', 'matched_role', 'slug', 'capabilities'];

    protected function casts(): array
    {
        return ['capabilities' => 'array'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(StaffMember::class);
    }

    /** Ce type reutilise-t-il une des quatre interfaces deja construites ? */
    public function usesFixedRole(): bool
    {
        return filled($this->matched_role);
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    /**
     * Les sections qui apparaitront reellement sur /staff/{slug}, dans l'ordre.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function sections(): array
    {
        $sections = [];

        foreach (self::CAPABILITIES as $capability => $meta) {
            if ($this->can($capability)) {
                $sections[] = ['key' => $capability, 'label' => $meta['section']];
            }
        }

        return $sections;
    }

    /**
     * L'interface de ce type : une route nommee pour un role fixe, l'URL
     * generique sinon.
     */
    public function homeUrl(): ?string
    {
        if ($this->usesFixedRole()) {
            $route = Roles::homeRoute($this->matched_role);

            return $route ? route($route) : null;
        }

        return $this->slug ? route('staff.home', $this->slug) : null;
    }

    public static function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'personnel';
        $slug = $base;
        $suffixe = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$suffixe++;
        }

        return $slug;
    }

    public static function auditLabel(): string
    {
        return 'Type de personnel';
    }
}
