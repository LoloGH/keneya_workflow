<?php

namespace Tests\Unit;

use App\Models\Prescription;
use PHPUnit\Framework\TestCase;

/**
 * Mise en page des ordonnances sur le portail patient.
 *
 * Le champ de saisie reste libre — c'est un choix produit : un praticien
 * n'ecrit pas une ordonnance dans un formulaire rigide. Le decoupage sert donc
 * uniquement a l'affichage, et ne doit jamais alterer ni perdre ce qui a ete
 * ecrit.
 */
class PrescriptionLinesTest extends TestCase
{
    public function test_chaque_medicament_devient_une_ligne_sans_numerotation_doublee(): void
    {
        $ordonnance = new Prescription([
            'content' => "1. Paracetamol — 1/2 — 5\n2. Aspirine — 1/3 — 4",
        ]);

        $this->assertSame([
            ['medicament' => 'Paracetamol', 'precisions' => ['1/2', '5']],
            ['medicament' => 'Aspirine', 'precisions' => ['1/3', '4']],
        ], $ordonnance->lines());
    }

    public function test_les_separateurs_courants_sont_reconnus(): void
    {
        $ordonnance = new Prescription([
            'content' => "Amoxicilline | 1 gelule | 7 jours\nIbuprofene - 200 mg - 3 jours",
        ]);

        $this->assertSame([
            ['medicament' => 'Amoxicilline', 'precisions' => ['1 gelule', '7 jours']],
            ['medicament' => 'Ibuprofene', 'precisions' => ['200 mg', '3 jours']],
        ], $ordonnance->lines());
    }

    /**
     * Une phrase libre ne doit pas etre decoupee arbitrairement : elle reste
     * une entree unique, affichee telle quelle.
     */
    public function test_un_texte_libre_sans_separateur_reste_intact(): void
    {
        $ordonnance = new Prescription([
            'content' => 'Paracetamol 500 mg, trois fois par jour.',
        ]);

        $this->assertSame([
            ['medicament' => 'Paracetamol 500 mg, trois fois par jour.', 'precisions' => []],
        ], $ordonnance->lines());
    }

    public function test_les_lignes_vides_sont_ignorees(): void
    {
        $ordonnance = new Prescription(['content' => "Repos\n\n   \nHydratation"]);

        $this->assertSame(['Repos', 'Hydratation'], array_column($ordonnance->lines(), 'medicament'));
    }

    public function test_un_contenu_vide_ne_produit_aucune_ligne(): void
    {
        $this->assertSame([], (new Prescription(['content' => '   ']))->lines());
    }
}
