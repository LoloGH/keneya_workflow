<?php

namespace Database\Seeders;

use App\Models\ServiceKind;
use Illuminate\Database\Seeder;

/**
 * Les trois types de service d'origine (v3.2.1, point 10).
 *
 * Ils sont deja crees par la migration : ce seeder est le filet d'une base
 * montee autrement (import, reprise), et le point d'entree lisible pour qui
 * cherche d'ou viennent ces trois lignes.
 */
class ServiceKindSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Clinique', 'slug' => ServiceKind::SLUG_CLINIQUE, 'requires_payment_gate' => false],
            // Le seul type d'origine soumis au peage : un acte se regle avant
            // d'etre realise.
            ['name' => 'Plateau technique', 'slug' => ServiceKind::SLUG_PLATEAU_TECHNIQUE, 'requires_payment_gate' => true],
            ['name' => 'Caisse', 'slug' => ServiceKind::SLUG_CAISSE, 'requires_payment_gate' => false],
        ];

        foreach ($types as $type) {
            ServiceKind::firstOrCreate(['slug' => $type['slug']], $type);
        }
    }
}
