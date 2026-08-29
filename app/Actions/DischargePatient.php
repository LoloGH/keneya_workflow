<?php

namespace App\Actions;

use App\Models\Doctor;
use App\Models\Hospitalization;
use App\Models\PatientHistory;
use App\Services\PatientHistoryRecorder;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sortie d'hospitalisation (v3.2.1, point 11).
 *
 * Bloquee tant qu'il reste des soins programmes en attente : cloturer
 * silencieusement un plan de soins inacheve reviendrait a effacer la trace de
 * ce qui n'a pas ete fait.
 */
class DischargePatient
{
    public function __construct(private readonly PatientHistoryRecorder $history) {}

    public function execute(Hospitalization $hospitalization, Doctor $doctor): Hospitalization
    {
        if (! $hospitalization->isActive()) {
            throw new InvalidArgumentException('Cette hospitalisation est deja cloturee.');
        }

        $enAttente = $hospitalization->pendingCareTasks();

        if ($enAttente > 0) {
            throw new InvalidArgumentException(sprintf(
                '%d soin(s) restent a faire ou a marquer comme manques : traitez-les avant la sortie.',
                $enAttente,
            ));
        }

        DB::transaction(function () use ($hospitalization, $doctor): void {
            $hospitalization->update([
                'status' => Hospitalization::STATUS_DISCHARGED,
                'discharged_at' => now(),
            ]);

            // La visite d'origine est deja cloturee : la ligne d'historique est
            // rattachee au meme passage, pour rester sur une seule frise.
            if ($visit = $hospitalization->visit) {
                $this->history->record(
                    visit: $visit,
                    type: PatientHistory::TYPE_HOSPITALIZATION_DISCHARGED,
                    description: sprintf(
                        'Sortie d\'hospitalisation prononcee par %s apres %d jour(s).',
                        $doctor->name(),
                        max(1, $hospitalization->admitted_at->diffInDays(now())),
                    ),
                    serviceId: $hospitalization->service_id,
                    doctor: $doctor,
                );
            }
        });

        Audit::log(
            Audit::EVENT_PATIENT_DISCHARGED,
            sprintf(
                'Sortie d\'hospitalisation de %s prononcee par %s.',
                $hospitalization->patient->patient_code,
                $doctor->name(),
            ),
            $hospitalization,
        );

        return $hospitalization->refresh();
    }
}
