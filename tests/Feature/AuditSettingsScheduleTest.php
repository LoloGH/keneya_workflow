<?php

namespace Tests\Feature;

use App\Livewire\Admin\ActivityLogViewer;
use App\Livewire\Admin\HospitalSettings;
use App\Livewire\Admin\ScheduleManager;
use App\Livewire\Admin\ServiceManager;
use App\Livewire\Shared\MySchedule;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Points 6, 7 et 8 de l'addendum v2 : nom de l'etablissement en base, journal
 * d'audit, plannings du personnel.
 */
class AuditSettingsScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    // ------------------------------------------------------- Etablissement

    public function test_le_nom_de_l_etablissement_vient_de_la_base(): void
    {
        // Sans valeur enregistree, on retombe sur la configuration.
        $this->assertSame(config('keneya.hospital'), hospital_name());

        Livewire::actingAs($this->makeAdmin())
            ->test(HospitalSettings::class)
            ->set('hospitalName', 'Centre de Sante de Reference de Kati')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Centre de Sante de Reference de Kati', hospital_name());
        $this->assertDatabaseHas('settings', ['key' => Setting::HOSPITAL_NAME]);
    }

    public function test_le_nom_de_l_etablissement_s_affiche_dans_la_barre_de_navigation(): void
    {
        Setting::put(Setting::HOSPITAL_NAME, 'Hopital de Segou');

        $this->actingAs($this->makeAdmin())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Hopital de Segou');
    }

    // ------------------------------------------------------------- Audit

    public function test_les_connexions_sont_journalisees(): void
    {
        $receptionist = $this->makeReceptionist();

        $this->post(route('login.store'), [
            'email' => $receptionist->email,
            'password' => 'motdepasse',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => Audit::LOG_NAME,
            'event' => Audit::EVENT_LOGIN,
            'causer_id' => $receptionist->getKey(),
        ]);
    }

    public function test_les_deconnexions_sont_journalisees(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('logout'));

        $this->assertDatabaseHas('activity_log', [
            'event' => Audit::EVENT_LOGOUT,
            'causer_id' => $admin->getKey(),
        ]);
    }

    public function test_les_actions_admin_sont_journalisees(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ServiceManager::class)
            ->set('name', 'Radiologie')
            ->set('service_kind_id', $this->serviceKind(ServiceKind::SLUG_PLATEAU_TECHNIQUE)->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('activity_log', [
            'event' => Audit::EVENT_SERVICE_CREATED,
            'causer_id' => $admin->getKey(),
        ]);
    }

    public function test_le_journal_est_filtrable_par_utilisateur_et_par_action(): void
    {
        $admin = $this->makeAdmin();

        Audit::log(Audit::EVENT_SERVICE_CREATED, 'Service Radiologie cree.');
        $this->actingAs($admin);
        Audit::log(Audit::EVENT_PATIENT_CREATED, 'Patient Sekou Diarra enregistre.');

        Livewire::actingAs($admin)
            ->test(ActivityLogViewer::class)
            ->assertSee('Service Radiologie cree.')
            ->assertSee('Patient Sekou Diarra enregistre.')
            ->set('event', Audit::EVENT_PATIENT_CREATED)
            ->assertSee('Patient Sekou Diarra enregistre.')
            ->assertDontSee('Service Radiologie cree.');
    }

    public function test_le_journal_est_en_lecture_seule(): void
    {
        $admin = $this->makeAdmin();
        Audit::log(Audit::EVENT_SERVICE_CREATED, 'Trace a conserver.');

        $component = Livewire::actingAs($admin)->test(ActivityLogViewer::class);

        // Aucune methode de modification n'est exposee par le composant.
        foreach (['delete', 'destroy', 'update', 'purge', 'edit'] as $method) {
            $this->assertFalse(
                method_exists($component->instance(), $method),
                "Le journal d'audit ne doit exposer aucune methode « {$method} ».",
            );
        }

        $this->assertSame(1, Activity::where('log_name', Audit::LOG_NAME)->count());
    }

    // --------------------------------------------------------- Plannings

    public function test_l_admin_cree_et_supprime_un_creneau(): void
    {
        $admin = $this->makeAdmin();
        $doctor = $this->makeDoctor(Service::factory()->create());

        Livewire::actingAs($admin)
            ->test(ScheduleManager::class)
            ->set('user_id', $doctor->user_id)
            ->set('date', today()->format('Y-m-d'))
            ->set('start_time', '08:00')
            ->set('end_time', '14:00')
            ->call('save')
            ->assertHasNoErrors();

        $schedule = Schedule::firstOrFail();
        $this->assertSame($doctor->user_id, $schedule->user_id);

        Livewire::actingAs($admin)
            ->test(ScheduleManager::class)
            ->call('delete', $schedule->getKey());

        $this->assertSame(0, Schedule::count());
    }

    public function test_une_fin_de_creneau_avant_son_debut_est_refusee(): void
    {
        $doctor = $this->makeDoctor(Service::factory()->create());

        Livewire::actingAs($this->makeAdmin())
            ->test(ScheduleManager::class)
            ->set('user_id', $doctor->user_id)
            ->set('date', today()->format('Y-m-d'))
            ->set('start_time', '14:00')
            ->set('end_time', '08:00')
            ->call('save')
            ->assertHasErrors('end_time');
    }

    /**
     * Aucun role ne voit le planning d'un autre : le filtre sur l'utilisateur
     * connecte est systematique.
     */
    public function test_chacun_ne_voit_que_son_propre_planning(): void
    {
        $service = Service::factory()->create();
        $mien = $this->makeDoctor($service);
        $collegue = $this->makeDoctor($service);

        Schedule::factory()->create([
            'user_id' => $mien->user_id,
            'service_id' => $service->getKey(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        Schedule::factory()->create([
            'user_id' => $collegue->user_id,
            'service_id' => $service->getKey(),
            'start_time' => '15:00',
            'end_time' => '19:00',
        ]);

        Livewire::actingAs($mien->user)
            ->test(MySchedule::class)
            ->assertSee('08:00 – 12:00', escape: false)
            ->assertDontSee('15:00 – 19:00');
    }

    public function test_la_receptionniste_voit_aussi_son_planning(): void
    {
        $receptionist = $this->makeReceptionist();

        Schedule::factory()->create([
            'user_id' => $receptionist->getKey(),
            'start_time' => '07:30',
            'end_time' => '13:30',
        ]);

        Livewire::actingAs($receptionist)
            ->test(MySchedule::class)
            ->assertSee('07:30 – 13:30', escape: false);
    }
}
