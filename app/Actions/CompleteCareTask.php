<?php

namespace App\Actions;

use App\Models\CareTask;
use App\Models\PatientHistory;
use App\Models\User;
use App\Services\PatientHistoryRecorder;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Execution d'un soin programme (v3.2.1, point 11).
 *
 * La tracabilite reelle vient d'ici — `completed_by_user_id` au moment du geste
 * — et non d'une assignation previsionnelle qui casserait au premier
 * remplacement d'equipe.
 */
class CompleteCareTask
{
    public function __construct(private readonly PatientHistoryRecorder $history) {}

    public function execute(CareTask $task, User $user): CareTask
    {
        if ($task->status !== CareTask::STATUS_PENDING) {
            throw new InvalidArgumentException('Ce soin a deja ete traite.');
        }

        $hospitalization = $task->hospitalization()->with(['patient', 'visit'])->firstOrFail();

        if (! $hospitalization->isActive()) {
            throw new InvalidArgumentException('Cette hospitalisation est cloturee.');
        }

        // Seul le personnel de garde sur le service peut marquer un soin. Une
        // assignation nommee reste une priorite d'affichage, jamais une
        // restriction : un soin ne doit pas rester bloque si la personne
        // designee est absente.
        if (! $user->isOnDutyFor($hospitalization->service_id)) {
            throw new InvalidArgumentException("Vous n'etes pas de garde sur ce service en ce moment.");
        }

        DB::transaction(function () use ($task, $user, $hospitalization): void {
            $task->update([
                'status' => CareTask::STATUS_DONE,
                'completed_by_user_id' => $user->getKey(),
                'completed_at' => now(),
            ]);

            if ($visit = $hospitalization->visit) {
                $this->history->record(
                    visit: $visit,
                    type: PatientHistory::TYPE_CARE_TASK_COMPLETED,
                    description: sprintf(
                        '%s administre par %s.%s',
                        $task->type->name,
                        $user->name,
                        filled($task->instructions) ? ' '.$task->instructions : '',
                    ),
                    serviceId: $hospitalization->service_id,
                );
            }
        });

        Audit::log(
            Audit::EVENT_CARE_TASK_COMPLETED,
            sprintf(
                '« %s » administre a %s par %s.',
                $task->type->name,
                $hospitalization->patient->patient_code,
                $user->name,
            ),
            $task,
        );

        return $task->refresh();
    }

    /**
     * Marquer un soin manque : une administration non faite reste visible dans
     * le dossier plutot que d'etre effacee.
     */
    public function markMissed(CareTask $task, User $user): CareTask
    {
        if ($task->status !== CareTask::STATUS_PENDING) {
            throw new InvalidArgumentException('Ce soin a deja ete traite.');
        }

        $task->update([
            'status' => CareTask::STATUS_MISSED,
            'completed_by_user_id' => $user->getKey(),
            'completed_at' => now(),
        ]);

        Audit::log(
            Audit::EVENT_CARE_TASK_COMPLETED,
            sprintf('« %s » marque comme manque par %s.', $task->type->name, $user->name),
            $task,
        );

        return $task->refresh();
    }
}
