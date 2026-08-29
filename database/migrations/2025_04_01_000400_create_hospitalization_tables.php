<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitalisation et planning de soins (v3.2.1, point 11).
 *
 * Distinct du systeme de file d'attente : un patient hospitalise n'attend pas
 * un tour avec un ticket, il occupe un lit et recoit des soins programmes sur
 * plusieurs jours.
 *
 * L'occupation d'une salle n'est volontairement **pas** stockee : elle se
 * calcule a la volee depuis les hospitalisations actives. Un compteur finit
 * toujours par se desynchroniser de la realite qu'il pretend compter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('service_id')->constrained();
            $table->unsignedInteger('capacity');
            $table->timestamps();
        });

        Schema::create('care_task_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('hospitalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('service_id')->constrained();
            // La visite pendant laquelle l'admission a ete decidee, si applicable.
            $table->foreignId('visit_id')->nullable()->constrained();
            $table->foreignId('room_id')->nullable()->constrained('rooms');
            $table->foreignId('admitted_by_doctor_id')->constrained('doctors');
            $table->timestamp('admitted_at')->useCurrent();
            $table->timestamp('discharged_at')->nullable();
            $table->enum('status', ['active', 'discharged'])->default('active');
            $table->timestamps();

            $table->index(['service_id', 'status']);
            $table->index(['room_id', 'status']);
        });

        Schema::create('care_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospitalization_id')->constrained();
            $table->foreignId('care_task_type_id')->constrained();
            $table->text('instructions')->nullable();
            $table->foreignId('prescribed_by_doctor_id')->constrained('doctors');
            // Facultatif : le medecin peut designer quelqu'un dans un cas
            // precis, sinon la tache reste ouverte au personnel de garde.
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users');
            $table->dateTime('scheduled_at');
            $table->enum('status', ['pending', 'done', 'missed'])->default('pending');
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['hospitalization_id', 'status']);
            $table->index(['scheduled_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_tasks');
        Schema::dropIfExists('hospitalizations');
        Schema::dropIfExists('care_task_types');
        Schema::dropIfExists('rooms');
    }
};
