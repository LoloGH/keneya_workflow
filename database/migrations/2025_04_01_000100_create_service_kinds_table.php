<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Types de service administrables (v3.2.1, point 10).
 *
 * `services.kind` etait un enum code en dur : chaque nouveau type demandait une
 * migration, donc un developpeur. L'admin doit pouvoir en creer lui-meme.
 *
 * L'indicateur `requires_payment_gate` remplace la comparaison codee en dur sur
 * « plateau_technique » : c'est desormais une propriete du type, reglable dans
 * /admin, qui decide si un renvoi vers ce service passe par la Caisse Services.
 *
 * `slug` n'est pas cosmetique : trois types restent structurants pour
 * l'application — c'est par lui que le code reconnait une caisse (interface
 * /caisse, exclusion des destinations) sans dependre d'un libelle que l'admin
 * peut renommer. Les types crees ensuite n'ont, eux, aucun comportement code.
 */
return new class extends Migration
{
    /**
     * Les trois types d'origine : libelle, slug, peage.
     *
     * @var array<int, array{0: string, 1: string, 2: bool}>
     */
    private const BUILT_IN = [
        ['Clinique', 'clinique', false],
        ['Plateau technique', 'plateau_technique', true],
        ['Caisse', 'caisse', false],
    ];

    public function up(): void
    {
        Schema::create('service_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('requires_payment_gate')->default(false);
            $table->timestamps();
        });

        $ids = [];

        foreach (self::BUILT_IN as [$name, $slug, $gate]) {
            $ids[$slug] = DB::table('service_kinds')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'requires_payment_gate' => $gate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('service_kind_id')->nullable()->after('name')->constrained();
        });

        // Conversion des valeurs existantes plutot qu'une remise a zero : un
        // service deja en base garde exactement le type qu'il avait.
        foreach ($ids as $slug => $id) {
            DB::table('services')->where('kind', $slug)->update(['service_kind_id' => $id]);
        }

        // Filet : un service dont le type serait illisible retombe sur Clinique
        // plutot que de rester orphelin et de casser toutes les lectures.
        DB::table('services')->whereNull('service_kind_id')->update(['service_kind_id' => $ids['clinique']]);

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('kind', 30)->default('clinique')->after('name');
        });

        // Les types ajoutes par l'admin n'ont pas d'equivalent dans l'ancien
        // enum : ils retombent sur « clinique », le seul choix sans effet de
        // bord. Les trois types d'origine, eux, sont restaures a l'identique.
        foreach (self::BUILT_IN as [, $slug]) {
            $id = DB::table('service_kinds')->where('slug', $slug)->value('id');

            if ($id) {
                DB::table('services')->where('service_kind_id', $id)->update(['kind' => $slug]);
            }
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_kind_id');
        });

        Schema::dropIfExists('service_kinds');
    }
};
