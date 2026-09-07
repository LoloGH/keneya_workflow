<?php

namespace Tests\Feature;

use App\Actions\DeletePatientRecord;
use App\Livewire\Admin\PatientDeletion;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\CareTask;
use App\Models\CareTaskType;
use App\Models\Companion;
use App\Models\FeedbackEntry;
use App\Models\HandoffNote;
use App\Models\Hospitalization;
use App\Models\Patient;
use App\Models\PatientHistory;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Referral;
use App\Models\Service;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\PatientHistoryRecorder;
use App\Support\Audit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Point 8 du v3.2 : suppression definitive d'un dossier par l'admin.
 *
 * Le point sensible tenu par ces tests : tout part, sauf la trace d'audit.
 */
class PatientDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** Un dossier complet : passage, renvoi, historique, documents, argent. */
    private function makeFullRecord(): array
    {
        $depart = Service::factory()->create(['name' => 'Medecine generale']);
        $laboratoire = Service::factory()->plateauTechnique()->create(['name' => 'Laboratoire']);

        $doctor = $this->makeDoctor($depart);
        $patient = Patient::factory()->create(['name' => 'Moussa Keita']);
        $visit = $this->makeVisit($depart, ['status' => Visit::STATUS_CALLED], $patient);

        app(PatientHistoryRecorder::class)->record(
            visit: $visit,
            type: PatientHistory::TYPE_CONSULTATION,
            description: 'Consultation initiale.',
            doctor: $doctor,
        );

        $referral = Referral::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'from_service_id' => $depart->getKey(),
            'to_service_id' => $laboratoire->getKey(),
            'from_doctor_id' => $doctor->getKey(),
            'instructions' => 'Bilan sanguin',
            'status' => Referral::STATUS_PENDING,
        ]);

        Attachment::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'referral_id' => $referral->getKey(),
            'original_name' => 'bilan.pdf',
            'path' => $patient->getKey().'/bilan.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'uploaded_by_user_id' => $doctor->user_id,
        ]);

        Prescription::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'doctor_id' => $doctor->getKey(),
            'content' => 'Amoxicilline 1 g.',
        ]);

        Payment::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'type' => Payment::TYPE_TICKET,
            'service_id' => $depart->getKey(),
            'amount' => 1500,
            'status' => Payment::STATUS_PAID,
            'recorded_by_user_id' => $doctor->user_id,
        ]);

        Appointment::create([
            'patient_id' => $patient->getKey(),
            'service_id' => $depart->getKey(),
            'doctor_id' => $doctor->getKey(),
            'scheduled_at' => now()->addWeek(),
            'status' => Appointment::STATUS_SCHEDULED,
        ]);

        Companion::create([
            'patient_id' => $patient->getKey(),
            'name' => 'Awa Keita',
            'relation' => 'Soeur',
        ]);

        // La ligne d'historique qui designe le renvoi. Elle manquait a ce
        // jeu d'essai, et c'est precisement elle qui faisait echouer la
        // suppression sur le serveur : `patient_history.referral_id` est une
        // cle etrangere en RESTRICT, or les renvois partaient avant elle. Un
        // dossier sans renvoi trace se supprimait, un dossier reellement
        // utilise non — et la suite ne voyait rien.
        app(PatientHistoryRecorder::class)->record(
            visit: $visit,
            type: PatientHistory::TYPE_REFERRAL_SENT,
            description: 'Renvoi vers le laboratoire.',
            doctor: $doctor,
            referral: $referral,
        );

        // Un sejour, son soin programme et sa note de releve : trois tables
        // que la suppression ne touchait pas du tout. Un patient ayant ete
        // hospitalise ne pouvait donc pas etre supprime.
        $hospitalisation = Hospitalization::create([
            'patient_id' => $patient->getKey(),
            'service_id' => $depart->getKey(),
            'visit_id' => $visit->getKey(),
            'admitted_by_doctor_id' => $doctor->getKey(),
            'admitted_at' => now()->subDays(3),
            'status' => Hospitalization::STATUS_DISCHARGED,
            'discharged_at' => now()->subDay(),
        ]);

        CareTask::create([
            'hospitalization_id' => $hospitalisation->getKey(),
            'care_task_type_id' => CareTaskType::create(['name' => 'Pansement'])->getKey(),
            'instructions' => 'Refection du pansement.',
            'prescribed_by_doctor_id' => $doctor->getKey(),
            'scheduled_at' => now()->subDays(2),
            'status' => CareTask::STATUS_DONE,
        ]);

        HandoffNote::create([
            'hospitalization_id' => $hospitalisation->getKey(),
            'written_by_user_id' => $doctor->user_id,
            'content' => 'Nuit calme.',
        ]);

        // Un retour depose par le patient : `feedback_entries.patient_id` est
        // en RESTRICT lui aussi.
        FeedbackEntry::create([
            'type' => FeedbackEntry::TYPE_COMPLAINT,
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'service_id' => $depart->getKey(),
            'content' => 'Attente trop longue.',
            'status' => FeedbackEntry::STATUS_NEW,
        ]);

        return [$patient, $depart, $doctor];
    }

    // ------------------------------- Le disque ne revient pas en arriere

    /**
     * Constate sur le serveur de demonstration : un dossier intact en base,
     * une piece jointe qui s'y trouvait toujours, et son fichier detruit.
     *
     * La cause etait l'ordre. Les fichiers partaient a l'interieur de la
     * transaction ; celle-ci echouant plus loin, la base revenait en arriere
     * et le disque, lui, ne le pouvait pas. L'admin voyait une erreur, en
     * concluait que rien n'avait bouge, et le dossier avait perdu ses
     * documents — definitivement, et sans que rien ne le signale.
     */
    public function test_une_suppression_qui_echoue_ne_detruit_aucun_fichier(): void
    {
        Storage::fake('attachments');

        [$patient] = $this->makeFullRecord();
        $admin = $this->makeAdmin();
        $chemin = $patient->attachments->first()->path;

        Storage::disk('attachments')->put($chemin, 'Le bilan sanguin du patient.');

        // On fait echouer la transaction a mi-parcours, apres l'etape ou les
        // fichiers etaient supprimes auparavant.
        Event::listen(QueryExecuted::class, function (QueryExecuted $requete): void {
            if (str_contains(strtolower($requete->sql), 'delete from "visits"')
                || str_contains(strtolower($requete->sql), 'delete from `visits`')) {
                throw new RuntimeException('Panne au milieu de la suppression.');
            }
        });

        try {
            app(DeletePatientRecord::class)->execute($patient, $admin, $patient->patient_code, 'Doublon.');
            $this->fail('La suppression aurait du echouer.');
        } catch (RuntimeException) {
            // Attendu.
        }

        // La base est revenue en arriere...
        $this->assertDatabaseHas('patients', ['id' => $patient->getKey()]);
        $this->assertDatabaseHas('attachments', ['patient_id' => $patient->getKey()]);

        // ...et le fichier doit l'avoir suivie. C'est tout l'objet du test :
        // un dossier qui survit sans ses documents est pire qu'un echec franc.
        Storage::disk('attachments')->assertExists($chemin);
    }

    public function test_une_suppression_reussie_emporte_aussi_les_fichiers(): void
    {
        Storage::fake('attachments');

        [$patient] = $this->makeFullRecord();
        $admin = $this->makeAdmin();
        $chemin = $patient->attachments->first()->path;

        Storage::disk('attachments')->put($chemin, 'Le bilan sanguin du patient.');

        app(DeletePatientRecord::class)->execute($patient, $admin, $patient->patient_code, 'Doublon.');

        // Reculer l'effacement apres la transaction ne doit pas l'annuler :
        // un document medical ne reste pas sur le disque apres son dossier.
        Storage::disk('attachments')->assertMissing($chemin);
    }

    public function test_la_suppression_emporte_tout_le_dossier(): void
    {
        [$patient] = $this->makeFullRecord();
        $admin = $this->makeAdmin();
        $code = $patient->patient_code;

        $this->actingAs($admin);

        app(DeletePatientRecord::class)->execute($patient, $admin, $code, 'Doublon avec un autre dossier.');

        $this->assertDatabaseMissing('patients', ['patient_code' => $code]);

        foreach (['visits', 'referrals', 'patient_history', 'attachments', 'payments', 'prescriptions', 'appointments', 'companions'] as $table) {
            $this->assertSame(0, \DB::table($table)->count(), "La table {$table} devait etre videe.");
        }
    }

    public function test_l_entree_du_journal_d_audit_survit_a_la_suppression(): void
    {
        [$patient] = $this->makeFullRecord();
        $admin = $this->makeAdmin();
        $code = $patient->patient_code;

        $this->actingAs($admin);

        app(DeletePatientRecord::class)->execute($patient, $admin, $code, 'Demande du patient.');

        $trace = Activity::where('event', Audit::EVENT_PATIENT_DELETED)->latest('id')->first();

        $this->assertNotNull($trace, 'La suppression doit laisser une trace d\'audit.');
        // Le patient n'existe plus, mais l'audit conserve de quoi le designer.
        $this->assertSame($code, $trace->properties['patient_code']);
        $this->assertSame('Moussa Keita', $trace->properties['nom']);
        $this->assertSame('Demande du patient.', $trace->properties['motif']);
        $this->assertSame($admin->getKey(), $trace->causer_id);
        $this->assertStringContainsString($code, $trace->description);
        // Aucune cle etrangere vers le patient : rien ne peut l'emporter.
        $this->assertNull($trace->subject_id);
    }

    public function test_la_suppression_n_est_jamais_tracee_dans_l_historique_patient(): void
    {
        [$patient] = $this->makeFullRecord();
        $admin = $this->makeAdmin();

        $this->actingAs($admin);
        app(DeletePatientRecord::class)->execute($patient, $admin, $patient->patient_code, 'Doublon.');

        $this->assertSame(0, PatientHistory::count());
    }

    public function test_un_visiteur_est_detache_et_non_supprime(): void
    {
        [$patient, $service] = $this->makeFullRecord();
        $admin = $this->makeAdmin();

        $visiteur = Visitor::factory()->create([
            'service_id' => $service->getKey(),
            'patient_id' => $patient->getKey(),
        ]);

        $this->actingAs($admin);
        app(DeletePatientRecord::class)->execute($patient, $admin, $patient->patient_code, 'Doublon.');

        // La fiche du visiteur documente une entree dans l'hopital : elle n'est
        // pas une donnee du dossier medical.
        $this->assertDatabaseHas('visitors', ['id' => $visiteur->getKey(), 'patient_id' => null]);
    }

    public function test_un_numero_de_dossier_mal_retape_bloque_la_suppression(): void
    {
        [$patient] = $this->makeFullRecord();
        $admin = $this->makeAdmin();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(DeletePatientRecord::class)->execute($patient, $admin, 'PAT-INEXISTANT', 'Doublon.');
        } finally {
            $this->assertDatabaseHas('patients', ['id' => $patient->getKey()]);
        }
    }

    public function test_un_motif_vide_bloque_la_suppression(): void
    {
        [$patient] = $this->makeFullRecord();
        $admin = $this->makeAdmin();

        $this->expectException(InvalidArgumentException::class);

        app(DeletePatientRecord::class)->execute($patient, $admin, $patient->patient_code, '   ');
    }

    public function test_l_admin_supprime_depuis_son_interface_en_deux_etapes(): void
    {
        [$patient] = $this->makeFullRecord();
        $code = $patient->patient_code;

        Livewire::actingAs($this->makeAdmin())
            ->test(PatientDeletion::class)
            ->set('search', $code)
            ->call('select', $patient->getKey())
            // Retaper approximativement ne suffit pas.
            ->set('confirmation', mb_strtolower($code))
            ->set('reason', 'Doublon avec un autre dossier.')
            ->call('delete')
            ->assertHasErrors('confirmation');

        $this->assertDatabaseHas('patients', ['id' => $patient->getKey()]);

        Livewire::actingAs($this->makeAdmin())
            ->test(PatientDeletion::class)
            ->call('select', $patient->getKey())
            ->set('confirmation', $code)
            ->set('reason', 'Doublon avec un autre dossier.')
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('patients', ['id' => $patient->getKey()]);
    }

    public function test_la_suppression_n_est_offerte_a_aucun_autre_role(): void
    {
        $service = Service::factory()->create();

        // La section n'existe que dans /admin : ni /reception, ni /service, ni
        // /caisse ne l'affichent, et le composant reste inatteignable ailleurs.
        $this->actingAs($this->makeReceptionist())
            ->get('/reception')
            ->assertDontSee('Supprimer un dossier');

        $this->actingAs($this->makeDoctor($service)->user)
            ->get('/service')
            ->assertDontSee('Supprimer un dossier');

        $this->makeCaisses();
        $this->actingAs($this->makeCashier())
            ->get('/caisse')
            ->assertDontSee('Supprimer un dossier');
    }

    public function test_le_composant_de_suppression_refuse_un_role_non_admin(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/admin')
            ->assertSee('Supprimer un dossier');

        // Garde-fou explicite : meme atteint directement, le composant refuse.
        Livewire::actingAs($this->makeReceptionist())
            ->test(PatientDeletion::class)
            ->assertStatus(403);
    }
}
