<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceKind;
use Illuminate\Database\Seeder;

/**
 * Les services de depart de l'Hopital Fousseyni Daou de Kayes.
 *
 * Leur type est desormais une ligne `service_kinds` administrable, resolue ici
 * par son slug : renommer « Plateau technique » dans /admin ne casse pas ce
 * seeder.
 */
class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $this->callOnce(ServiceKindSeeder::class);

        $types = ServiceKind::pluck('id', 'slug');

        $services = [
            ['name' => 'Medecine Generale', 'slug' => ServiceKind::SLUG_CLINIQUE],
            ['name' => 'Urgences', 'slug' => ServiceKind::SLUG_CLINIQUE],
            ['name' => 'Maternite', 'slug' => ServiceKind::SLUG_CLINIQUE],
            ['name' => 'Administration', 'slug' => ServiceKind::SLUG_CLINIQUE],
            ['name' => 'Echographie', 'slug' => ServiceKind::SLUG_PLATEAU_TECHNIQUE],
            ['name' => 'Laboratoire', 'slug' => ServiceKind::SLUG_PLATEAU_TECHNIQUE],

            // Les deux caisses sont des services a part entiere : meme file,
            // meme token, meme « Appeler le suivant ».
            ['name' => Service::CAISSE_TICKET, 'slug' => ServiceKind::SLUG_CAISSE],
            ['name' => Service::CAISSE_SERVICES, 'slug' => ServiceKind::SLUG_CAISSE],
        ];

        foreach ($services as $service) {
            Service::firstOrCreate(
                ['name' => $service['name']],
                ['service_kind_id' => $types[$service['slug']]],
            );
        }
    }
}
