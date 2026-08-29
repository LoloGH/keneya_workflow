<?php

namespace Tests\Feature;

use App\Actions\ConfirmCaissePayment;
use App\Actions\RegisterPatient;
use App\Actions\SendReferral;
use App\Livewire\Admin\DoctorManager;
use App\Livewire\Caisse\CaisseQueue;
use App\Livewire\Reception\PatientRegistrationForm;
use App\Livewire\Service\ServiceQueue;
use App\Models\Patient;
use App\Models\PatientHistory;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Models\Visit;
use App\Services\SmsGateway;
use App\Support\Audit;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Point 6 du v3.2 : la caisse est un role et une interface a part entiere, et
 * plus personne n'atteint un service sans etre passe par elle.
 */
class CaisseFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsGateway::class)->shouldReceive('send')->andReturnTrue();
    }

    // --------------------------------------------------------- Cloisonnement

    public function test_le_caissier_atteint_son_interface(): void
    {
        $this->makeCaisses();

        $this->actingAs($this->makeCashier())->get('/caisse')->assertOk();
    }

    public function test_le_caissier_est_redirige_depuis_les_trois_autres_interfaces(): void
    {
        $this->makeCaisses();
        $cashier = $this->makeCashier();

        foreach (['/admin', '/reception', '/service'] as $interdite) {
            $this->actingAs($cashier)
                ->get($interdite)
                ->assertRedirect(route('caisse.home'));
        }
    }

    public function test_les_autres_roles_sont_rediriges_depuis_la_caisse(): void
    {
        $this->makeCaisses();
        $service = Service::factory()->create();

        $this->actingAs($this->makeAdmin())->get('/caisse')->assertRedirect(route('admin.home'));
        $this->actingAs($this->makeReceptionist())->get('/caisse')->assertRedirect(route('reception.home'));
        $this->actingAs($this->makeDoctor($service)->user)->get('/caisse')->assertRedirect(route('service.home'));
    }

    public function test_la_connexion_d_un_caissier_mene_a_la_caisse(): void
    {
        $this->makeCaisses();
        $this->seedRoles();

        $cashier = $this->makeCashier();

        // La connexion passe par le point d'aiguillage unique, qui renvoie
        // ensuite vers l'interface du role.
        $this->post('/connexion', [
            'email' => $cashier->email,
            'password' => 'motdepasse',
        ])->assertRedirect(route('home'));

        $this->actingAs($cashier)
            ->get(route('home'))
            ->assertRedirect(route(Roles::homeRoute(Roles::CASHIER)));
    }

    // ------------------------------------ Routage sous condition de paiement

    public function test_l_enregistrement_envoie_d_abord_le_patient_a_la_caisse_ticket(): void
    {
        [$ticket] = $this->makeCaisses();
        $consultation = Service::factory()->create(['name' => 'Medecine generale']);

        $visit = app(RegisterPatient::class)->execute([
            'name' => 'Aminata Traore',
            'age' => 32,
            'gender' => 'Femme',
            'mobile' => '70000001',
            'service_id' => $consultation->getKey(),
        ]);

        // Le patient patiente a la caisse, la destination clinique est memorisee.
        $this->assertSame($ticket->getKey(), $visit->service_id);
        $this->assertSame($consultation->getKey(), $visit->pending_next_service_id);
        $this->assertTrue($visit->awaitsPayment());
    }

    public function test_un_renvoi_vers_un_plateau_technique_passe_par_la_caisse_services(): void
    {
        [, $caisseServices] = $this->makeCaisses();

        $consultation = Service::factory()->create(['name' => 'Medecine generale']);
        $laboratoire = Service::factory()->plateauTechnique()->create(['name' => 'Laboratoire']);

        $doctor = $this->makeDoctor($consultation, '70000002');
        $visit = $this->makeVisit($consultation, ['status' => Visit::STATUS_CALLED]);

        $referral = app(SendReferral::class)->execute($visit, $doctor, $laboratoire, 'Bilan sanguin');

        $visit->refresh();

        // La visite attend a la caisse…
        $this->assertSame($caisseServices->getKey(), $visit->service_id);
        $this->assertSame($laboratoire->getKey(), $visit->pending_next_service_id);

        // …mais le renvoi garde la destination medicale reelle : la caisse
        // n'est qu'une etape de routage, jamais la destination du renvoi.
        $this->assertSame($laboratoire->getKey(), $referral->to_service_id);
    }

    public function test_un_renvoi_vers_un_service_sans_peage_ne_passe_par_aucune_caisse(): void
    {
        $this->makeCaisses();

        $depart = Service::factory()->create(['name' => 'Urgences']);
        $cardiologie = Service::factory()->create(['name' => 'Cardiologie']);

        $doctor = $this->makeDoctor($depart);
        $visit = $this->makeVisit($depart, ['status' => Visit::STATUS_CALLED]);

        app(SendReferral::class)->execute($visit, $doctor, $cardiologie, 'Avis specialise');

        $visit->refresh();

        // Le type « Clinique » ne porte pas `requires_payment_gate` : depuis le
        // v3.2.1, c'est cet indicateur seul qui decide du peage, et un avis
        // inter-services ne se paie donc plus.
        $this->assertFalse($cardiologie->requiresPaymentGate());
        $this->assertSame($cardiologie->getKey(), $visit->service_id);
        $this->assertNull($visit->pending_next_service_id);
    }

    public function test_un_type_de_service_cree_par_l_admin_declenche_le_peage_s_il_est_coche(): void
    {
        [, $caisseServices] = $this->makeCaisses();

        $depart = Service::factory()->create(['name' => 'Urgences']);
        $doctor = $this->makeDoctor($depart);

        // Deux types tout neufs, l'un payant, l'autre non — aucun n'existe dans
        // le code : seul l'indicateur coche par l'admin les distingue.
        $payant = ServiceKind::create([
            'name' => 'Imagerie',
            'slug' => 'imagerie',
            'requires_payment_gate' => true,
        ]);
        $gratuit = ServiceKind::create([
            'name' => 'Soins de suite',
            'slug' => 'soins_de_suite',
            'requires_payment_gate' => false,
        ]);

        $scanner = Service::factory()->ofKind($payant)->create(['name' => 'Scanner']);
        $kine = Service::factory()->ofKind($gratuit)->create(['name' => 'Kinesitherapie']);

        $versScanner = $this->makeVisit($depart, ['status' => Visit::STATUS_CALLED]);
        app(SendReferral::class)->execute($versScanner, $doctor, $scanner, 'Scanner cerebral');

        $this->assertSame($caisseServices->getKey(), $versScanner->refresh()->service_id);
        $this->assertSame($scanner->getKey(), $versScanner->pending_next_service_id);

        $versKine = $this->makeVisit($depart, ['status' => Visit::STATUS_CALLED]);
        app(SendReferral::class)->execute($versKine, $doctor, $kine, 'Reeducation');

        $this->assertSame($kine->getKey(), $versKine->refresh()->service_id);
        $this->assertNull($versKine->pending_next_service_id);
    }

    public function test_decocher_le_peage_d_un_type_suffit_a_supprimer_l_etape_de_caisse(): void
    {
        $this->makeCaisses();

        $depart = Service::factory()->create();
        $doctor = $this->makeDoctor($depart);
        $laboratoire = Service::factory()->plateauTechnique()->create(['name' => 'Laboratoire']);

        // L'admin decide que le laboratoire n'est plus payant d'avance.
        $laboratoire->serviceKind->update(['requires_payment_gate' => false]);

        $visit = $this->makeVisit($depart, ['status' => Visit::STATUS_CALLED]);
        app(SendReferral::class)->execute($visit, $doctor, $laboratoire->refresh(), 'Bilan');

        $this->assertSame($laboratoire->getKey(), $visit->refresh()->service_id);
    }

    public function test_la_confirmation_de_paiement_bascule_la_visite_vers_le_bon_service(): void
    {
        [$ticket] = $this->makeCaisses();
        $consultation = Service::factory()->create(['name' => 'Medecine generale']);

        $cashier = $this->makeCashier();
        $visit = $this->makeVisit($ticket, [
            'pending_next_service_id' => $consultation->getKey(),
            'token' => 7,
        ]);

        $apres = app(ConfirmCaissePayment::class)->execute($visit, $cashier, 2500);

        $this->assertSame($consultation->getKey(), $apres->service_id);
        $this->assertNull($apres->pending_next_service_id);
        $this->assertSame(Visit::STATUS_WAITING, $apres->status);
        // Nouveau ticket dans la file cible : la numerotation est par service.
        $this->assertSame(1, $apres->token);

        $this->assertDatabaseHas('payments', [
            'visit_id' => $visit->getKey(),
            'service_id' => $consultation->getKey(),
            'type' => Payment::TYPE_TICKET,
            'amount' => 2500,
            'status' => Payment::STATUS_PAID,
            'recorded_by_user_id' => $cashier->getKey(),
        ]);

        $this->assertDatabaseHas('patient_history', [
            'visit_id' => $visit->getKey(),
            'type' => PatientHistory::TYPE_PAYMENT_CONFIRMED,
        ]);
    }

    public function test_un_encaissement_a_la_caisse_services_est_du_type_service(): void
    {
        [, $caisseServices] = $this->makeCaisses();
        $echographie = Service::factory()->plateauTechnique()->create(['name' => 'Echographie']);

        $visit = $this->makeVisit($caisseServices, [
            'pending_next_service_id' => $echographie->getKey(),
        ]);

        app(ConfirmCaissePayment::class)->execute($visit, $this->makeCashier(), 10000);

        $this->assertDatabaseHas('payments', [
            'visit_id' => $visit->getKey(),
            'type' => Payment::TYPE_SERVICE,
            'amount' => 10000,
        ]);
    }

    public function test_une_visite_qui_n_attend_aucun_paiement_est_refusee(): void
    {
        [$ticket] = $this->makeCaisses();
        $visit = $this->makeVisit($ticket);

        $this->expectException(\InvalidArgumentException::class);

        app(ConfirmCaissePayment::class)->execute($visit, $this->makeCashier(), 1000);
    }

    // ------------------------------------------------------- Interface /caisse

    public function test_l_interface_caisse_montre_les_deux_files(): void
    {
        $this->makeCaisses();

        $this->actingAs($this->makeCashier())
            ->get('/caisse')
            ->assertSee(Service::CAISSE_TICKET)
            ->assertSee(Service::CAISSE_SERVICES);
    }

    public function test_le_caissier_encaisse_et_oriente_depuis_sa_file(): void
    {
        [$ticket] = $this->makeCaisses();
        $consultation = Service::factory()->create(['name' => 'Medecine generale']);

        $cashier = $this->makeCashier();
        $visit = $this->makeVisit($ticket, [
            'pending_next_service_id' => $consultation->getKey(),
        ]);

        Livewire::actingAs($cashier)
            ->test(CaisseQueue::class, ['serviceId' => $ticket->getKey()])
            ->call('startPayment', $visit->getKey())
            ->set('amount', 1500)
            ->call('confirmAndRoute')
            ->assertHasNoErrors();

        $this->assertSame($consultation->getKey(), $visit->refresh()->service_id);
    }

    public function test_une_file_qui_n_est_pas_une_caisse_est_refusee(): void
    {
        $this->makeCaisses();
        $clinique = Service::factory()->create();

        Livewire::actingAs($this->makeCashier())
            ->test(CaisseQueue::class, ['serviceId' => $clinique->getKey()])
            ->assertStatus(403);
    }

    // ------------------------------- La caisse n'est jamais choisie a la main

    public function test_la_caisse_n_est_pas_proposee_comme_service_a_l_accueil(): void
    {
        $this->makeCaisses();
        Service::factory()->create(['name' => 'Medecine generale']);

        // La receptionniste choisit une destination de soin ; c'est le routage
        // qui intercale la caisse, pas elle.
        Livewire::actingAs($this->makeReceptionist())
            ->test(PatientRegistrationForm::class)
            ->assertSee('Medecine generale')
            ->assertDontSee(Service::CAISSE_TICKET)
            ->assertDontSee(Service::CAISSE_SERVICES);
    }

    public function test_la_caisse_n_est_pas_proposee_comme_destination_de_renvoi(): void
    {
        $this->makeCaisses();

        $depart = Service::factory()->create(['name' => 'Urgences']);
        $laboratoire = Service::factory()->plateauTechnique()->create(['name' => 'Laboratoire']);
        $doctor = $this->makeDoctor($depart);

        $proposes = Livewire::actingAs($doctor->user)
            ->test(ServiceQueue::class, ['serviceId' => $depart->getKey()])
            ->viewData('otherServices')
            ->pluck('name');

        $this->assertTrue($proposes->contains('Laboratoire'));
        $this->assertFalse($proposes->contains(Service::CAISSE_TICKET));
        $this->assertFalse($proposes->contains(Service::CAISSE_SERVICES));
    }

    public function test_un_renvoi_vers_une_caisse_est_refuse(): void
    {
        [$ticket] = $this->makeCaisses();

        $depart = Service::factory()->create();
        $doctor = $this->makeDoctor($depart);
        $visit = $this->makeVisit($depart, ['status' => Visit::STATUS_CALLED]);

        $this->expectException(\InvalidArgumentException::class);

        app(SendReferral::class)->execute($visit, $doctor, $ticket, 'Paiement');
    }

    public function test_un_medecin_ne_peut_pas_etre_affecte_a_une_caisse(): void
    {
        [$ticket] = $this->makeCaisses();
        $this->seedRoles();

        // Sinon il verrait la file de la caisse depuis /service : les medecins
        // n'encaissent jamais.
        Livewire::actingAs($this->makeAdmin())
            ->test(DoctorManager::class)
            ->set('name', 'Dr Test')
            ->set('email', 'test@keneya.local')
            ->set('password', 'motdepasse')
            ->set('service_id', $ticket->getKey())
            ->call('save')
            ->assertHasErrors('service_id');
    }

    // ----------------------------------------------- Journal d'audit metier

    public function test_l_appel_du_suivant_et_la_confirmation_sont_journalises(): void
    {
        [$ticket] = $this->makeCaisses();
        $consultation = Service::factory()->create(['name' => 'Medecine generale']);

        $cashier = $this->makeCashier();
        $visit = $this->makeVisit($ticket, [
            'pending_next_service_id' => $consultation->getKey(),
            'token' => 1,
        ]);

        Livewire::actingAs($cashier)
            ->test(CaisseQueue::class, ['serviceId' => $ticket->getKey()])
            ->call('callNext')
            ->call('startPayment', $visit->getKey())
            ->set('amount', 2000)
            ->call('confirmAndRoute')
            ->assertHasNoErrors();

        $appel = Activity::where('event', Audit::EVENT_PATIENT_CALLED)->latest('id')->first();
        $this->assertNotNull($appel, 'L\'appel du suivant doit etre journalise.');
        $this->assertSame($cashier->getKey(), $appel->causer_id);
        $this->assertStringContainsString('appele', mb_strtolower($appel->description));

        $paiement = Activity::where('event', Audit::EVENT_PAYMENT_CONFIRMED)->latest('id')->first();
        $this->assertNotNull($paiement, 'La confirmation de paiement doit etre journalisee.');
        $this->assertSame($cashier->getKey(), $paiement->causer_id);
        // Une description lisible par un humain, pas un diff d'attributs.
        $this->assertStringContainsString('Medecine generale', $paiement->description);
        $this->assertSame(2000, $paiement->properties['montant']);
    }

    public function test_sans_caisse_configuree_le_patient_va_directement_au_service(): void
    {
        // Aucun service kind=caisse : une installation qui n'utilise pas la
        // caisse doit continuer a fonctionner sans routage force.
        $consultation = Service::factory()->create(['name' => 'Medecine generale']);

        $visit = app(RegisterPatient::class)->execute([
            'name' => 'Sekou Diarra',
            'age' => 44,
            'gender' => 'Homme',
            'mobile' => '70000003',
            'service_id' => $consultation->getKey(),
        ]);

        $this->assertSame($consultation->getKey(), $visit->service_id);
        $this->assertNull($visit->pending_next_service_id);
        $this->assertSame(1, Patient::count());
    }
}
