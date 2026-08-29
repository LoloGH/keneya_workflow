<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ouvre le parcours de soin aux types de personnel generiques (v3.2.1, point 10).
 *
 * Jusqu'ici, seul un `doctors` pouvait envoyer un renvoi, en saisir le resultat
 * ou signer une ligne d'historique. Un type cree par l'admin — infirmier,
 * sage-femme, technicien — n'a pas de ligne `doctors` et se serait heurte a des
 * cles etrangeres.
 *
 * On n'elargit pas `doctors` a tout le personnel : ce serait fabriquer de faux
 * medecins, avec les droits de l'interface /service. Chaque acte porte donc
 * l'un **ou** l'autre rattachement, jamais les deux, et jamais aucun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('from_staff_member_id')->nullable()->after('from_doctor_id')->constrained('staff_members');
            $table->foreignId('completed_by_staff_member_id')->nullable()->after('completed_by_doctor_id')->constrained('staff_members');
            $table->foreignId('closed_by_staff_member_id')->nullable()->after('closed_by_doctor_id')->constrained('staff_members');
        });

        // `from_doctor_id` etait obligatoire : il devient facultatif, l'auteur
        // pouvant desormais etre un membre du personnel generique.
        Schema::table('referrals', function (Blueprint $table) {
            $table->unsignedBigInteger('from_doctor_id')->nullable()->change();
        });

        Schema::table('patient_history', function (Blueprint $table) {
            $table->foreignId('staff_member_id')->nullable()->after('doctor_id')->constrained('staff_members');
        });
    }

    public function down(): void
    {
        Schema::table('patient_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_member_id');
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_staff_member_id');
            $table->dropConstrainedForeignId('completed_by_staff_member_id');
            $table->dropConstrainedForeignId('closed_by_staff_member_id');
        });

        // Un renvoi sans medecin n'a pas d'equivalent dans l'ancien schema :
        // il est retire plutot que de rendre la colonne non-nulle sur une
        // valeur inventee.
        DB::table('referrals')->whereNull('from_doctor_id')->delete();

        Schema::table('referrals', function (Blueprint $table) {
            $table->unsignedBigInteger('from_doctor_id')->nullable(false)->change();
        });
    }
};
