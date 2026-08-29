<?php

namespace Tests\Feature;

use App\Livewire\Admin\DoctorManager;
use App\Livewire\Admin\PatientDirectory;
use App\Livewire\Admin\ReceptionistManager;
use App\Livewire\Admin\ServiceManager;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Receptionist;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_l_admin_cree_et_renomme_un_service(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ServiceManager::class)
            ->set('name', 'Radiologie')
            ->set('service_kind_id', $this->serviceKind(ServiceKind::SLUG_PLATEAU_TECHNIQUE)->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $service = Service::where('name', 'Radiologie')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ServiceManager::class)
            ->call('edit', $service->getKey())
            ->assertSet('service_kind_id', $this->serviceKind(ServiceKind::SLUG_PLATEAU_TECHNIQUE)->getKey())
            ->set('name', 'Imagerie medicale')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Imagerie medicale', $service->refresh()->name);
    }

    public function test_l_admin_cree_un_medecin_avec_son_compte_et_son_role(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(DoctorManager::class)
            ->set('name', 'Dr Modibo Keita')
            ->set('email', 'modibo@keneya.test')
            ->set('password', 'motdepasse')
            ->set('phone', '76000001')
            ->set('service_id', Service::factory()->create()->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $doctor = Doctor::with('user')->firstOrFail();

        $this->assertSame('Dr Modibo Keita', $doctor->user->name);
        $this->assertTrue($doctor->user->hasRole(Roles::DOCTOR));
        $this->assertSame(route('service.home'), route($doctor->user->homeRoute()));
    }

    public function test_l_admin_reaffecte_un_medecin_a_un_autre_service(): void
    {
        $doctor = $this->makeDoctor(Service::factory()->create());
        $nouveau = Service::factory()->create();

        Livewire::actingAs($this->makeAdmin())
            ->test(DoctorManager::class)
            ->call('edit', $doctor->getKey())
            ->set('service_id', $nouveau->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($nouveau->getKey(), $doctor->refresh()->service_id);
    }

    public function test_le_mot_de_passe_est_conserve_si_le_champ_reste_vide(): void
    {
        $doctor = $this->makeDoctor(Service::factory()->create());
        $hash = $doctor->user->password;

        Livewire::actingAs($this->makeAdmin())
            ->test(DoctorManager::class)
            ->call('edit', $doctor->getKey())
            ->set('name', 'Nom corrige')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($hash, $doctor->user->refresh()->password);
        $this->assertSame('Nom corrige', $doctor->user->name);
    }

    public function test_l_admin_cree_une_receptionniste(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(ReceptionistManager::class)
            ->set('name', 'Awa Traore')
            ->set('email', 'awa@keneya.test')
            ->set('password', 'motdepasse')
            ->call('save')
            ->assertHasNoErrors();

        $receptionist = Receptionist::with('user')->firstOrFail();

        $this->assertTrue($receptionist->user->hasRole(Roles::RECEPTIONIST));
        $this->assertSame(route('reception.home'), route($receptionist->user->homeRoute()));
    }

    public function test_l_admin_ouvre_le_dossier_de_n_importe_quel_patient(): void
    {
        $service = Service::factory()->create();
        $patient = Patient::factory()->create(['name' => 'Sekou Diarra']);
        $this->makeVisit($service, [], $patient);
        $this->makeVisit(Service::factory()->create(), [], Patient::factory()->create(['name' => 'Autre Patient']));

        Livewire::actingAs($this->makeAdmin())
            ->test(PatientDirectory::class)
            ->set('search', 'Sekou')
            ->assertSee('Sekou Diarra')
            ->assertDontSee('Autre Patient')
            ->call('openRecord', $patient->getKey())
            ->assertSee($patient->patient_code);
    }
}
