<?php

namespace App\Actions;

use App\Models\Doctor;
use App\Models\PatientHistory;
use App\Models\Referral;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\Visit;
use App\Services\PatientHistoryRecorder;
use App\Services\SmsGateway;
use App\Services\TokenAllocator;
use App\Support\Audit;
use App\Support\Caregiver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saisie du resultat par le service destinataire, puis retour au prescripteur.
 *
 * Le retour n'est pas qu'une notification : **le patient lui-meme revient**.
 * La visite quitte la file du service destinataire, retourne dans celle du
 * prescripteur et y recoit un nouveau ticket. Sans cela, un patient revenu du
 * laboratoire resterait indefiniment dans la file du laboratoire, meme si son
 * resultat s'affiche bien chez le medecin.
 *
 * **Le retour ne repasse jamais par la caisse.** Le paiement ne conditionne que
 * le trajet aller (`SendReferral`, via RouteThroughCaisse) : on ne fait pas
 * payer deux fois un patient pour revenir voir le medecin qui l'a envoye. Cette
 * action n'appelle donc volontairement pas RouteThroughCaisse et reste un
 * chemin de code separe de l'aller — mutualiser les deux reintroduirait le
 * peage sur le retour a la premiere refactorisation.
 *
 * Le prescripteur est prevenu par SMS si son numero est renseigne ; dans tous
 * les cas le resultat apparait dans son panneau « Resultats recus » au
 * prochain rafraichissement (wire:poll), sans WebSocket.
 */
class CompleteReferral
{
    public function __construct(
        private readonly PatientHistoryRecorder $history,
        private readonly SmsGateway $sms,
        private readonly TokenAllocator $tokens,
    ) {}

    public function execute(Referral $referral, Doctor|StaffMember $completedBy, string $resultText): Referral
    {
        if ($referral->status !== Referral::STATUS_PENDING) {
            throw new InvalidArgumentException('Ce renvoi a deja recu un resultat.');
        }

        if ((int) Caregiver::of($completedBy)->serviceId() !== (int) $referral->to_service_id) {
            throw new InvalidArgumentException('Seul un praticien du service destinataire peut saisir ce resultat.');
        }

        $referral = DB::transaction(function () use ($referral, $completedBy, $resultText): Referral {
            $referral->update([
                'status' => Referral::STATUS_DONE,
                'result_text' => $resultText,
                ...Caregiver::of($completedBy)->columns('completed_by'),
                'completed_at' => now(),
            ]);

            $referral->load(['patient', 'toService', 'visit']);

            $retour = $this->returnToPrescriber($referral);

            $this->history->record(
                visit: $referral->visit,
                type: PatientHistory::TYPE_REFERRAL_RESULT,
                description: sprintf(
                    'Resultat de %s saisi par %s : %s%s',
                    $referral->toService->name,
                    $completedBy->name(),
                    $resultText,
                    $retour
                        ? sprintf(
                            ' Retour en %s, ticket n° %d.',
                            $retour->service->name,
                            $retour->token,
                        )
                        : '',
                ),
                serviceId: $referral->to_service_id,
                doctor: $completedBy,
                referral: $referral,
            );

            return $referral;
        });

        Audit::log(
            Audit::EVENT_REFERRAL_COMPLETED,
            sprintf('Resultat saisi pour le patient %s par %s.', $referral->patient->patient_code, $completedBy->name()),
            $referral,
        );

        $prescriber = $referral->fromDoctor()->first();

        if ($prescriber && filled($prescriber->phone)) {
            $this->sms->send($prescriber->phone, sprintf(
                '%s : resultat disponible pour le patient %s (%s), renvoye vers %s.',
                config('keneya.name'),
                $referral->patient->name,
                $referral->patient->patient_code,
                $referral->toService->name,
            ));
        }

        return $referral;
    }

    /**
     * Replace la visite dans la file du service prescripteur, avec un nouveau
     * ticket. Aucun passage par la caisse : le retour n'est pas un renvoi.
     *
     * Ne fait rien si la visite a ete cloturee entre-temps — un dossier clos ne
     * doit pas ressusciter dans une file d'attente parce qu'un resultat arrive
     * en retard.
     */
    private function returnToPrescriber(Referral $referral): ?Visit
    {
        $visit = $referral->visit;

        if (! $visit instanceof Visit || $visit->isClosed()) {
            return null;
        }

        $prescriberService = Service::find($referral->from_service_id);

        if (! $prescriberService) {
            return null;
        }

        $visit->update([
            'service_id' => $prescriberService->getKey(),
            // Le retour ne doit rien a personne : toute destination en attente
            // de paiement heritee de l'aller est effacee.
            'pending_next_service_id' => null,
            'token' => $this->tokens->next($prescriberService),
            'status' => Visit::STATUS_WAITING,
        ]);

        return $visit->refresh()->load('service');
    }
}
