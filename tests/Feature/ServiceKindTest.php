<?php

namespace Tests\Feature;

use App\Livewire\Admin\ServiceKindManager;
use App\Livewire\Admin\ServiceManager;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Types de service administrables (v3.2.1, point 10).
 */
class ServiceKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_trois_types_d_origine_sont_poses_par_la_migration(): void
    {
        // La migration les cree : une base fraiche les a deja, sans seeder.
        foreach (ServiceKind::BUILT_IN_SLUGS as $slug) {
            $this->assertDatabaseHas('service_kinds', ['slug' => $slug]);
        }

        $this->assertTrue(
            ServiceKind::where('slug', ServiceKind::SLUG_PLATEAU_TECHNIQUE)->value('requires_payment_gate'),
            'Seul le plateau technique est soumis au paiement prealable a l\'installation.',
        );

        foreach ([ServiceKind::SLUG_CLINIQUE, ServiceKind::SLUG_CAISSE] as $slug) {
            $this->assertFalse((bool) ServiceKind::where('slug', $slug)->value('requires_payment_gate'));
        }
    }

    public function test_l_admin_cree_un_type_avec_son_indicateur_de_peage(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceKindManager::class)
            ->set('name', 'Imagerie medicale')
            ->set('requires_payment_gate', true)
            ->call('save')
            ->assertHasNoErrors();

        $kind = ServiceKind::where('name', 'Imagerie medicale')->firstOrFail();

        $this->assertTrue($kind->requires_payment_gate);
        // Le slug est derive du nom, sans intervention de l'admin.
        $this->assertSame('imagerie_medicale', $kind->slug);
        $this->assertFalse($kind->isBuiltIn());

        $this->assertNotNull(
            Activity::where('event', Audit::EVENT_SERVICE_KIND_CREATED)->latest('id')->first(),
        );
    }

    public function test_deux_types_de_meme_nom_recoivent_des_slugs_distincts(): void
    {
        $composant = Livewire::actingAs($this->makeAdmin())->test(ServiceKindManager::class);

        $composant->set('name', 'Imagerie')->call('save')->assertHasNoErrors();
        $composant->set('name', 'Imagerie')->call('save')->assertHasNoErrors();

        $this->assertSame(['imagerie', 'imagerie_2'], ServiceKind::where('name', 'Imagerie')
            ->orderBy('id')
            ->pluck('slug')
            ->all());
    }

    public function test_un_type_encore_utilise_n_est_pas_supprimable(): void
    {
        $kind = ServiceKind::create(['name' => 'Imagerie', 'slug' => 'imagerie']);
        Service::factory()->ofKind($kind)->create(['name' => 'Scanner']);

        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceKindManager::class)
            ->call('delete', $kind->getKey());

        // Le type survit, et rien n'est journalise : la suppression n'a pas eu lieu.
        $this->assertDatabaseHas('service_kinds', ['id' => $kind->getKey()]);
        $this->assertNull(Activity::where('event', Audit::EVENT_SERVICE_KIND_DELETED)->first());
    }

    public function test_un_type_d_origine_n_est_jamais_supprimable(): void
    {
        $plateau = $this->serviceKind(ServiceKind::SLUG_PLATEAU_TECHNIQUE);

        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceKindManager::class)
            ->call('delete', $plateau->getKey());

        // Du code s'appuie sur ces trois slugs : les perdre casserait le
        // routage caisse et l'interface /caisse.
        $this->assertDatabaseHas('service_kinds', ['id' => $plateau->getKey()]);
    }

    public function test_un_type_libre_et_inutilise_est_supprimable(): void
    {
        $kind = ServiceKind::create(['name' => 'Imagerie', 'slug' => 'imagerie']);

        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceKindManager::class)
            ->call('delete', $kind->getKey());

        $this->assertDatabaseMissing('service_kinds', ['id' => $kind->getKey()]);
    }

    public function test_renommer_un_type_d_origine_ne_change_pas_son_slug(): void
    {
        $plateau = $this->serviceKind(ServiceKind::SLUG_PLATEAU_TECHNIQUE);

        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceKindManager::class)
            ->call('edit', $plateau->getKey())
            ->set('name', 'Examens complementaires')
            ->call('save')
            ->assertHasNoErrors();

        $plateau->refresh();

        $this->assertSame('Examens complementaires', $plateau->name);
        $this->assertSame(ServiceKind::SLUG_PLATEAU_TECHNIQUE, $plateau->slug);
    }

    public function test_le_service_affiche_le_libelle_de_son_type(): void
    {
        $kind = ServiceKind::create(['name' => 'Imagerie', 'slug' => 'imagerie']);
        $service = Service::factory()->ofKind($kind)->create(['name' => 'Scanner']);

        $this->assertSame('Imagerie', $service->kindLabel());

        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceManager::class)
            ->assertSee('Imagerie');
    }

    public function test_le_type_caisse_n_est_pas_proposable_a_la_creation_d_un_service(): void
    {
        $caisse = $this->serviceKind(ServiceKind::SLUG_CAISSE);

        // Ni dans la liste…
        $proposes = Livewire::actingAs($this->makeAdmin())
            ->test(ServiceManager::class)
            ->viewData('kinds')
            ->pluck('slug');

        $this->assertFalse($proposes->contains(ServiceKind::SLUG_CAISSE));

        // …ni en forçant la valeur : les deux caisses sont posees par le seeder.
        Livewire::actingAs($this->makeAdmin())
            ->test(ServiceManager::class)
            ->set('name', 'Fausse caisse')
            ->set('service_kind_id', $caisse->getKey())
            ->call('save')
            ->assertHasErrors('service_kind_id');
    }

    public function test_la_section_types_de_service_est_dans_l_interface_admin(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/admin')
            ->assertSee('Types de service');
    }
}
