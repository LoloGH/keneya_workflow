<?php

namespace Tests;

use App\Models\Cashier;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Receptionist;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Models\User;
use App\Models\Visit;
use App\Support\Roles;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cree les quatre roles cloisonnes, comme le fait RoleSeeder au deploiement.
     * A appeler dans les tests ou c'est le code applicatif — et non le test —
     * qui attribue un role.
     */
    protected function seedRoles(): void
    {
        foreach (Roles::all() as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function makeAdmin(): User
    {
        return $this->makeUserWithRole(Roles::ADMIN);
    }

    protected function makeReceptionist(): User
    {
        $user = $this->makeUserWithRole(Roles::RECEPTIONIST);

        Receptionist::create(['user_id' => $user->getKey()]);

        return $user;
    }

    /**
     * Un des trois types de service d'origine, cree a la demande : les tests
     * partent d'une base vide, la migration ne les a pas semes ici.
     */
    protected function serviceKind(string $slug): ServiceKind
    {
        return ServiceKind::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst(str_replace('_', ' ', $slug)),
                'requires_payment_gate' => $slug === ServiceKind::SLUG_PLATEAU_TECHNIQUE,
            ],
        );
    }

    protected function makeCashier(): User
    {
        $user = $this->makeUserWithRole(Roles::CASHIER);

        Cashier::create(['user_id' => $user->getKey()]);

        return $user;
    }

    /**
     * Les deux caisses du seeder, indispensables au routage sous condition de
     * paiement : sans elles, RouteThroughCaisse laisse passer en direct.
     *
     * @return array{0: Service, 1: Service}
     */
    protected function makeCaisses(): array
    {
        return [
            Service::factory()->caisse()->create(['name' => Service::CAISSE_TICKET]),
            Service::factory()->caisse()->create(['name' => Service::CAISSE_SERVICES]),
        ];
    }

    protected function makeDoctor(Service $service, ?string $phone = null): Doctor
    {
        return Doctor::create([
            'user_id' => $this->makeUserWithRole(Roles::DOCTOR)->getKey(),
            'service_id' => $service->getKey(),
            'phone' => $phone,
        ]);
    }

    /**
     * Ouvre un passage pour un patient (nouveau par defaut) dans un service.
     */
    protected function makeVisit(Service $service, array $attributes = [], ?Patient $patient = null): Visit
    {
        return Visit::factory()->create(array_merge([
            'patient_id' => ($patient ?? Patient::factory()->create())->getKey(),
            'service_id' => $service->getKey(),
        ], $attributes));
    }

    private function makeUserWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }
}
