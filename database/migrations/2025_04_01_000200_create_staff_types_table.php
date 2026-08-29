<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Types de personnel administrables (v3.2.1, point 10).
 *
 * Deux chemins, pas un seul :
 *  - `matched_role` renseigne : le type reutilise telle quelle une des quatre
 *    interfaces deja construites et testees (/admin, /reception, /service,
 *    /caisse). Rien ne change dans leur fonctionnement.
 *  - `matched_role` vide : le type recoit une interface generique sur
 *    /staff/{slug}, composee des seules briques cochees dans `capabilities`.
 *
 * `staff_members` rattache une personne a son type et a son service, sur le
 * modele de `doctors` / `receptionists` / `cashiers`. Les types adosses a un
 * role continuent, eux, d'utiliser ces tables-la — rien n'est deplace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // 'doctor' | 'receptionist' | 'cashier' | null. Jamais 'admin' :
            // l'administrateur n'a pas vocation a etre multiplie.
            $table->string('matched_role')->nullable();
            // Chemin d'acces de l'interface generique. Nul pour un type adosse
            // a un role, qui a deja son interface.
            $table->string('slug')->unique()->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('staff_type_id')->constrained();
            // Le service ou la personne exerce, choisi a sa creation — meme
            // logique que doctors.service_id.
            $table->foreignId('service_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['user_id', 'staff_type_id']);
        });

        // Les types correspondant aux roles deja en place, pour que la section
        // « Personnel » de /admin les presente des la premiere ouverture.
        foreach ([
            ['Medecin', 'doctor'],
            ['Receptionniste', 'receptionist'],
            ['Caissier', 'cashier'],
        ] as [$name, $role]) {
            DB::table('staff_types')->insert([
                'name' => $name,
                'matched_role' => $role,
                'slug' => null,
                'capabilities' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_members');
        Schema::dropIfExists('staff_types');
    }
};
