<?php

namespace Tests\Feature;

use App\Livewire\Shared\ProfileCard;
use App\Livewire\Shared\VerticalTabNav;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Refonte de la navigation : barre de marque et onglets verticaux.
 *
 * Rien de fonctionnel ne change ici — ces tests verrouillent la presentation
 * pour que le remaniement ne fasse pas disparaitre une section en silence.
 */
class NavigationLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Arborescence minimale, avec un groupe, pour eprouver le composant sans
     * dependre des sections reelles d'une interface.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sections(): array
    {
        return [
            ['key' => 'premier', 'label' => 'Premier', 'view' => 'sections.admin.establishment'],
            ['key' => 'groupe', 'label' => 'Groupe', 'children' => [
                ['key' => 'enfant-a', 'label' => 'Enfant A', 'view' => 'sections.admin.services'],
                ['key' => 'enfant-b', 'label' => 'Enfant B', 'view' => 'sections.admin.audit'],
            ]],
        ];
    }

    // ------------------------------------------------------- Le composant

    public function test_la_premiere_section_est_active_par_defaut(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, ['sections' => $this->sections()])
            ->assertSet('active', 'premier');
    }

    public function test_choisir_une_section_change_le_panneau_affiche(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, ['sections' => $this->sections()])
            ->assertSee('Etablissement')          // panneau initial
            ->call('select', 'enfant-b')
            ->assertSet('active', 'enfant-b')
            // Les titres de carte passent depuis la refonte visuelle par le
            // composant <x-card>, donc par {{ }} : ils sont echappes comme les
            // libelles de la barre. C'est aussi ce qu'on veut — un titre venu
            // de la base ne doit pas pouvoir injecter de balisage.
            ->assertSee("Journal d'audit")
            ->assertDontSee("Nom de l'etablissement");
    }

    public function test_une_section_inconnue_est_ignoree(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, ['sections' => $this->sections()])
            ->call('select', 'section-inexistante')
            ->assertSet('active', 'premier');
    }

    public function test_un_groupe_ne_peut_pas_etre_selectionne_comme_panneau(): void
    {
        // Un groupe n'a pas de vue : cliquer dessus le deplie, sans rien afficher.
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, ['sections' => $this->sections()])
            ->call('select', 'groupe')
            ->assertSet('active', 'premier');
    }

    public function test_le_groupe_contenant_la_section_active_est_deplie(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, ['sections' => $this->sections(), 'active' => 'enfant-a'])
            ->assertSet('active', 'enfant-a')
            ->assertSet('expanded', ['groupe'])
            ->assertSee('Enfant A')
            // Deplie : le sous-menu est rendu visible, sans style de masquage.
            ->assertDontSee('id="grp-groupe" x-show="ouvert" style="display: none;"', false);
    }

    // ------------------------------------------------- Le fil d'Ariane

    /**
     * Arborescence avec des familles, comme celle de /admin : c'est la seule
     * interface dont le fil comporte un maillon intermediaire.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionsAvecFamilles(): array
    {
        return [
            ['key' => 'etablissement', 'label' => 'Etablissement', 'famille' => 'Etablissement',
                'view' => 'sections.admin.establishment'],
            ['key' => 'tarifs', 'label' => 'Tarifs', 'famille' => 'Gestion',
                'view' => 'sections.admin.billable-items'],
            ['key' => 'audit', 'label' => "Journal d'audit", 'view' => 'sections.admin.audit'],
        ];
    }

    public function test_le_premier_maillon_du_fil_ramene_a_la_premiere_section(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, ['sections' => $this->sections(), 'active' => 'enfant-b'])
            ->call('selectPremier')
            ->assertSet('active', 'premier');
    }

    public function test_un_maillon_de_famille_mene_a_la_premiere_section_de_cette_famille(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, [
                'sections' => $this->sectionsAvecFamilles(),
                'active' => 'audit',
            ])
            ->call('selectFamille', 'Gestion')
            ->assertSet('active', 'tarifs');
    }

    /**
     * Le fil est ecrit a la main dans chaque vue de section : une faute de
     * frappe doit laisser la page ou elle est, pas la casser.
     */
    public function test_un_maillon_qui_ne_correspond_a_rien_ne_bouge_pas(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, [
                'sections' => $this->sectionsAvecFamilles(),
                'active' => 'audit',
            ])
            ->call('selectFamille', 'Famille inexistante')
            ->assertSet('active', 'audit');
    }

    public function test_le_dernier_maillon_du_fil_n_est_pas_cliquable(): void
    {
        $rendu = Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, [
                'sections' => $this->sectionsAvecFamilles(),
                'active' => 'tarifs',
            ])
            ->html();

        // Le fil de cette section compte trois maillons ; seuls les deux
        // premiers menent quelque part.
        $this->assertSame(2, substr_count($rendu, 'class="fil__lien"'));
        $this->assertStringContainsString('aria-current="page"', $rendu);
    }

    /**
     * Garde-fou contre les boutons morts : chaque maillon intermediaire ecrit
     * dans une vue de /admin doit nommer une famille reellement declaree par
     * la page. Sans ce test, renommer une famille laisserait un fil qui a l'air
     * cliquable et ne fait rien.
     */
    public function test_les_maillons_intermediaires_de_admin_nomment_des_familles_reelles(): void
    {
        $page = file_get_contents(resource_path('views/pages/admin.blade.php'));
        preg_match_all("/'famille'\s*=>\s*'([^']+)'/", $page, $trouvees);
        $familles = array_unique($trouvees[1]);

        $this->assertNotEmpty($familles, 'La page /admin ne declare plus aucune famille.');

        foreach (glob(resource_path('views/sections/admin/*.blade.php')) as $vue) {
            if (! preg_match('/:fil="\[(.+?)\]"/s', file_get_contents($vue), $fil)) {
                continue;
            }

            // Nowdoc : le motif contient une apostrophe et des antislashs,
            // qu'aucune forme de guillemets ne laisserait passer intacts.
            $motif = <<<'REGEX'
                /'((?:[^'\\]|\\.)*)'/
                REGEX;

            preg_match_all($motif, $fil[1], $maillons);
            $etapes = $maillons[1];

            // Ni le premier maillon (le nom de l'espace) ni le dernier (la page
            // courante) ne designent une famille.
            foreach (array_slice($etapes, 1, -1) as $intermediaire) {
                $this->assertContains(
                    stripslashes($intermediaire),
                    $familles,
                    basename($vue).' : le maillon « '.$intermediaire.' » ne correspond a aucune famille.',
                );
            }
        }
    }

    /**
     * Le depliage d'un groupe se fait desormais entierement dans le navigateur.
     *
     * Le serveur ne decide plus que de l'etat de depart : il rend toujours les
     * sous-sections, et masque celles d'un groupe ferme par un style en ligne
     * qu'Alpine reprend ensuite a son compte. C'est ce contrat-la que ce test
     * verrouille — le comportement au clic, lui, n'existe plus cote PHP.
     */
    public function test_un_groupe_ferme_rend_ses_sous_sections_masquees(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(VerticalTabNav::class, ['sections' => $this->sections()])
            ->assertSet('expanded', [])
            // Le libelle est bien dans le document : c'est ce qui permet a
            // Alpine de l'afficher sans rien demander au serveur.
            ->assertSee('Enfant A')
            // Mais le groupe part ferme, et son etat initial est celui-la.
            ->assertSee('x-data="{ ouvert: false }"', false)
            ->assertSee('style="display: none;"', false);
    }

    public function test_le_contexte_est_transmis_a_la_section_active(): void
    {
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);

        // La vue de la file d'attente a besoin de $serviceId : sans le
        // contexte, son rendu echouerait.
        Livewire::actingAs($doctor->user)
            ->test(VerticalTabNav::class, [
                'sections' => [['key' => 'file', 'label' => "File d'attente", 'view' => 'sections.service.queue']],
                'context' => ['serviceId' => $service->getKey()],
            ])
            ->assertSee('Appeler le suivant');
    }

    // ------------------------------------------------ Aucune section perdue

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function interfaces(): array
    {
        return [
            'admin' => ['admin', '/admin', [
                'Etablissement', 'Services', 'Personnel', 'Patients',
                "Journal d'audit", 'Supprimer un dossier',
            ]],
            'accueil' => ['receptionist', '/reception', [
                'Enregistrement', 'Rendez-vous du jour',
                "Salle d'attente", 'Passages du jour', 'Mon planning',
            ]],
            'service' => ['doctor', '/service', [
                "File d'attente", 'Renvois', 'Fin de consultation',
                'Mes patients', 'Mes rendez-vous', 'Mon planning',
            ]],
        ];
    }

    /**
     * @param  array<int, string>  $attendues
     */
    #[DataProvider('interfaces')]
    public function test_chaque_interface_liste_ses_sections(string $role, string $url, array $attendues): void
    {
        $response = $this->actingAs($this->userForRole($role))->get($url);

        $response->assertOk();

        // Les libelles sont rendus via {{ }} : on les compare echappes, donc
        // sur le texte reellement affiche et non sur l'instantane Livewire.
        foreach ($attendues as $section) {
            $response->assertSee($section);
        }
    }

    // ------------------------------------------------------- La barre haute

    public function test_la_barre_porte_le_nom_de_l_etablissement_a_gauche(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/admin')
            ->assertOk()
            ->assertSee('app-header__hospital', escape: false)
            ->assertSee(hospital_name());
    }

    public function test_la_barre_n_affiche_plus_le_libelle_de_l_espace(): void
    {
        foreach (['admin' => '/admin', 'receptionist' => '/reception', 'doctor' => '/service'] as $role => $url) {
            $this->actingAs($this->userForRole($role))
                ->get($url)
                ->assertOk()
                ->assertDontSee('Espace administration')
                ->assertDontSee('Espace accueil')
                ->assertDontSee('Espace service');
        }
    }

    public function test_la_barre_affiche_le_role_seul(): void
    {
        $this->actingAs($this->makeAdmin())->get('/admin')
            ->assertSee(Roles::label(Roles::ADMIN));

        $this->actingAs($this->makeReceptionist())->get('/reception')
            ->assertSee(Roles::label(Roles::RECEPTIONIST));
    }

    /**
     * Pour un medecin, c'est son service qui est affiche plutot que son role.
     */
    public function test_la_barre_affiche_le_service_pour_un_medecin(): void
    {
        $service = Service::factory()->create(['name' => 'Radiologie']);

        $this->actingAs($this->makeDoctor($service)->user)
            ->get('/service')
            ->assertOk()
            ->assertSee('Radiologie');
    }

    /**
     * Depuis la v3.2.3, la deconnexion vit dans la carte de profil : c'est
     * l'avatar qui occupe le coin de la barre, et lui qui doit rester
     * identifiable sans ambiguite.
     */
    public function test_la_carte_de_profil_remplace_l_icone_de_deconnexion(): void
    {
        $response = $this->actingAs($this->makeAdmin())->get('/admin');

        $response->assertOk()
            ->assertSee('data-testid="profile-card"', escape: false)
            ->assertSee('title="Mon compte"', escape: false);

        // L'ancienne icone isolee a bien disparu de la barre.
        $this->assertStringNotContainsString('aria-label="Se deconnecter"', $response->getContent());
    }

    public function test_la_deconnexion_est_offerte_par_la_carte_de_profil(): void
    {
        // Icone seule dans la carte, comme les autres actions de la barre : le
        // libelle reste porte par aria-label et title, jamais par un texte
        // visible qui doublerait l'icone.
        $rendu = Livewire::actingAs($this->makeAdmin())
            ->test(ProfileCard::class)
            ->call('toggle')
            ->assertSee('data-testid="logout"', escape: false)
            ->assertSee('aria-label="Se deconnecter"', escape: false)
            ->html();

        $this->assertStringNotContainsString('>Se deconnecter<', $rendu);
    }

    public function test_la_deconnexion_fonctionne_toujours(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * Le nom du service etant desormais dans la barre, le selecteur ne
     * s'affiche que s'il sert reellement a quelque chose.
     */
    public function test_le_selecteur_de_service_n_apparait_qu_en_multi_service(): void
    {
        $premier = Service::factory()->create(['name' => 'Medecine Generale']);
        $doctor = $this->makeDoctor($premier);

        $this->actingAs($doctor->user)->get('/service')
            ->assertOk()
            ->assertDontSee('Service actif');

        // Rattache a un second service : le selecteur reapparait.
        Doctor::create([
            'user_id' => $doctor->user_id,
            'service_id' => Service::factory()->create(['name' => 'Urgences'])->getKey(),
        ]);

        $this->actingAs($doctor->user)->get('/service')
            ->assertOk()
            ->assertSee('Service actif');
    }

    private function userForRole(string $role): User
    {
        return match ($role) {
            'admin' => $this->makeAdmin(),
            'receptionist' => $this->makeReceptionist(),
            'doctor' => $this->makeDoctor(Service::factory()->create())->user,
        };
    }
}
