<?php

namespace Tests\Feature;

use App\Actions\SendPortalLink;
use App\Livewire\Portal\PatientPortal;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Patient;
use App\Models\PortalAccessAttempt;
use App\Models\Prescription;
use App\Models\Service;
use App\Services\SmsGateway;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Point 7 du v3.2 : portail patient, lien permanent et code a quatre chiffres.
 *
 * Le lien ne perime jamais — c'est la demande. La securite repose donc sur un
 * jeton non devinable et un verrouillage temporaire, et ces tests verifient
 * les deux plutot que de supposer qu'une seule couche suffit.
 */
class PatientPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_dossier_recoit_un_code_et_un_jeton_a_la_creation(): void
    {
        $patient = Patient::factory()->create();

        $this->assertMatchesRegularExpression('/^\d{4}$/', $patient->access_code);
        $this->assertTrue(Str::isUuid($patient->portal_token));
    }

    public function test_le_portail_ne_divulgue_rien_avant_le_code(): void
    {
        $patient = Patient::factory()->create(['name' => 'Fatoumata Sidibe']);

        $this->get(route('portal.show', $patient->portal_token))
            ->assertOk()
            ->assertSee("Code d'acces", escape: false)
            ->assertDontSee('Fatoumata Sidibe');
    }

    public function test_un_jeton_inconnu_renvoie_une_page_introuvable(): void
    {
        $this->get(route('portal.show', (string) Str::uuid()))->assertNotFound();
    }

    public function test_le_bon_code_ouvre_les_documents_et_les_rendez_vous(): void
    {
        $patient = Patient::factory()->create(['name' => 'Fatoumata Sidibe']);
        $service = Service::factory()->create(['name' => 'Cardiologie']);
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, [], $patient);

        Prescription::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'doctor_id' => $doctor->getKey(),
            'content' => 'Paracetamol 500 mg, trois fois par jour.',
        ]);

        Appointment::create([
            'patient_id' => $patient->getKey(),
            'service_id' => $service->getKey(),
            'doctor_id' => $doctor->getKey(),
            'scheduled_at' => now()->addWeek(),
            'status' => Appointment::STATUS_SCHEDULED,
        ]);

        Attachment::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'original_name' => 'echographie.pdf',
            'path' => (string) $patient->getKey().'/echographie.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'uploaded_by_user_id' => $doctor->user_id,
        ]);

        Livewire::test(PatientPortal::class, ['token' => $patient->portal_token])
            ->set('code', $patient->access_code)
            ->call('unlock')
            ->assertHasNoErrors()
            ->assertSee('Fatoumata Sidibe')
            ->assertSee('Paracetamol 500 mg, trois fois par jour.')
            ->assertSee('echographie.pdf')
            ->assertSee('Cardiologie');
    }

    public function test_un_code_incorrect_ne_montre_rien_et_decompte_les_tentatives(): void
    {
        $patient = Patient::factory()->create(['name' => 'Fatoumata Sidibe']);

        Livewire::test(PatientPortal::class, ['token' => $patient->portal_token])
            ->set('code', $this->wrongCodeFor($patient))
            ->call('unlock')
            ->assertDontSee('Fatoumata Sidibe')
            ->assertSee('Code incorrect');

        $this->assertSame(1, PortalAccessAttempt::where('patient_id', $patient->getKey())->value('failures'));
    }

    public function test_cinq_codes_errones_verrouillent_temporairement_l_acces(): void
    {
        $patient = Patient::factory()->create();
        $mauvais = $this->wrongCodeFor($patient);

        $composant = Livewire::test(PatientPortal::class, ['token' => $patient->portal_token]);

        for ($essai = 1; $essai <= PortalAccessAttempt::MAX_FAILURES; $essai++) {
            $composant->set('code', $mauvais)->call('unlock');
        }

        $tentative = PortalAccessAttempt::where('patient_id', $patient->getKey())->first();
        $this->assertTrue($tentative->isLocked(), 'Le dossier doit etre verrouille apres cinq echecs.');

        // Meme le bon code est refuse pendant le verrouillage.
        $composant->set('code', $patient->access_code)
            ->call('unlock')
            ->assertSee('Trop de tentatives');
    }

    public function test_le_verrouillage_est_temporaire_et_non_definitif(): void
    {
        $patient = Patient::factory()->create(['name' => 'Fatoumata Sidibe']);
        $mauvais = $this->wrongCodeFor($patient);

        $composant = Livewire::test(PatientPortal::class, ['token' => $patient->portal_token]);

        for ($essai = 1; $essai <= PortalAccessAttempt::MAX_FAILURES; $essai++) {
            $composant->set('code', $mauvais)->call('unlock');
        }

        // Un patient qui se trompe ne doit pas rester bloque devant ses
        // propres documents : le verrou tombe de lui-meme.
        $this->travel(PortalAccessAttempt::LOCK_MINUTES + 1)->minutes();

        Livewire::test(PatientPortal::class, ['token' => $patient->portal_token])
            ->set('code', $patient->access_code)
            ->call('unlock')
            ->assertSee('Fatoumata Sidibe');
    }

    public function test_le_telechargement_exige_le_code_valide(): void
    {
        $patient = Patient::factory()->create();
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, [], $patient);

        $piece = Attachment::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'original_name' => 'resultat.pdf',
            'path' => $patient->getKey().'/resultat.pdf',
            'mime_type' => 'application/pdf',
            'size' => 512,
            'uploaded_by_user_id' => $doctor->user_id,
        ]);

        $this->get(route('portal.attachment', [$patient->portal_token, $piece]))
            ->assertForbidden();
    }

    /**
     * Le PDF d'une ordonnance est aussi sensible que la piece jointe qu'il
     * accompagne : connaitre son identifiant ne doit pas suffire a la lire.
     */
    public function test_le_pdf_de_l_ordonnance_exige_le_code_valide(): void
    {
        $patient = Patient::factory()->create();
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, [], $patient);

        $ordonnance = Prescription::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'doctor_id' => $doctor->getKey(),
            'content' => 'Paracetamol',
        ]);

        $this->get(route('portal.prescription.pdf', [$patient->portal_token, $ordonnance]))
            ->assertForbidden();
    }

    public function test_l_ordonnance_d_un_autre_patient_reste_introuvable(): void
    {
        $patient = Patient::factory()->create();
        $autre = Patient::factory()->create();
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, [], $autre);

        $ordonnance = Prescription::create([
            'patient_id' => $autre->getKey(),
            'visit_id' => $visit->getKey(),
            'doctor_id' => $doctor->getKey(),
            'content' => 'Paracetamol',
        ]);

        $this->withSession(['portal.'.$patient->getKey() => true])
            ->get(route('portal.prescription.pdf', [$patient->portal_token, $ordonnance]))
            ->assertNotFound();
    }

    /**
     * Mise en page : chaque medicament apparait sur sa propre ligne, et le
     * lien de telechargement est propose au patient.
     */
    public function test_l_ordonnance_est_mise_en_page_et_telechargeable(): void
    {
        $patient = Patient::factory()->create();
        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $visit = $this->makeVisit($service, [], $patient);

        $ordonnance = Prescription::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'doctor_id' => $doctor->getKey(),
            'content' => "1. Paracetamol — 1/2 — 5\n2. Aspirine — 1/3 — 4",
        ]);

        Livewire::test(PatientPortal::class, ['token' => $patient->portal_token])
            ->set('code', $patient->access_code)
            ->call('unlock')
            ->assertHasNoErrors()
            ->assertSee('Paracetamol')
            ->assertSee('Aspirine')
            ->assertSee('prescription__medicament', escape: false)
            ->assertSee(route('portal.prescription.pdf', [$patient->portal_token, $ordonnance]), escape: false);
    }

    public function test_le_lien_est_envoye_par_sms_sur_demande(): void
    {
        $patient = Patient::factory()->create(['mobile' => '70112233']);
        $messages = new ArrayObject;

        $this->mock(SmsGateway::class)
            ->shouldReceive('send')
            ->andReturnUsing(function (string $numero, string $texte) use ($messages) {
                $messages[] = [$numero, $texte];

                return true;
            });

        app(SendPortalLink::class)->execute($patient);

        $this->assertCount(1, $messages);
        [$numero, $texte] = $messages[0];
        $this->assertSame('70112233', $numero);
        $this->assertStringContainsString($patient->portal_token, $texte);
        // Le code ne voyage jamais par SMS : il est remis a l'accueil.
        $this->assertStringNotContainsString($patient->access_code, $texte);
    }

    /** Un code a quatre chiffres different de celui du dossier. */
    private function wrongCodeFor(Patient $patient): string
    {
        return $patient->access_code === '0000' ? '1111' : '0000';
    }
}
