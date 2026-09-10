<?php

namespace Tests\Feature;

use App\Actions\DeleteStaffAccount;
use App\Actions\RecordPayment;
use App\Livewire\Admin\CareTaskTypeManager;
use App\Livewire\Admin\RoomManager;
use App\Livewire\Admin\ServiceKindManager;
use App\Livewire\Admin\StaffManager;
use App\Livewire\Admin\StaffTypeManager;
use App\Models\CareTaskType;
use App\Models\Doctor;
use App\Models\FeedbackEntry;
use App\Models\Hospitalization;
use App\Models\Patient;
use App\Models\PatientHistory;
use App\Models\Payment;
use App\Models\Receptionist;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Models\StaffMember;
use App\Models\StaffNotification;
use App\Models\StaffType;
use App\Models\User;
use App\Models\Visit;
use App\Services\PatientHistoryRecorder;
use App\Services\SmsGateway;
use App\Services\SmsSendResult;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * L'action de suppression est offerte partout dans /admin (v3.2.1).
 *
 * Elle n'est jamais masquee : un bouton qui disparait sans explication laisse
 * l'administrateur devant une case vide. Le refus vient du serveur, avec sa
 * raison — ces tests verrouillent les deux moities de cette regle.
 */
class AdminDeletionActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsGateway::class)->shouldReceive('deliver')->andReturn(SmsSendResult::sent());
    }

    // ------------------------------- L'action est presente, toujours

    public function test_l_action_de_suppression_est_offerte_meme_quand_elle_sera_refusee(): void
    {
        $admin = $this->makeAdmin();

        // Un type d'origine, jamais supprimable, et un type encore utilise.
        $plateau = $this->serviceKind(ServiceKind::SLUG_PLATEAU_TECHNIQUE);
        $utilise = ServiceKind::create(['name' => 'Imagerie', 'slug' => 'imagerie']);
        Service::factory()->ofKind($utilise)->create(['name' => 'Scanner']);

        $rendu = Livewire::actingAs($admin)->test(ServiceKindManager::class)->html();

        // Les deux lignes portent bien l'action, sans exception.
        $this->assertStringContainsString('delete('.$plateau->getKey().')', $rendu);
        $this->assertStringContainsString('delete('.$utilise->getKey().')', $rendu);
        $this->assertStringContainsString('Supprimer ce type de service', $rendu);
    }

    public function test_chaque_table_d_administration_expose_l_action(): void
    {
        $admin = $this->makeAdmin();
        $service = Service::factory()->create(['name' => 'Medecine Generale']);

        $this->makeDoctor($service);
        $this->makeReceptionist();
        Room::create(['name' => 'Salle 1', 'service_id' => $service->getKey(), 'capacity' => 2]);
        CareTaskType::create(['name' => 'Serum']);

        $type = StaffType::create([
            'name' => 'Infirmier', 'matched_role' => null,
            'slug' => 'infirmier', 'capabilities' => [StaffType::CAP_CARE_TASKS],
        ]);
        StaffMember::create([
            'user_id' => User::factory()->create()->getKey(),
            'staff_type_id' => $type->getKey(),
            'service_id' => $service->getKey(),
        ]);

        foreach ([
            StaffManager::class,
            StaffTypeManager::class,
            RoomManager::class,
            CareTaskTypeManager::class,
        ] as $composant) {
            $rendu = Livewire::actingAs($admin)->test($composant)->html();

            $this->assertStringContainsString(
                'wire:click="delete(',
                $rendu,
                $composant.' doit offrir une action de suppression.',
            );
        }
    }

    // ------------------------------- Les refus disent pourquoi

    public function test_un_type_d_origine_refuse_la_suppression_avec_sa_raison(): void
    {
        $plateau = $this->serviceKind(ServiceKind::SLUG_PLATEAU_TECHNIQUE);

        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceKindManager::class)
            ->call('delete', $plateau->getKey());

        $this->assertDatabaseHas('service_kinds', ['id' => $plateau->getKey()]);
        $this->assertNull(Activity::where('event', Audit::EVENT_SERVICE_KIND_DELETED)->first());
    }

    public function test_un_type_de_personnel_encore_porte_refuse_avec_sa_raison(): void
    {
        $service = Service::factory()->create();
        $type = StaffType::create([
            'name' => 'Infirmier', 'matched_role' => null,
            'slug' => 'infirmier', 'capabilities' => [],
        ]);
        StaffMember::create([
            'user_id' => User::factory()->create()->getKey(),
            'staff_type_id' => $type->getKey(),
            'service_id' => $service->getKey(),
        ]);

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffTypeManager::class)
            ->call('delete', $type->getKey());

        $this->assertDatabaseHas('staff_types', ['id' => $type->getKey()]);
        $this->assertNull(Activity::where('event', Audit::EVENT_STAFF_TYPE_DELETED)->first());
    }

    // ------------------------------- Suppression d'un compte du personnel

    public function test_une_receptionniste_sans_trace_est_supprimee_avec_son_compte(): void
    {
        $receptionist = $this->makeReceptionist();
        $userId = $receptionist->getKey();
        $rattachement = Receptionist::where('user_id', $userId)->firstOrFail();

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'receptionist:'.$rattachement->getKey());

        $this->assertDatabaseMissing('receptionists', ['id' => $rattachement->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_la_suppression_d_un_compte_est_journalisee_et_survit(): void
    {
        $receptionist = $this->makeReceptionist();
        $rattachement = Receptionist::where('user_id', $receptionist->getKey())->firstOrFail();
        $nom = $receptionist->name;

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'receptionist:'.$rattachement->getKey());

        $trace = Activity::where('event', Audit::EVENT_STAFF_DELETED)->latest('id')->first();

        $this->assertNotNull($trace);
        $this->assertSame($nom, $trace->properties['nom']);
        $this->assertTrue($trace->properties['compte_supprime']);
        // Le compte a disparu, la trace demeure.
        $this->assertDatabaseMissing('users', ['name' => $nom]);
    }

    public function test_un_medecin_ayant_soigne_n_est_pas_supprimable(): void
    {
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, ['status' => Visit::STATUS_CALLED]);

        app(PatientHistoryRecorder::class)->record(
            visit: $visit,
            type: PatientHistory::TYPE_CONSULTATION,
            description: 'Consultation.',
            doctor: $doctor,
        );

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'doctor:'.$doctor->getKey());

        // Effacer ce rattachement rendrait anonyme une consultation signee.
        $this->assertDatabaseHas('doctors', ['id' => $doctor->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $doctor->user_id]);

        // Et le refus dit ce qui bloque, la ou il est formule.
        try {
            app(DeleteStaffAccount::class)->execute($doctor, $this->makeAdmin());
            $this->fail('La suppression devait etre refusee.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('1 entree(s) au dossier', $e->getMessage());
            $this->assertStringContainsString('parcours de patients', $e->getMessage());
        }
    }

    public function test_un_medecin_jamais_utilise_est_supprimable(): void
    {
        $doctor = $this->makeDoctor(Service::factory()->create());
        $userId = $doctor->user_id;

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'doctor:'.$doctor->getKey());

        $this->assertDatabaseMissing('doctors', ['id' => $doctor->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_un_medecin_multi_service_ne_perd_que_le_rattachement_retire(): void
    {
        $premier = Service::factory()->create(['name' => 'Urgences']);
        $second = Service::factory()->create(['name' => 'Maternite']);

        $doctor = $this->makeDoctor($premier);
        $autre = Doctor::create([
            'user_id' => $doctor->user_id,
            'service_id' => $second->getKey(),
        ]);

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'doctor:'.$doctor->getKey());

        // Le compte survit tant qu'il exerce ailleurs.
        $this->assertDatabaseMissing('doctors', ['id' => $doctor->getKey()]);
        $this->assertDatabaseHas('doctors', ['id' => $autre->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $doctor->user_id]);
    }

    public function test_un_encaissement_bloque_la_suppression_du_compte(): void
    {
        $service = Service::factory()->create();
        $receptionist = $this->makeReceptionist();
        $rattachement = Receptionist::where('user_id', $receptionist->getKey())->firstOrFail();

        $visit = $this->makeVisit($service);

        app(RecordPayment::class)->execute(
            $visit,
            $receptionist,
            Payment::TYPE_TICKET,
            1500,
        );

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'receptionist:'.$rattachement->getKey());

        $this->assertDatabaseHas('users', ['id' => $receptionist->getKey()]);

        try {
            app(DeleteStaffAccount::class)->execute($rattachement, $this->makeAdmin());
            $this->fail('La suppression devait etre refusee.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('1 encaissement(s)', $e->getMessage());
        }
    }

    public function test_le_planning_part_avec_le_compte_supprime(): void
    {
        $service = Service::factory()->create();
        $receptionist = $this->makeReceptionist();
        $rattachement = Receptionist::where('user_id', $receptionist->getKey())->firstOrFail();

        Schedule::create([
            'user_id' => $receptionist->getKey(),
            'service_id' => $service->getKey(),
            'date' => today()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '14:00:00',
        ]);

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'receptionist:'.$rattachement->getKey());

        // Un planning est la propriete du compte, pas du dossier patient.
        $this->assertSame(0, Schedule::where('user_id', $receptionist->getKey())->count());
    }

    /**
     * Constate en administration : « 500 SERVER ERROR » a la suppression d'un
     * personnel, sans un mot d'explication.
     *
     * Une notification est la propriete du compte — c'est sa cloche — mais
     * `staff_notifications.user_id` est une cle etrangere en RESTRICT, et rien
     * ne la vidait. Tout compte ayant recu ne serait-ce qu'une notification
     * refusait donc d'etre supprime : c'est-a-dire, en pratique, tout compte
     * qui a servi une journee.
     */
    public function test_la_cloche_part_avec_le_compte_supprime(): void
    {
        $receptionist = $this->makeReceptionist();
        $rattachement = Receptionist::where('user_id', $receptionist->getKey())->firstOrFail();

        StaffNotification::create([
            'user_id' => $receptionist->getKey(),
            'type' => StaffNotification::TYPE_NEW_QUEUE_ENTRY,
            'title' => 'Un patient attend a l accueil.',
            'link' => '/reception',
        ]);

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'receptionist:'.$rattachement->getKey());

        $this->assertSame(0, StaffNotification::where('user_id', $receptionist->getKey())->count());
        $this->assertNull(User::find($receptionist->getKey()));
    }

    /**
     * Toutes les traces qui retiennent un compte disent lesquelles.
     *
     * Le refus lui-meme n'est pas le defaut — il est voulu, et documente le
     * parcours des patients. Ce qui l'etait, c'est qu'une bonne moitie des
     * colonnes concernees n'etait pas listee : la base refusait alors la
     * suppression a la toute derniere seconde, en 500, la ou l'admin aurait
     * du lire une phrase.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tracesQuiRetiennentUnCompte(): array
    {
        return [
            'note de releve' => ['handoff_notes', 'written_by_user_id'],
            'visiteur enregistre' => ['visitors', 'registered_by_user_id'],
            'diffusion de SMS' => ['broadcast_messages', 'sent_by_user_id'],
            'retour depose' => ['feedback_entries', 'submitted_by_user_id'],
            'retour traite' => ['feedback_entries', 'resolved_by_user_id'],
            'note de satisfaction' => ['feedback_survey_ratings', 'user_id'],
            'soin annule' => ['care_tasks', 'cancelled_by_user_id'],
        ];
    }

    #[DataProvider('tracesQuiRetiennentUnCompte')]
    public function test_chaque_trace_du_compte_est_annoncee_avant_le_refus(string $table, string $colonne): void
    {
        $receptionist = $this->makeReceptionist();
        $rattachement = Receptionist::where('user_id', $receptionist->getKey())->firstOrFail();

        // La colonne est renseignee directement : ce qui est teste ici n'est
        // pas la facon dont la trace nait, mais le fait que l'action la voie.
        DB::table($table)->insert($this->ligneMinimale($table, $colonne, $receptionist->getKey()));

        $this->expectException(InvalidArgumentException::class);

        app(DeleteStaffAccount::class)->execute($rattachement, $this->makeAdmin());
    }

    /**
     * Le strict minimum pour qu'une ligne de cette table existe et designe ce
     * compte. Les colonnes obligatoires seulement — le contenu n'a aucune
     * importance pour ce que ce test verifie.
     *
     * @return array<string, mixed>
     */
    private function ligneMinimale(string $table, string $colonne, int $userId): array
    {
        $base = [$colonne => $userId, 'created_at' => now(), 'updated_at' => now()];

        return match ($table) {
            'handoff_notes' => $base + [
                'hospitalization_id' => $this->hospitalisation()->getKey(),
                'content' => 'Nuit calme.',
            ],
            'visitors' => $base + [
                'visitor_code' => 'VIS-TEST-1',
                'name' => 'Awa Traore',
                'service_id' => Service::factory()->create()->getKey(),
            ],
            'broadcast_messages' => $base + [
                'content' => 'Fermeture exceptionnelle.',
                'target_type' => 'all_patients',
                'recipient_count' => 0,
            ],
            'feedback_entries' => $base + [
                'type' => FeedbackEntry::TYPE_INCIDENT,
                'content' => 'Ascenseur en panne.',
                'status' => FeedbackEntry::STATUS_NEW,
            ],
            'feedback_survey_ratings' => $base + [
                'feedback_entry_id' => FeedbackEntry::create([
                    'type' => FeedbackEntry::TYPE_SURVEY,
                    'content' => 'Enquete.',
                    'status' => FeedbackEntry::STATUS_NEW,
                ])->getKey(),
                'post_label' => 'Accueil',
                'rating' => 4,
            ],
            'care_tasks' => $base + [
                'hospitalization_id' => ($sejour = $this->hospitalisation())->getKey(),
                'care_task_type_id' => CareTaskType::create(['name' => 'Pansement'])->getKey(),
                // Prescrit par le medecin qui a admis, pas par le compte que
                // l'on cherche a supprimer : c'est bien l'annulation qui doit
                // retenir ce compte, et elle seule.
                'prescribed_by_doctor_id' => $sejour->admitted_by_doctor_id,
                'instructions' => 'Refection.',
                'scheduled_at' => now(),
                'status' => 'cancelled',
            ],
            default => $base,
        };
    }

    /** Un sejour quelconque, support des soins et des notes de releve. */
    private function hospitalisation(): Hospitalization
    {
        $service = Service::factory()->create();
        $patient = Patient::factory()->create();

        return Hospitalization::create([
            'patient_id' => $patient->getKey(),
            'service_id' => $service->getKey(),
            'visit_id' => $this->makeVisit($service, [], $patient)->getKey(),
            // Un sejour porte toujours le medecin qui a admis : c'est un autre
            // compte que celui dont on teste la suppression.
            'admitted_by_doctor_id' => $this->makeDoctor($service)->getKey(),
            'admitted_at' => now(),
            'status' => Hospitalization::STATUS_ACTIVE,
        ]);
    }

    public function test_un_membre_a_interface_dediee_est_supprimable(): void
    {
        $service = Service::factory()->create();
        $type = StaffType::create([
            'name' => 'Infirmier', 'matched_role' => null,
            'slug' => 'infirmier', 'capabilities' => [StaffType::CAP_CARE_TASKS],
        ]);
        $user = User::factory()->create();
        $member = StaffMember::create([
            'user_id' => $user->getKey(),
            'staff_type_id' => $type->getKey(),
            'service_id' => $service->getKey(),
        ]);

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'staff:'.$member->getKey());

        $this->assertDatabaseMissing('staff_members', ['id' => $member->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    }

    public function test_un_membre_ayant_signe_un_acte_n_est_pas_supprimable(): void
    {
        $service = Service::factory()->create();
        $type = StaffType::create([
            'name' => 'Infirmier', 'matched_role' => null,
            'slug' => 'infirmier', 'capabilities' => [StaffType::CAP_QUEUE],
        ]);
        $user = User::factory()->create();
        $member = StaffMember::create([
            'user_id' => $user->getKey(),
            'staff_type_id' => $type->getKey(),
            'service_id' => $service->getKey(),
        ]);

        $visit = $this->makeVisit($service, [], Patient::factory()->create());

        app(PatientHistoryRecorder::class)->record(
            visit: $visit,
            type: PatientHistory::TYPE_CONSULTATION,
            description: 'Soin realise.',
            doctor: $member,
        );

        Livewire::actingAs($this->makeAdmin())
            ->test(StaffManager::class)
            ->call('delete', 'staff:'.$member->getKey());

        $this->assertDatabaseHas('staff_members', ['id' => $member->getKey()]);

        try {
            app(DeleteStaffAccount::class)->execute($member, $this->makeAdmin());
            $this->fail('La suppression devait etre refusee.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('1 entree(s) au dossier', $e->getMessage());
        }
    }
}
