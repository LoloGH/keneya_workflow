<?php

namespace Tests\Feature;

use App\Actions\CompleteReferral;
use App\Actions\ConfirmCaissePayment;
use App\Actions\SendReferral;
use App\Livewire\Service\IncomingReferrals;
use App\Livewire\Service\ServiceQueue;
use App\Models\PatientHistory;
use App\Models\Referral;
use App\Models\Service;
use App\Models\Visit;
use App\Services\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Retour d'un renvoi complete (v3.2.1, correctif prioritaire).
 *
 * Le resultat s'affichait bien chez le prescripteur, mais le patient restait
 * dans la file du service destinataire — bloque la-bas indefiniment. Ces tests
 * verrouillent le retour effectif dans la file du prescripteur, et le fait
 * qu'il ne repasse jamais par la caisse.
 */
class ReferralReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(SmsGateway::class)->shouldReceive('send')->andReturnTrue();
    }

    /** Un aller complet : le medecin envoie, la visite atterrit au laboratoire. */
    private function makeAllerRetour(): array
    {
        [, $caisseServices] = $this->makeCaisses();

        $consultation = Service::factory()->create(['name' => 'Medecine Generale']);
        $laboratoire = Service::factory()->plateauTechnique()->create(['name' => 'Laboratoire']);

        $prescripteur = $this->makeDoctor($consultation, '70112233');
        $biologiste = $this->makeDoctor($laboratoire);

        $visit = $this->makeVisit($consultation, ['status' => Visit::STATUS_CALLED, 'token' => 3]);

        $referral = app(SendReferral::class)->execute($visit, $prescripteur, $laboratoire, 'Bilan sanguin');

        // L'aller passe bien par la caisse : c'est le comportement attendu.
        $visit->refresh();
        $this->assertSame($caisseServices->getKey(), $visit->service_id);
        $this->assertSame($laboratoire->getKey(), $visit->pending_next_service_id);

        // Paiement confirme : la visite rejoint le laboratoire.
        app(ConfirmCaissePayment::class)->execute($visit, $this->makeCashier(), 5000);

        return [$referral->refresh(), $visit->refresh(), $consultation, $laboratoire, $prescripteur, $biologiste];
    }

    public function test_le_patient_revient_dans_la_file_du_prescripteur(): void
    {
        [$referral, $visit, $consultation, $laboratoire, , $biologiste] = $this->makeAllerRetour();

        $this->assertSame($laboratoire->getKey(), $visit->service_id, 'Le patient doit d\'abord etre au laboratoire.');

        app(CompleteReferral::class)->execute($referral, $biologiste, 'Hemoglobine 11 g/dL.');

        $visit->refresh();

        // Le coeur du correctif : la visite quitte le laboratoire.
        $this->assertSame($consultation->getKey(), $visit->service_id);
        $this->assertSame(Visit::STATUS_WAITING, $visit->status);
        $this->assertNotNull($visit->token);
    }

    public function test_le_retour_ne_repasse_jamais_par_la_caisse(): void
    {
        [$referral, $visit, $consultation, , , $biologiste] = $this->makeAllerRetour();

        $encaissementsAvant = \DB::table('payments')->count();

        app(CompleteReferral::class)->execute($referral, $biologiste, 'Resultat normal.');

        $visit->refresh();

        // Ni file de caisse, ni destination en attente de paiement…
        $this->assertSame($consultation->getKey(), $visit->service_id);
        $this->assertNull($visit->pending_next_service_id);
        $this->assertFalse($visit->awaitsPayment());
        // …ni second encaissement.
        $this->assertSame($encaissementsAvant, \DB::table('payments')->count());
    }

    public function test_le_retour_donne_un_nouveau_ticket_dans_la_file_du_prescripteur(): void
    {
        [$referral, $visit, $consultation, , , $biologiste] = $this->makeAllerRetour();

        // Deux patients attendent deja chez le prescripteur.
        $this->makeVisit($consultation, ['token' => 1]);
        $this->makeVisit($consultation, ['token' => 2]);

        app(CompleteReferral::class)->execute($referral, $biologiste, 'Resultat normal.');

        $this->assertSame(3, $visit->refresh()->token, 'Le retour prend le ticket suivant de la file du prescripteur.');
    }

    public function test_le_patient_reapparait_dans_la_file_affichee_au_prescripteur(): void
    {
        [$referral, $visit, $consultation, , $prescripteur, $biologiste] = $this->makeAllerRetour();

        $nom = $visit->patient->name;

        Livewire::actingAs($prescripteur->user)
            ->test(ServiceQueue::class, ['serviceId' => $consultation->getKey()])
            ->assertDontSee($nom);

        app(CompleteReferral::class)->execute($referral, $biologiste, 'Resultat normal.');

        Livewire::actingAs($prescripteur->user)
            ->test(ServiceQueue::class, ['serviceId' => $consultation->getKey()])
            ->assertSee($nom);
    }

    public function test_le_retour_est_trace_dans_l_historique(): void
    {
        [$referral, $visit, , , , $biologiste] = $this->makeAllerRetour();

        app(CompleteReferral::class)->execute($referral, $biologiste, 'Hemoglobine 11 g/dL.');

        $entree = PatientHistory::where('visit_id', $visit->getKey())
            ->where('type', PatientHistory::TYPE_REFERRAL_RESULT)
            ->latest('id')
            ->first();

        $this->assertNotNull($entree);
        $this->assertStringContainsString('Retour en Medecine Generale', $entree->description);
    }

    public function test_le_resultat_saisi_depuis_l_interface_renvoie_aussi_le_patient(): void
    {
        [$referral, $visit, $consultation, $laboratoire, , $biologiste] = $this->makeAllerRetour();

        Livewire::actingAs($biologiste->user)
            ->test(IncomingReferrals::class, ['serviceId' => $laboratoire->getKey()])
            ->call('startAnswer', $referral->getKey())
            ->set('resultText', 'Hemoglobine 11 g/dL.')
            ->call('submitResult')
            ->assertHasNoErrors();

        $this->assertSame($consultation->getKey(), $visit->refresh()->service_id);
    }

    public function test_un_dossier_cloture_entre_temps_ne_revient_pas_en_file(): void
    {
        [$referral, $visit, , , , $biologiste] = $this->makeAllerRetour();

        $visit->update(['status' => Visit::STATUS_CLOSED, 'closed_at' => now()]);

        app(CompleteReferral::class)->execute($referral, $biologiste, 'Resultat tardif.');

        $visit->refresh();

        // Un resultat en retard ne doit pas ressusciter un dossier clos.
        $this->assertSame(Visit::STATUS_CLOSED, $visit->status);
        $this->assertSame(Referral::STATUS_DONE, $referral->refresh()->status);
    }

    public function test_un_renvoi_entre_services_cliniques_revient_aussi(): void
    {
        $this->makeCaisses();

        $urgences = Service::factory()->create(['name' => 'Urgences']);
        $cardiologie = Service::factory()->create(['name' => 'Cardiologie']);

        $prescripteur = $this->makeDoctor($urgences);
        $cardiologue = $this->makeDoctor($cardiologie);

        $visit = $this->makeVisit($urgences, ['status' => Visit::STATUS_CALLED]);
        $referral = app(SendReferral::class)->execute($visit, $prescripteur, $cardiologie, 'Avis specialise');

        // Aller direct : le type « Clinique » ne porte pas de peage.
        $this->assertSame($cardiologie->getKey(), $visit->refresh()->service_id);

        app(CompleteReferral::class)->execute($referral->refresh(), $cardiologue, 'Echographie cardiaque normale.');

        $this->assertSame($urgences->getKey(), $visit->refresh()->service_id);
    }
}
