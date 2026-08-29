<?php

namespace Tests\Feature;

use App\Actions\ConfirmCaissePayment;
use App\Actions\SendReferral;
use App\Livewire\Admin\StaffTypeManager;
use App\Livewire\Staff\StaffIncomingReferrals;
use App\Livewire\Staff\StaffPayments;
use App\Livewire\Staff\StaffQueue;
use App\Models\Referral;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\StaffType;
use App\Models\User;
use App\Models\Visit;
use App\Services\SmsGateway;
use App\Support\Roles;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Types de personnel et interface generique (v3.2.1, point 10).
 *
 * Le comportement est teste **par combinaison de capacites**, pas type par
 * type : la surface fonctionnelle grandit avec chaque case cochee, et une
 * verification manuelle par metier la laisserait vite depasser ce qui est
 * couvert.
 */
class StaffTypeInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsGateway::class)->shouldReceive('send')->andReturnTrue();
    }

    /**
     * Un type generique et une personne qui le porte.
     *
     * @param  array<int, string>  $capabilities
     * @return array{0: StaffType, 1: User, 2: Service}
     */
    private function makeStaff(array $capabilities, ?Service $service = null, string $name = 'Infirmier'): array
    {
        $service ??= Service::factory()->create(['name' => 'Medecine Generale']);

        $type = StaffType::create([
            'name' => $name,
            'matched_role' => null,
            'slug' => StaffType::makeSlug($name),
            'capabilities' => $capabilities,
        ]);

        $user = User::factory()->create();

        StaffMember::create([
            'user_id' => $user->getKey(),
            'staff_type_id' => $type->getKey(),
            'service_id' => $service->getKey(),
        ]);

        return [$type, $user, $service];
    }

    // --------------------------------------------------------- Cloisonnement

    public function test_le_personnel_generique_atteint_sa_propre_interface(): void
    {
        [$type, $user] = $this->makeStaff([StaffType::CAP_QUEUE]);

        $this->actingAs($user)->get('/staff/'.$type->slug)->assertOk();
    }

    public function test_il_est_redirige_depuis_les_quatre_interfaces_fixes(): void
    {
        [$type, $user] = $this->makeStaff([StaffType::CAP_QUEUE]);

        foreach (['/admin', '/reception', '/service', '/caisse'] as $interdite) {
            $this->actingAs($user)
                ->get($interdite)
                ->assertRedirect(route('staff.home', $type->slug));
        }
    }

    public function test_il_est_redirige_depuis_l_interface_d_un_autre_type(): void
    {
        [$type, $user] = $this->makeStaff([StaffType::CAP_QUEUE], null, 'Infirmier');
        [$autre] = $this->makeStaff([StaffType::CAP_QUEUE], null, 'Brancardier');

        $this->actingAs($user)
            ->get('/staff/'.$autre->slug)
            ->assertRedirect(route('staff.home', $type->slug));
    }

    public function test_un_slug_inconnu_redirige_sans_reveler_ce_qui_existe(): void
    {
        [$type, $user] = $this->makeStaff([StaffType::CAP_QUEUE]);

        // Ni 404 ni 403 : la meme redirection propre que partout ailleurs, pour
        // ne pas laisser deviner quels types existent en tapant des URL.
        $this->actingAs($user)
            ->get('/staff/type-inexistant')
            ->assertRedirect(route('staff.home', $type->slug));
    }

    public function test_les_roles_fixes_sont_rediriges_depuis_une_interface_generique(): void
    {
        [$type] = $this->makeStaff([StaffType::CAP_QUEUE]);
        $service = Service::factory()->create();

        $this->actingAs($this->makeAdmin())
            ->get('/staff/'.$type->slug)
            ->assertRedirect(route('admin.home'));

        $this->actingAs($this->makeDoctor($service)->user)
            ->get('/staff/'.$type->slug)
            ->assertRedirect(route('service.home'));
    }

    public function test_la_connexion_mene_a_l_interface_du_type(): void
    {
        [$type, $user] = $this->makeStaff([StaffType::CAP_QUEUE]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('staff.home', $type->slug));
    }

    // ------------------------------------- Sections pilotees par les capacites

    /**
     * @return array<string, array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}>
     */
    public static function combinaisons(): array
    {
        return [
            'file seule' => [
                [StaffType::CAP_QUEUE],
                ["File d'attente"],
                ['Renvois recus', 'Encaissement', 'Soins programmes'],
            ],
            'file et renvois' => [
                [StaffType::CAP_QUEUE, StaffType::CAP_RECEIVE_REFERRAL],
                ["File d'attente", 'Renvois recus'],
                ['Encaissement', 'Soins programmes'],
            ],
            'encaissement seul' => [
                [StaffType::CAP_ACCEPT_PAYMENT],
                ['Encaissement'],
                ["File d'attente", 'Renvois recus', 'Soins programmes'],
            ],
            'soins seuls' => [
                [StaffType::CAP_CARE_TASKS],
                ['Soins programmes'],
                ["File d'attente", 'Renvois recus', 'Encaissement'],
            ],
            'aucune capacite' => [
                [],
                ['Mon planning'],
                ["File d'attente", 'Renvois recus', 'Encaissement', 'Soins programmes'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<int, string>  $attendues
     * @param  array<int, string>  $absentes
     */
    #[DataProvider('combinaisons')]
    public function test_seules_les_sections_des_capacites_cochees_apparaissent(
        array $capabilities,
        array $attendues,
        array $absentes,
    ): void {
        [$type, $user] = $this->makeStaff($capabilities);

        $reponse = $this->actingAs($user)->get('/staff/'.$type->slug)->assertOk();

        foreach ($attendues as $section) {
            $reponse->assertSee($section, escape: false);
        }

        foreach ($absentes as $section) {
            $reponse->assertDontSee($section, escape: false);
        }
    }

    public function test_le_planning_personnel_est_offert_sans_aucune_capacite(): void
    {
        [$type, $user] = $this->makeStaff([]);

        $this->actingAs($user)
            ->get('/staff/'.$type->slug)
            ->assertSee('Mon planning', escape: false);
    }

    // ----------------------------------------- Les capacites gardent le metier

    public function test_une_action_hors_capacite_est_refusee_meme_appelee_directement(): void
    {
        // Le type ne peut que consulter : appeler le suivant ne le regarde pas.
        [, $user] = $this->makeStaff([StaffType::CAP_VIEW_DOSSIER]);

        Livewire::actingAs($user)
            ->test(StaffQueue::class)
            ->call('callNext')
            ->assertStatus(403);
    }

    public function test_un_type_avec_file_appelle_le_patient_suivant(): void
    {
        [, $user, $service] = $this->makeStaff([StaffType::CAP_QUEUE]);
        $visit = $this->makeVisit($service, ['token' => 1]);

        Livewire::actingAs($user)
            ->test(StaffQueue::class)
            ->call('callNext');

        $this->assertSame(Visit::STATUS_CALLED, $visit->refresh()->status);
    }

    public function test_un_type_avec_renvoi_envoie_un_patient_sans_etre_medecin(): void
    {
        [, $user, $service] = $this->makeStaff([StaffType::CAP_QUEUE, StaffType::CAP_SEND_REFERRAL]);
        $laboratoire = Service::factory()->plateauTechnique()->create(['name' => 'Laboratoire']);
        $visit = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);

        Livewire::actingAs($user)
            ->test(StaffQueue::class)
            ->call('startReferral', $visit->getKey())
            ->set('toServiceId', $laboratoire->getKey())
            ->set('instructions', 'Bilan sanguin complet')
            ->call('sendReferral')
            ->assertHasNoErrors();

        $referral = Referral::latest('id')->firstOrFail();

        // L'acte est signe par le membre du personnel, jamais par un faux medecin.
        $this->assertNull($referral->from_doctor_id);
        $this->assertSame(
            $user->staffMember->getKey(),
            $referral->from_staff_member_id,
        );
        $this->assertSame($laboratoire->getKey(), $referral->to_service_id);
    }

    public function test_un_type_qui_recoit_les_renvois_saisit_le_resultat(): void
    {
        $this->makeCaisses();

        $consultation = Service::factory()->create(['name' => 'Medecine Generale']);
        $laboratoire = Service::factory()->plateauTechnique()->create(['name' => 'Laboratoire']);

        [, $technicien] = $this->makeStaff(
            [StaffType::CAP_QUEUE, StaffType::CAP_RECEIVE_REFERRAL],
            $laboratoire,
            'Technicien',
        );

        $prescripteur = $this->makeDoctor($consultation);
        $visit = $this->makeVisit($consultation, ['status' => Visit::STATUS_CALLED]);

        $referral = app(SendReferral::class)
            ->execute($visit, $prescripteur, $laboratoire, 'Bilan');

        app(ConfirmCaissePayment::class)
            ->execute($visit->refresh(), $this->makeCashier(), 5000);

        Livewire::actingAs($technicien)
            ->test(StaffIncomingReferrals::class)
            ->call('startAnswer', $referral->getKey())
            ->set('resultText', 'Hemoglobine 12 g/dL.')
            ->call('submitResult')
            ->assertHasNoErrors();

        // Le retour fonctionne aussi pour un agent non-medecin.
        $this->assertSame($consultation->getKey(), $visit->refresh()->service_id);
        $this->assertSame(Referral::STATUS_DONE, $referral->refresh()->status);
        $this->assertNull($referral->completed_by_doctor_id);
        $this->assertNotNull($referral->completed_by_staff_member_id);
    }

    public function test_un_type_qui_encaisse_enregistre_un_paiement(): void
    {
        [, $user, $service] = $this->makeStaff([StaffType::CAP_ACCEPT_PAYMENT]);
        $visit = $this->makeVisit($service);

        Livewire::actingAs($user)
            ->test(StaffPayments::class)
            ->call('startPayment', $visit->getKey())
            ->set('amount', 3000)
            ->call('record')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payments', [
            'visit_id' => $visit->getKey(),
            'amount' => 3000,
            'recorded_by_user_id' => $user->getKey(),
        ]);
    }

    public function test_un_type_sans_encaissement_ne_peut_pas_encaisser(): void
    {
        [, $user] = $this->makeStaff([StaffType::CAP_QUEUE]);

        // Le composant d'encaissement refuse des le montage : la capacite
        // n'est pas qu'un filtre d'affichage.
        Livewire::actingAs($user)
            ->test(StaffPayments::class)
            ->assertStatus(403);
    }

    public function test_une_visite_d_un_autre_service_reste_hors_de_portee(): void
    {
        [, $user] = $this->makeStaff([StaffType::CAP_QUEUE, StaffType::CAP_CLOSE_VISIT]);
        $ailleurs = Service::factory()->create(['name' => 'Urgences']);
        $visit = $this->makeVisit($ailleurs, ['status' => Visit::STATUS_CALLED]);

        // Une visite hors de mon service n'existe pas de mon point de vue.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test(StaffQueue::class)
            ->call('closeVisit', $visit->getKey());
    }

    // ------------------------------------------------- Administration du type

    public function test_l_admin_cree_un_type_a_interface_dediee(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(StaffTypeManager::class)
            ->set('name', 'Infirmier de garde')
            ->set('matched_role', '')
            ->set('capabilities', [StaffType::CAP_CARE_TASKS, StaffType::CAP_VIEW_DOSSIER])
            ->call('save')
            ->assertHasNoErrors();

        $type = StaffType::where('name', 'Infirmier de garde')->firstOrFail();

        $this->assertNull($type->matched_role);
        $this->assertSame('infirmier-de-garde', $type->slug);
        $this->assertTrue($type->can(StaffType::CAP_CARE_TASKS));
        $this->assertFalse($type->can(StaffType::CAP_QUEUE));
    }

    public function test_un_type_adosse_a_un_role_n_a_ni_slug_ni_capacites(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(StaffTypeManager::class)
            ->set('name', 'Sage-femme')
            ->set('matched_role', Roles::DOCTOR)
            ->set('capabilities', [StaffType::CAP_QUEUE])
            ->call('save')
            ->assertHasNoErrors();

        $type = StaffType::where('name', 'Sage-femme')->firstOrFail();

        // Son interface existe deja : rien a composer.
        $this->assertSame(Roles::DOCTOR, $type->matched_role);
        $this->assertNull($type->slug);
        $this->assertNull($type->capabilities);
        $this->assertSame(route('service.home'), $type->homeUrl());
    }

    public function test_le_role_admin_n_est_jamais_reutilisable(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(StaffTypeManager::class)
            ->set('name', 'Super utilisateur')
            ->set('matched_role', Roles::ADMIN)
            ->call('save')
            ->assertHasErrors('matched_role');
    }

    public function test_l_apercu_annonce_les_sections_reellement_generees(): void
    {
        $composant = Livewire::actingAs($this->makeAdmin())
            ->test(StaffTypeManager::class)
            ->set('matched_role', '')
            ->set('capabilities', [StaffType::CAP_QUEUE, StaffType::CAP_ACCEPT_PAYMENT]);

        $apercu = $composant->instance()->previewSections();

        $this->assertContains(StaffType::CAPABILITIES[StaffType::CAP_QUEUE]['section'], $apercu);
        $this->assertContains(StaffType::CAPABILITIES[StaffType::CAP_ACCEPT_PAYMENT]['section'], $apercu);
        $this->assertNotContains(StaffType::CAPABILITIES[StaffType::CAP_RECEIVE_REFERRAL]['section'], $apercu);
        $this->assertContains('Mon planning', $apercu);
    }

    public function test_un_type_encore_porte_n_est_pas_supprimable(): void
    {
        [$type] = $this->makeStaff([StaffType::CAP_QUEUE]);

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffTypeManager::class)
            ->call('delete', $type->getKey());

        $this->assertDatabaseHas('staff_types', ['id' => $type->getKey()]);
    }
}
