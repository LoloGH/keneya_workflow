<?php

namespace App\Actions;

use App\Models\CareTask;
use App\Models\CareTaskType;
use App\Models\Doctor;
use App\Models\Hospitalization;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Prescription de soins recurrents (v3.2.1, point 11).
 *
 * Meme principe que la creation groupee de planning : la recurrence est
 * resolue **a la creation**, pas a la lecture. « Toutes les 8 heures pendant
 * 3 jours » produit neuf lignes, chacune marquable individuellement comme
 * faite ou manquee — ce qu'une regle calculee a la volee ne permettrait pas.
 */
class PrescribeCareTasks
{
    /** Garde-fou : une prescription ne genere jamais des milliers de lignes. */
    public const MAX_OCCURRENCES = 200;

    /**
     * @return int nombre d'occurrences creees
     */
    public function execute(
        Hospitalization $hospitalization,
        CareTaskType $type,
        Doctor $doctor,
        Carbon $start,
        int $intervalHours,
        int $durationDays,
        ?string $instructions = null,
        ?User $assignedTo = null,
    ): int {
        if (! $hospitalization->isActive()) {
            throw new InvalidArgumentException('Cette hospitalisation est cloturee : aucun soin ne peut y etre ajoute.');
        }

        if ($intervalHours < 1) {
            throw new InvalidArgumentException("L'intervalle doit valoir au moins une heure.");
        }

        if ($durationDays < 1) {
            throw new InvalidArgumentException('La duree doit valoir au moins un jour.');
        }

        $fin = $start->copy()->addDays($durationDays);
        $occurrences = [];

        for ($moment = $start->copy(); $moment->lt($fin); $moment->addHours($intervalHours)) {
            $occurrences[] = $moment->copy();

            if (count($occurrences) > self::MAX_OCCURRENCES) {
                throw new InvalidArgumentException(sprintf(
                    'Cette prescription depasse %d administrations : reduisez la duree ou espacez l\'intervalle.',
                    self::MAX_OCCURRENCES,
                ));
            }
        }

        DB::transaction(function () use ($occurrences, $hospitalization, $type, $doctor, $instructions, $assignedTo): void {
            foreach ($occurrences as $moment) {
                CareTask::create([
                    'hospitalization_id' => $hospitalization->getKey(),
                    'care_task_type_id' => $type->getKey(),
                    'instructions' => $instructions,
                    'prescribed_by_doctor_id' => $doctor->getKey(),
                    'assigned_to_user_id' => $assignedTo?->getKey(),
                    'scheduled_at' => $moment,
                    'status' => CareTask::STATUS_PENDING,
                ]);
            }
        });

        Audit::log(
            Audit::EVENT_CARE_TASKS_PRESCRIBED,
            sprintf(
                '%d administration(s) de « %s » prescrites par %s pour %s (toutes les %d h pendant %d jour(s)).',
                count($occurrences),
                $type->name,
                $doctor->name(),
                $hospitalization->patient->patient_code,
                $intervalHours,
                $durationDays,
            ),
            $hospitalization,
        );

        return count($occurrences);
    }
}
