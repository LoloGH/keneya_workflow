<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => 'Service '.$this->faker->unique()->word(),
            'service_kind_id' => fn () => self::kindId(ServiceKind::SLUG_CLINIQUE),
        ];
    }

    public function plateauTechnique(): static
    {
        return $this->state(fn () => [
            'service_kind_id' => self::kindId(ServiceKind::SLUG_PLATEAU_TECHNIQUE),
        ]);
    }

    public function caisse(): static
    {
        return $this->state(fn () => ['service_kind_id' => self::kindId(ServiceKind::SLUG_CAISSE)]);
    }

    /** Un type quelconque, pour tester un service cree de toutes pieces. */
    public function ofKind(ServiceKind $kind): static
    {
        return $this->state(fn () => ['service_kind_id' => $kind->getKey()]);
    }

    /**
     * Les trois types d'origine sont poses par la migration ; un test qui
     * repart d'une base vide les retrouve ici plutot que d'avoir a les semer.
     */
    private static function kindId(string $slug): int
    {
        return ServiceKind::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst(str_replace('_', ' ', $slug)),
                'requires_payment_gate' => $slug === ServiceKind::SLUG_PLATEAU_TECHNIQUE,
            ],
        )->getKey();
    }
}
