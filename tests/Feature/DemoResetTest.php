<?php

namespace Tests\Feature;

use App\Console\Commands\DemoReset;
use App\Models\Attachment;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reinitialisation de l'environnement de demonstration commerciale.
 *
 * Le VPS de demonstration ne contient que des donnees fictives ; ces tests
 * verifient qu'un reset ne laisse rien du prospect precedent, ni en base, ni
 * sur le disque, ni dans le nom de l'etablissement.
 */
class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_donnees_de_demonstration_sont_videes_et_les_seeders_relances(): void
    {
        Storage::fake('attachments');

        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $patient = Patient::factory()->create();
        $visit = $this->makeVisit($service, [], $patient);

        Prescription::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'doctor_id' => $doctor->getKey(),
            'content' => 'Paracetamol',
        ]);

        $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Visit::count());
        $this->assertSame(0, Prescription::count());

        // Les seeders ont bien ete relances : la demo est utilisable
        // immediatement apres, sans intervention manuelle.
        $this->assertTrue(Service::where('name', 'Urgences')->exists());
        $this->assertTrue(Service::where('name', Service::CAISSE_TICKET)->exists());
    }

    /**
     * Le point le plus sensible : le nom d'un etablissement teste la veille ne
     * doit pas s'afficher devant le prospect suivant. SettingSeeder utilise
     * firstOrCreate et ne corrigerait pas une valeur deja presente.
     */
    public function test_le_nom_d_un_etablissement_precedent_est_efface(): void
    {
        Storage::fake('attachments');

        Setting::put(Setting::HOSPITAL_NAME, 'Clinique du prospect precedent');

        $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(DemoReset::NOM_HOPITAL, Setting::get(Setting::HOSPITAL_NAME));
    }

    /**
     * Une piece jointe orpheline reste lisible par quiconque connait son
     * chemin : le fichier doit partir avec son enregistrement.
     */
    public function test_les_fichiers_des_pieces_jointes_sont_supprimes(): void
    {
        Storage::fake('attachments');

        $service = Service::factory()->create();
        $doctor = $this->makeDoctor($service);
        $patient = Patient::factory()->create();
        $visit = $this->makeVisit($service, [], $patient);

        Storage::disk('attachments')->put($patient->getKey().'/resultat.pdf', 'contenu fictif');

        Attachment::create([
            'patient_id' => $patient->getKey(),
            'visit_id' => $visit->getKey(),
            'original_name' => 'resultat.pdf',
            'path' => $patient->getKey().'/resultat.pdf',
            'mime_type' => 'application/pdf',
            'size' => 14,
            'uploaded_by_user_id' => $doctor->user_id,
        ]);

        $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

        Storage::disk('attachments')->assertMissing($patient->getKey().'/resultat.pdf');
        $this->assertSame(0, Attachment::count());
    }

    /**
     * L'option --complet efface aussi la configuration, puis la recree : c'est
     * le filet pour un service renomme au nom d'un autre hopital pendant une
     * demonstration.
     */
    public function test_l_option_complet_recree_les_services_du_seeder(): void
    {
        Storage::fake('attachments');

        Service::factory()->create(['name' => 'Service renomme pour un prospect']);

        $this->artisan('demo:reset', ['--complet' => true, '--force' => true])->assertSuccessful();

        $this->assertFalse(Service::where('name', 'Service renomme pour un prospect')->exists());
        $this->assertTrue(Service::where('name', 'Urgences')->exists());
    }

    /**
     * Exigence explicite : la commande ne doit jamais etre declenchable depuis
     * le web. Ce test echouera si une route venait a l'exposer.
     */
    public function test_aucune_route_http_n_expose_la_reinitialisation(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('demo', $route->uri());
        }
    }
}
