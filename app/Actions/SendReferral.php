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
 * Renvoi d'un patient vers un autre service.
 *
 * C'est la visite qui se deplace : un episode unique traverse les services,
 * change de service_id et recoit un nouveau ticket. Ni le patient_id ni le
 * patient_code ne changent, et l'episode ne se cloture qu'une fois, a la fin.
 */
class SendReferral
{
    public function __construct(
        private readonly TokenAllocator $tokens,
        private readonly PatientHistoryRecorder $history,
        private readonly SmsGateway $sms,
        private readonly RouteThroughCaisse $routing,
    ) {}

    public function execute(Visit $visit, Doctor|StaffMember $fromDoctor, Service $toService, string $instructions): Referral
    {
        if ($visit->isClosed()) {
            throw new InvalidArgumentException('Ce dossier est cloture : il ne peut plus etre renvoye vers un autre service.');
        }

        $fromService = $visit->service()->firstOrFail();

        if ($toService->is($fromService)) {
            throw new InvalidArgumentException('Le service destinataire doit etre different du service actuel du patient.');
        }

        // La caisse est une etape de routage, jamais une destination medicale :
        // c'est RouteThroughCaisse qui l'intercale, personne ne l'y envoie.
        if ($toService->isCaisse()) {
            throw new InvalidArgumentException("La caisse n'est pas une destination de renvoi.");
        }

        $referral = DB::transaction(function () use ($visit, $fromDoctor, $fromService, $toService, $instructions): Referral {
            $referral = Referral::create([
                'patient_id' => $visit->patient_id,
                'visit_id' => $visit->getKey(),
                'from_service_id' => $fromService->getKey(),
                'to_service_id' => $toService->getKey(),
                ...Caregiver::of($fromDoctor)->columns('from'),
                'instructions' => $instructions,
                'status' => Referral::STATUS_PENDING,
            ]);

            // Un service dont le type porte `requires_payment_gate` se regle
            // avant d'etre realise : la visite est routee vers la Caisse
            // Services, la vraie destination attendant dans
            // `pending_next_service_id`. La ligne `referrals` ci-dessus garde,
            // elle, la destination metier reelle — la caisse n'est jamais la
            // destination d'un renvoi au sens medical.
            [$file, $enAttente] = $this->routing->forReferral($toService);

            $visit->update([
                'service_id' => $file->getKey(),
                'pending_next_service_id' => $enAttente?->getKey(),
                'token' => $this->tokens->next($file),
                'status' => Visit::STATUS_WAITING,
            ]);

            $this->history->record(
                visit: $visit,
                type: PatientHistory::TYPE_REFERRAL_SENT,
                description: sprintf(
                    'Renvoye de %s vers %s par %s.%s Instructions : %s',
                    $fromService->name,
                    $toService->name,
                    $fromDoctor->name(),
                    $enAttente ? ' Passage par '.$file->name.' avant realisation.' : '',
                    $instructions,
                ),
                serviceId: $toService->getKey(),
                doctor: $fromDoctor,
                referral: $referral,
            );

            return $referral;
        });

        Audit::log(
            Audit::EVENT_REFERRAL_SENT,
            sprintf('Renvoi de %s vers %s.', $fromService->name, $toService->name),
            $referral,
        );

        $patient = $visit->patient()->firstOrFail();

        $courante = $visit->fresh()->load('service');

        $this->sms->send($patient->mobile, sprintf(
            '%s : vous etes oriente(e) vers %s, ticket n° %d. Dossier %s.',
            config('keneya.name'),
            $courante->service->name,
            $courante->token,
            $patient->patient_code,
        ));

        return $referral;
    }
}
