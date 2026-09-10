<?php

namespace Tests\Feature;

use App\Actions\BulkCreateSchedule;
use App\Actions\CreatePrescription;
use App\Actions\RecordConsultationConclusion;
use App\Actions\RegisterVisitor;
use App\Actions\StoreAttachment;
use App\Livewire\Admin\BulkScheduleForm;
use App\Livewire\Admin\PatientDirectory;
use App\Livewire\Reception\VisitorRegistrationForm;
use App\Livewire\Service\ConsultationActions;
use App\Livewire\Service\MyAppointments;
use App\Livewire\Service\MyPatients;
use App\Livewire\Service\PatientRecordPanel;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientHistory;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\PatientHistoryRecorder;
use App\Services\SmsGateway;
use App\Services\SmsSendResult;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Points 1 a 5 du v3.2 : visiteurs rattaches, planning groupe, dossier
 * (pieces jointes, ordonnances, frise unifiee), rendez-vous et conclusion.
 */
class DossierWorkflowV32Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsGateway::class)->shouldReceive('deliver')->andReturn(SmsSendResult::sent());
    }

    // ------------------------------------ Point 1 : visiteurs rattaches

    public function test_un_visiteur_est_rattache_au_patient_visite(): void
    {
        $service = Service::factory()->create(['name' => 'Chirurgie']);
        $patient = Patient::factory()->create(['name' => 'Moussa Keita']);

        $visiteur = app(RegisterVisitor::class)->execute([
            'name' => 'Awa Keita',
            'mobile' => '70223344',
            'service_id' => $service->getKey(),
            'patient_id' => $patient->getKey(),
        ]);

        $this->assertSame($patient->getKey(), $visiteur->patient_id);
        $this->assertSame('Moussa Keita', $visiteur->patient->name);
    }

    public function test_un_service_clinique_exige_le_patient_visite(): void
    {
        $service = Service::factory()->create(['name' => 'Chirurgie']);

        $this->expectException(InvalidArgumentException::class);

        app(RegisterVisitor::class)->execute([
            'name' => 'Awa Keita',
            'service_id' => $service->getKey(),
        ]);
    }

    public function test_un_service_non_clinique_accepte_un_visiteur_sans_patient(): void
    {
        // Une demarche administrative n'a pas de patient a visiter.
        $administration = Service::factory()->plateauTechnique()->create(['name' => 'Administration']);

        $visiteur = app(RegisterVisitor::class)->execute([
            'name' => 'Ibrahim Cisse',
            'service_id' => $administration->getKey(),
        ]);

        $this->assertNull($visiteur->patient_id);
    }

    public function test_le_formulaire_visiteur_rattache_le_patient_choisi(): void
    {
        $service = Service::factory()->create(['name' => 'Chirurgie']);
        $patient = Patient::factory()->create(['name' => 'Moussa Keita']);

        Livewire::actingAs($this->makeReceptionist())
            ->test(VisitorRegistrationForm::class)
            ->set('name', 'Awa Keita')
            ->set('service_id', $service->getKey())
            ->set('patientSearch', 'Moussa')
            ->call('selectPatient', $patient->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('visitors', [
            'name' => 'Awa Keita',
            'patient_id' => $patient->getKey(),
        ]);
    }

    public function test_le_patient_visite_apparait_sur_l_ecran_de_salle_d_attente(): void
    {
        $service = Service::factory()->create(['name' => 'Chirurgie']);
        $patient = Patient::factory()->create(['name' => 'Moussa Keita']);

        Visitor::factory()->create([
            'service_id' => $service->getKey(),
            'patient_id' => $patient->getKey(),
            'token' => 1,
        ]);

        $this->get('/board')->assertOk()->assertSee('Moussa Keita');
    }

    // -------------------------------------- Point 2 : planning groupe

    public function test_la_creation_groupee_genere_une_ligne_par_jour_coche(): void
    {
        $user = $this->makeReceptionist();

        // Du lundi 5 au dimanche 18 janvier 2027 : deux lundis, deux mercredis.
        $crees = app(BulkCreateSchedule::class)->execute(
            user: $user,
            from: Carbon::parse('2027-01-04'),
            to: Carbon::parse('2027-01-17'),
            weekdays: [1, 3],
            startTime: '08:00',
            endTime: '14:00',
        );

        $this->assertSame(4, $crees);
        $this->assertSame(4, Schedule::where('user_id', $user->getKey())->count());

        foreach (['2027-01-04', '2027-01-06', '2027-01-11', '2027-01-13'] as $date) {
            $this->assertTrue(
                Schedule::where('user_id', $user->getKey())->whereDate('date', $date)->exists(),
                "Un creneau etait attendu le {$date}.",
            );
        }

        // Les jours non coches restent vides.
        $this->assertFalse(
            Schedule::where('user_id', $user->getKey())->whereDate('date', '2027-01-05')->exists(),
        );
    }

    public function test_une_generation_relancee_ne_duplique_pas_les_creneaux(): void
    {
        $user = $this->makeReceptionist();

        $arguments = [
            'user' => $user,
            'from' => Carbon::parse('2027-01-04'),
            'to' => Carbon::parse('2027-01-08'),
            'weekdays' => [1, 2, 3, 4, 5],
            'startTime' => '08:00',
            'endTime' => '14:00',
        ];

        $this->assertSame(5, app(BulkCreateSchedule::class)->execute(...$arguments));
        $this->assertSame(0, app(BulkCreateSchedule::class)->execute(...$arguments));
        $this->assertSame(5, Schedule::where('user_id', $user->getKey())->count());
    }

    public function test_une_plage_inversee_ou_sans_jour_est_refusee(): void
    {
        $user = $this->makeReceptionist();

        try {
            app(BulkCreateSchedule::class)->execute(
                user: $user,
                from: Carbon::parse('2027-01-10'),
                to: Carbon::parse('2027-01-04'),
                weekdays: [1],
                startTime: '08:00',
                endTime: '14:00',
            );
            $this->fail('Une plage inversee devait etre refusee.');
        } catch (InvalidArgumentException) {
            // Attendu.
        }

        $this->expectException(InvalidArgumentException::class);

        app(BulkCreateSchedule::class)->execute(
            user: $user,
            from: Carbon::parse('2027-01-04'),
            to: Carbon::parse('2027-01-10'),
            weekdays: [],
            startTime: '08:00',
            endTime: '14:00',
        );
    }

    public function test_l_admin_genere_un_planning_depuis_son_interface(): void
    {
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);

        Livewire::actingAs($this->makeAdmin())
            ->test(BulkScheduleForm::class)
            ->set('user_id', $doctor->user_id)
            ->set('from', '2027-02-01')
            ->set('to', '2027-02-07')
            ->set('weekdays', ['1', '4'])
            ->set('start_time', '09:00')
            ->set('end_time', '15:00')
            ->set('service_id', $service->getKey())
            ->call('generate')
            ->assertHasNoErrors();

        $this->assertSame(2, Schedule::where('user_id', $doctor->user_id)->count());

        $trace = Activity::where('event', Audit::EVENT_SCHEDULE_BULK)->latest('id')->first();
        $this->assertNotNull($trace, 'La generation groupee doit etre journalisee.');
    }

    // ------------- Point 3 : pieces jointes, ordonnances, frise unifiee

    public function test_le_medecin_depose_une_piece_jointe_depuis_le_dossier(): void
    {
        Storage::fake('attachments');

        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $patient = Patient::factory()->create();
        $visit = $this->makeVisit($service, [], $patient);

        app(PatientHistoryRecorder::class)->record(
            visit: $visit,
            type: PatientHistory::TYPE_CONSULTATION,
            description: 'Consultation.',
            doctor: $doctor,
        );

        Livewire::actingAs($doctor->user)
            ->test(MyPatients::class)
            ->call('startAttachment', $patient->getKey())
            ->set('files', [UploadedFile::fake()->create('radio.pdf', 40, 'application/pdf')])
            ->call('saveAttachment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attachments', [
            'patient_id' => $patient->getKey(),
            'original_name' => 'radio.pdf',
            // Depot depuis le dossier : ni renvoi, ni entree d'historique.
            'referral_id' => null,
            'patient_history_id' => null,
        ]);
    }

    public function test_l_admin_depose_une_piece_jointe_depuis_la_vue_globale(): void
    {
        Storage::fake('attachments');

        $service = Service::factory()->create();
        $patient = Patient::factory()->create();
        $this->makeVisit($service, [], $patient);

        Livewire::actingAs($this->makeAdmin())
            ->test(PatientDirectory::class)
            ->call('openRecord', $patient->getKey())
            ->call('startAttachment')
            ->set('files', [UploadedFile::fake()->image('cliche.png')])
            ->call('saveAttachment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attachments', [
            'patient_id' => $patient->getKey(),
            'original_name' => 'cliche.png',
        ]);
    }

    public function test_le_dossier_affiche_une_seule_frise_triee_par_date(): void
    {
        Storage::fake('attachments');

        $service = Service::factory()->create(['name' => 'Medecine generale']);
        $doctor = $this->makeDoctor($service);
        $patient = Patient::factory()->create();
        $visit = $this->makeVisit($service, [], $patient);

        $recorder = app(PatientHistoryRecorder::class);

        $this->travelTo(now()->setTime(9, 0));
        $recorder->record(
            visit: $visit,
            type: PatientHistory::TYPE_CONSULTATION,
            description: 'Consultation du matin.',
            doctor: $doctor,
        );

        $this->travelTo(now()->setTime(10, 0));
        app(CreatePrescription::class)->execute($visit, $doctor, [['medicament' => 'Amoxicilline 1 g']]);

        $this->travelTo(now()->setTime(11, 0));
        app(StoreAttachment::class)->executeForPatient(
            UploadedFile::fake()->create('scanner.pdf', 30, 'application/pdf'),
            $patient,
            $doctor->user,
            $visit,
        );

        $this->travelBack();

        $rendu = Livewire::actingAs($doctor->user)
            ->test(PatientRecordPanel::class)
            ->call('open', $patient->getKey())
            ->html();

        // Les trois natures d'evenement sont dans la meme liste, dans l'ordre.
        $consultation = mb_strpos($rendu, 'Consultation du matin.');
        $ordonnance = mb_strpos($rendu, 'Ordonnance etablie');
        $piece = mb_strpos($rendu, 'scanner.pdf');

        $this->assertNotFalse($consultation);
        $this->assertNotFalse($ordonnance);
        $this->assertNotFalse($piece, 'Une piece jointe deposee dans le dossier doit figurer dans la frise.');
        $this->assertTrue(
            $consultation < $ordonnance && $ordonnance < $piece,
            'La frise doit rester triee par date, toutes natures confondues.',
        );

        // Une seule frise : pas de bloc « Pieces jointes » separe.
        $this->assertSame(1, substr_count($rendu, 'class="timeline"'));
    }

    public function test_le_dossier_propose_l_impression_des_pieces_jointes_et_ordonnances(): void
    {
        Storage::fake('attachments');

        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $patient = Patient::factory()->create();
        $visit = $this->makeVisit($service, [], $patient);

        $prescription = app(CreatePrescription::class)->execute($visit, $doctor, [['medicament' => 'Amoxicilline 1 g']]);

        $piece = app(StoreAttachment::class)->executeForPatient(
            UploadedFile::fake()->create('scanner.pdf', 30, 'application/pdf'),
            $patient,
            $doctor->user,
            $visit,
        );

        Livewire::actingAs($doctor->user)
            ->test(PatientRecordPanel::class)
            ->call('open', $patient->getKey())
            ->assertSee(route('service.attachment.print', $piece), escape: false)
            ->assertSee(route('service.prescription.print', $prescription), escape: false);
    }

    public function test_la_vue_imprimable_d_une_ordonnance_porte_les_bons_champs(): void
    {
        $service = Service::factory()->create(['name' => 'Medecine generale']);
        $doctor = $this->makeDoctor($service);
        $patient = Patient::factory()->create(['name' => 'Moussa Keita']);
        $visit = $this->makeVisit($service, [], $patient);

        $prescription = app(CreatePrescription::class)->execute($visit, $doctor, [['medicament' => 'Amoxicilline 1 g', 'posologie' => 'matin et soir']]);

        $this->actingAs($doctor->user)
            ->get(route('service.prescription.print', $prescription))
            ->assertOk()
            ->assertSee('Moussa Keita')
            ->assertSee($patient->patient_code)
            // Chaque ligne a sa colonne : le medicament et la posologie ne
            // sont plus un seul bloc de texte.
            ->assertSee('Amoxicilline 1 g')
            ->assertSee('matin et soir')
            // Le bloc porte la signature et le cachet du medecin depuis la
            // v3.3.1 : son intitule le dit, sans quoi il annoncerait moins
            // que ce qu'il montre.
            ->assertSee('Signature et cachet du medecin')
            // `escape: false` : l'intitule est du texte fixe du gabarit, son
            // apostrophe n'est donc pas echappee dans la page.
            ->assertSee("Cachet de l'etablissement", escape: false)
            ->assertSee('window.print()', escape: false);
    }

    public function test_un_medecin_d_un_autre_service_ne_peut_pas_imprimer_l_ordonnance(): void
    {
        $service = Service::factory()->create();
        $autre = Service::factory()->create();

        $doctor = $this->makeDoctor($service);
        $intrus = $this->makeDoctor($autre);

        $visit = $this->makeVisit($service);
        $prescription = app(CreatePrescription::class)->execute($visit, $doctor, [['medicament' => 'Amoxicilline 1 g']]);

        $this->actingAs($intrus->user)
            ->get(route('service.prescription.print', $prescription))
            ->assertForbidden();
    }

    // --------------------------------------- Point 4 : mes rendez-vous

    public function test_le_medecin_donne_un_rendez_vous_a_un_patient_de_sa_liste(): void
    {
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $patient = Patient::factory()->create();
        $visit = $this->makeVisit($service, ['status' => Visit::STATUS_CLOSED], $patient);

        app(PatientHistoryRecorder::class)->record(
            visit: $visit,
            type: PatientHistory::TYPE_CONSULTATION,
            description: 'Consultation.',
            doctor: $doctor,
        );

        // Le dossier est cloture : donner un rendez-vous ne doit plus dependre
        // d'une consultation en cours.
        Livewire::actingAs($doctor->user)
            ->test(MyPatients::class)
            ->call('startAppointment', $patient->getKey())
            ->set('appointmentAt', now()->addDays(10)->format('Y-m-d\TH:i'))
            ->call('saveAppointment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
        ]);
    }

    public function test_mes_rendez_vous_liste_ceux_du_medecin_connecte(): void
    {
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $autre = $this->makeDoctor($service);

        $mien = Patient::factory()->create(['name' => 'Moussa Keita']);
        $sien = Patient::factory()->create(['name' => 'Fatoumata Sidibe']);

        foreach ([[$doctor, $mien], [$autre, $sien]] as [$praticien, $patient]) {
            Appointment::create([
                'patient_id' => $patient->getKey(),
                'service_id' => $service->getKey(),
                'doctor_id' => $praticien->getKey(),
                'scheduled_at' => now()->addDays(3),
                'status' => Appointment::STATUS_SCHEDULED,
            ]);
        }

        Livewire::actingAs($doctor->user)
            ->test(MyAppointments::class)
            ->assertSee('Moussa Keita')
            ->assertDontSee('Fatoumata Sidibe');
    }

    // ------------------------------- Point 5 : conclusion de consultation

    public function test_la_conclusion_est_une_entree_d_historique(): void
    {
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);

        app(RecordConsultationConclusion::class)->execute(
            $visit,
            $doctor,
            'Angine virale, evolution favorable attendue sous 5 jours.',
        );

        $this->assertDatabaseHas('patient_history', [
            'visit_id' => $visit->getKey(),
            'type' => PatientHistory::TYPE_CONSULTATION_CONCLUSION,
            'description' => 'Angine virale, evolution favorable attendue sous 5 jours.',
        ]);

        // Distincte de l'ordonnance : aucune ligne `prescriptions` creee.
        $this->assertSame(0, \DB::table('prescriptions')->count());
    }

    public function test_le_medecin_redige_la_conclusion_depuis_sa_consultation(): void
    {
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);

        Livewire::actingAs($doctor->user)
            ->test(ConsultationActions::class, ['serviceId' => $service->getKey()])
            ->set('visitId', $visit->getKey())
            ->call('selectTab', 'conclusion')
            ->set('conclusion', 'Angine virale.')
            ->call('recordConclusion')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('patient_history', [
            'visit_id' => $visit->getKey(),
            'type' => PatientHistory::TYPE_CONSULTATION_CONCLUSION,
        ]);
    }
}
