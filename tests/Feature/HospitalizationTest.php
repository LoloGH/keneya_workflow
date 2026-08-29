<?php

namespace Tests\Feature;

use App\Actions\AdmitPatient;
use App\Actions\CompleteCareTask;
use App\Actions\DischargePatient;
use App\Actions\PrescribeCareTasks;
use App\Livewire\Service\Hospitalizations;
use App\Livewire\Staff\StaffCareTasks;
use App\Models\CareTask;
use App\Models\CareTaskType;
use App\Models\Doctor;
use App\Models\Hospitalization;
use App\Models\PatientHistory;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\StaffType;
use App\Models\User;
use App\Models\Visit;
use App\Services\SmsGateway;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Hospitalisation, salles et planning de soins (v3.2.1, point 11).
 */
class HospitalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsGateway::class)->shouldReceive('send')->andReturnTrue();
    }

    /** @return array{0: Service, 1: Doctor, 2: Visit} */
    private function makeAdmissionContext(): array
    {
        $service = Service::factory()->create(['name' => 'Medecine Generale']);
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);

        return [$service, $doctor, $visit];
    }

    /** Un infirmier de garde sur le service, avec la capacite « soins ». */
    private function makeNurse(Service $service, bool $onDuty = true): User
    {
        $type = StaffType::create([
            'name' => 'Infirmier',
            'matched_role' => null,
            'slug' => StaffType::makeSlug('Infirmier'),
            'capabilities' => [StaffType::CAP_CARE_TASKS],
        ]);

        $user = User::factory()->create();

        StaffMember::create([
            'user_id' => $user->getKey(),
            'staff_type_id' => $type->getKey(),
            'service_id' => $service->getKey(),
        ]);

        if ($onDuty) {
            Schedule::create([
                'user_id' => $user->getKey(),
                'service_id' => $service->getKey(),
                'date' => today()->toDateString(),
                'start_time' => '00:00:00',
                'end_time' => '23:59:00',
            ]);
        }

        return $user;
    }

    // ------------------------------------------------------------ Admission

    public function test_l_admission_cloture_la_visite_en_cours(): void
    {
        [, $doctor, $visit] = $this->makeAdmissionContext();

        $hospitalization = app(AdmitPatient::class)->execute($visit, $doctor);

        // Un patient hospitalise n'attend plus un tour dans une file.
        $this->assertSame(Visit::STATUS_CLOSED, $visit->refresh()->status);
        $this->assertNotNull($visit->closed_at);

        $this->assertSame(Hospitalization::STATUS_ACTIVE, $hospitalization->status);
        $this->assertSame($visit->patient_id, $hospitalization->patient_id);
        $this->assertSame($visit->getKey(), $hospitalization->visit_id);
    }

    public function test_l_admission_laisse_une_trace_sur_la_frise_du_dossier(): void
    {
        [, $doctor, $visit] = $this->makeAdmissionContext();

        app(AdmitPatient::class)->execute($visit, $doctor);

        $this->assertDatabaseHas('patient_history', [
            'visit_id' => $visit->getKey(),
            'type' => PatientHistory::TYPE_HOSPITALIZATION_ADMITTED,
        ]);

        $this->assertNotNull(Activity::where('event', Audit::EVENT_PATIENT_ADMITTED)->first());
    }

    public function test_un_praticien_d_un_autre_service_ne_peut_pas_hospitaliser(): void
    {
        [, , $visit] = $this->makeAdmissionContext();
        $ailleurs = $this->makeDoctor(Service::factory()->create(['name' => 'Urgences']));

        $this->expectException(InvalidArgumentException::class);

        app(AdmitPatient::class)->execute($visit, $ailleurs);
    }

    // ---------------------------------------------- Salles et capacite

    public function test_l_occupation_se_calcule_sur_les_hospitalisations_actives(): void
    {
        [$service, $doctor] = $this->makeAdmissionContext();
        $room = Room::create(['name' => 'Salle 3', 'service_id' => $service->getKey(), 'capacity' => 2]);

        $this->assertSame(0, $room->occupancy());

        $premier = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);
        $hospitalisation = app(AdmitPatient::class)->execute($premier, $doctor, $room);

        $this->assertSame(1, $room->refresh()->occupancy());
        $this->assertSame('1/2 lits occupes', $room->occupancyLabel());

        // Une sortie libere le lit, sans compteur a maintenir.
        app(DischargePatient::class)->execute($hospitalisation, $doctor);

        $this->assertSame(0, $room->refresh()->occupancy());
    }

    public function test_une_salle_pleine_avertit_sans_bloquer_l_admission(): void
    {
        [$service, $doctor] = $this->makeAdmissionContext();
        $room = Room::create(['name' => 'Salle 1', 'service_id' => $service->getKey(), 'capacity' => 1]);

        app(AdmitPatient::class)->execute(
            $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]),
            $doctor,
            $room,
        );

        $this->assertTrue($room->refresh()->isFull());

        $suivant = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);

        // Sans confirmation : refuse, avec un message qui dit pourquoi.
        try {
            app(AdmitPatient::class)->execute($suivant, $doctor, $room);
            $this->fail('Une salle pleine devait demander une confirmation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('pleine', $e->getMessage());
        }

        // Avec confirmation : admis malgre tout — une urgence depasse parfois
        // la capacite nominale, un blocage strict serait dangereux.
        $hospitalisation = app(AdmitPatient::class)->execute($suivant, $doctor, $room, overCapacityConfirmed: true);

        $this->assertSame(Hospitalization::STATUS_ACTIVE, $hospitalisation->status);
        $this->assertSame(2, $room->refresh()->occupancy());
    }

    public function test_l_admission_au_dela_de_la_capacite_passe_par_l_interface(): void
    {
        [$service, $doctor] = $this->makeAdmissionContext();
        $room = Room::create(['name' => 'Salle 1', 'service_id' => $service->getKey(), 'capacity' => 1]);

        app(AdmitPatient::class)->execute(
            $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]),
            $doctor,
            $room,
        );

        $suivant = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);

        $composant = Livewire::actingAs($doctor->user)
            ->test(Hospitalizations::class, ['serviceId' => $service->getKey()])
            ->call('startAdmission', $suivant->getKey())
            ->set('roomId', $room->getKey())
            ->call('admit')
            ->assertHasErrors('roomId');

        $composant->call('startAdmission', $suivant->getKey())
            ->set('roomId', $room->getKey())
            ->set('overCapacityConfirmed', true)
            ->call('admit')
            ->assertHasNoErrors();

        $this->assertSame(Visit::STATUS_CLOSED, $suivant->refresh()->status);
    }

    // ------------------------------------------------- Planning de soins

    public function test_la_generation_groupee_produit_le_bon_nombre_d_occurrences(): void
    {
        [, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);
        $type = CareTaskType::create(['name' => 'Serum']);

        // Toutes les 8 heures pendant 3 jours : 72 / 8 = 9 administrations.
        $crees = app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: $type,
            doctor: $doctor,
            start: Carbon::parse('2027-03-01 08:00'),
            intervalHours: 8,
            durationDays: 3,
            instructions: 'Serum glucose 500 ml',
        );

        $this->assertSame(9, $crees);
        $this->assertSame(9, CareTask::where('hospitalization_id', $hospitalisation->getKey())->count());

        // Chaque occurrence est une ligne distincte, marquable individuellement.
        $this->assertSame(
            '2027-03-01 08:00',
            CareTask::orderBy('scheduled_at')->first()->scheduled_at->format('Y-m-d H:i'),
        );
        // La derniere occurrence tombe avant la fin de fenetre (08:00 + 3 j).
        $this->assertSame(
            '2027-03-04 00:00',
            CareTask::orderByDesc('scheduled_at')->first()->scheduled_at->format('Y-m-d H:i'),
        );
    }

    public function test_une_prescription_deraisonnable_est_refusee(): void
    {
        [, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        $this->expectException(InvalidArgumentException::class);

        // Toutes les heures pendant 60 jours : bien au-dela du garde-fou.
        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now(),
            intervalHours: 1,
            durationDays: 60,
        );
    }

    public function test_le_medecin_prescrit_des_soins_depuis_son_interface(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);
        $type = CareTaskType::create(['name' => 'Injection']);

        Livewire::actingAs($doctor->user)
            ->test(Hospitalizations::class, ['serviceId' => $service->getKey()])
            ->call('startPrescription', $hospitalisation->getKey())
            ->set('careTaskTypeId', $type->getKey())
            ->set('careStartsAt', '2027-03-01T08:00')
            ->set('careIntervalHours', 12)
            ->set('careDurationDays', 2)
            ->call('prescribe')
            ->assertHasNoErrors();

        $this->assertSame(4, CareTask::count());
    }

    // ------------------------------------------- Execution et filtrage de garde

    public function test_le_personnel_de_garde_voit_les_soins_de_son_service(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        $infirmier = $this->makeNurse($service);

        Livewire::actingAs($infirmier)
            ->test(StaffCareTasks::class)
            ->assertSee('Serum')
            ->assertSee($visit->patient->name);
    }

    public function test_hors_garde_aucun_soin_n_est_visible(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        // Meme infirmier, meme service, mais aucun creneau couvrant l'heure.
        $infirmier = $this->makeNurse($service, onDuty: false);

        Livewire::actingAs($infirmier)
            ->test(StaffCareTasks::class)
            ->assertDontSee('Serum')
            ->assertSee("Vous n'etes pas de garde", escape: false);
    }

    public function test_une_ligne_de_garde_suffit_a_faire_apparaitre_les_soins(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Pansement']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        $infirmier = $this->makeNurse($service, onDuty: false);

        Livewire::actingAs($infirmier)->test(StaffCareTasks::class)->assertDontSee('Pansement');

        // On ajoute la garde : la liste se remplit, sans rien changer d'autre.
        Schedule::create([
            'user_id' => $infirmier->getKey(),
            'service_id' => $service->getKey(),
            'date' => today()->toDateString(),
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
        ]);

        Livewire::actingAs($infirmier)->test(StaffCareTasks::class)->assertSee('Pansement');
    }

    public function test_le_personnel_de_garde_marque_un_soin_comme_fait(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        $infirmier = $this->makeNurse($service);
        $task = CareTask::orderBy('scheduled_at')->firstOrFail();

        Livewire::actingAs($infirmier)
            ->test(StaffCareTasks::class)
            ->call('markDone', $task->getKey());

        $task->refresh();

        // La tracabilite vient de l'execution, pas d'une assignation prevue.
        $this->assertSame(CareTask::STATUS_DONE, $task->status);
        $this->assertSame($infirmier->getKey(), $task->completed_by_user_id);
        $this->assertNotNull($task->completed_at);

        // Et le soin rejoint la frise unifiee du dossier.
        $this->assertDatabaseHas('patient_history', [
            'visit_id' => $visit->getKey(),
            'type' => PatientHistory::TYPE_CARE_TASK_COMPLETED,
        ]);

        $this->assertNotNull(Activity::where('event', Audit::EVENT_CARE_TASK_COMPLETED)->first());
    }

    public function test_un_soin_ne_peut_pas_etre_marque_hors_garde(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        $infirmier = $this->makeNurse($service, onDuty: false);

        $this->expectException(InvalidArgumentException::class);

        app(CompleteCareTask::class)->execute(CareTask::firstOrFail(), $infirmier);
    }

    public function test_un_soin_en_retard_est_signale_sans_bascule_de_statut(): void
    {
        [, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->subHours(5),
            intervalHours: 12,
            durationDays: 1,
        );

        $task = CareTask::firstOrFail();

        // Signal visuel calcule a l'affichage : aucune tache de fond n'a
        // touche au statut.
        $this->assertTrue($task->isLate());
        $this->assertSame(CareTask::STATUS_PENDING, $task->status);
        $this->assertSame('En retard', $task->statusLabel());
    }

    public function test_l_assignation_nommee_reste_une_priorite_pas_une_restriction(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        $designe = $this->makeNurse($service);
        $collegue = $this->makeNurse($service);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Injection']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 24,
            durationDays: 1,
            assignedTo: $designe,
        );

        $task = CareTask::firstOrFail();
        $this->assertSame($designe->getKey(), $task->assigned_to_user_id);

        // Le collegue de garde voit la tache…
        Livewire::actingAs($collegue)
            ->test(StaffCareTasks::class)
            ->assertSee('Injection');

        // …et peut la prendre en charge : un soin ne reste pas bloque parce
        // que la personne designee est absente.
        app(CompleteCareTask::class)->execute($task, $collegue);

        $this->assertSame($collegue->getKey(), $task->refresh()->completed_by_user_id);
    }

    // ---------------------------------------------------------------- Sortie

    public function test_la_sortie_est_bloquee_tant_qu_un_soin_reste_en_attente(): void
    {
        [, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        try {
            app(DischargePatient::class)->execute($hospitalisation, $doctor);
            $this->fail('La sortie devait etre bloquee.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('soin', $e->getMessage());
        }

        $this->assertSame(Hospitalization::STATUS_ACTIVE, $hospitalisation->refresh()->status);
    }

    public function test_la_sortie_est_possible_une_fois_les_soins_traites(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        $infirmier = $this->makeNurse($service);

        foreach (CareTask::all() as $task) {
            app(CompleteCareTask::class)->execute($task, $infirmier);
        }

        app(DischargePatient::class)->execute($hospitalisation, $doctor);

        $hospitalisation->refresh();

        $this->assertSame(Hospitalization::STATUS_DISCHARGED, $hospitalisation->status);
        $this->assertNotNull($hospitalisation->discharged_at);
        $this->assertDatabaseHas('patient_history', [
            'visit_id' => $visit->getKey(),
            'type' => PatientHistory::TYPE_HOSPITALIZATION_DISCHARGED,
        ]);
    }

    public function test_un_soin_marque_manque_ne_bloque_plus_la_sortie(): void
    {
        [$service, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation,
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );

        $infirmier = $this->makeNurse($service);

        // Un soin manque reste visible dans le dossier : il n'est pas efface,
        // il est qualifie.
        foreach (CareTask::all() as $task) {
            app(CompleteCareTask::class)->markMissed($task, $infirmier);
        }

        app(DischargePatient::class)->execute($hospitalisation, $doctor);

        $this->assertSame(Hospitalization::STATUS_DISCHARGED, $hospitalisation->refresh()->status);
        // Toutes les 12 h pendant 1 jour : deux administrations, toutes deux
        // conservees avec leur statut « manque ».
        $this->assertSame(2, CareTask::where('status', CareTask::STATUS_MISSED)->count());
    }

    public function test_aucun_soin_ne_s_ajoute_a_une_hospitalisation_cloturee(): void
    {
        [, $doctor, $visit] = $this->makeAdmissionContext();
        $hospitalisation = app(AdmitPatient::class)->execute($visit, $doctor);

        app(DischargePatient::class)->execute($hospitalisation, $doctor);

        $this->expectException(InvalidArgumentException::class);

        app(PrescribeCareTasks::class)->execute(
            hospitalization: $hospitalisation->refresh(),
            type: CareTaskType::create(['name' => 'Serum']),
            doctor: $doctor,
            start: now()->addHour(),
            intervalHours: 12,
            durationDays: 1,
        );
    }
}
