<?php

namespace App\Actions;

use App\Models\Doctor;
use App\Models\PatientHistory;
use App\Models\Referral;
use App\Models\StaffMember;
use App\Services\PatientHistoryRecorder;
use App\Support\Audit;
use App\Support\Caregiver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cloture d'un renvoi par le prescripteur (addendum v2, point 1).
 *
 * Une fois clos, le renvoi quitte le panneau « Resultats recus » — la boucle
 * est fermee — mais il reste visible dans l'historique du patient, qui n'est
 * jamais purge.
 */
class CloseReferral
{
    public function __construct(private readonly PatientHistoryRecorder $history) {}

    public function execute(Referral $referral, Doctor|StaffMember $closedBy): Referral
    {
        if ($referral->status !== Referral::STATUS_DONE) {
            throw new InvalidArgumentException('Seul un renvoi dont le resultat est arrive peut etre cloture.');
        }

        // Seul l'auteur du renvoi ferme la boucle — medecin ou personnel
        // generique, selon celui qui l'a envoye.
        $agent = Caregiver::of($closedBy);
        $auteur = $agent->doctorId() !== null
            ? (int) $referral->from_doctor_id === $agent->doctorId()
            : (int) $referral->from_staff_member_id === $agent->staffMemberId();

        if (! $auteur) {
            throw new InvalidArgumentException("Seul l'auteur du renvoi peut le cloturer.");
        }

        $referral = DB::transaction(function () use ($referral, $closedBy, $agent): Referral {
            $referral->update([
                'status' => Referral::STATUS_CLOSED,
                ...$agent->columns('closed_by'),
                'closed_at' => now(),
            ]);

            $referral->load(['toService', 'visit']);

            $this->history->record(
                visit: $referral->visit,
                type: PatientHistory::TYPE_REFERRAL_CLOSED,
                description: sprintf(
                    'Renvoi vers %s cloture par %s apres lecture du resultat.',
                    $referral->toService->name,
                    $closedBy->name(),
                ),
                serviceId: $referral->from_service_id,
                doctor: $closedBy,
                referral: $referral,
            );

            return $referral;
        });

        // Journalise seulement une fois la transaction validee : un journal qui
        // consigne des actes qui n'ont pas eu lieu ne vaut rien.
        Audit::log(
            Audit::EVENT_REFERRAL_CLOSED,
            sprintf('Renvoi #%d cloture par %s.', $referral->getKey(), $closedBy->name()),
            $referral,
        );

        return $referral;
    }
}
