<?php

namespace App\Actions;

use App\Models\Patient;
use App\Models\PatientHistory;
use App\Models\Service;
use App\Models\Visit;
use App\Services\PatientHistoryRecorder;
use App\Services\SmsGateway;
use App\Services\TokenAllocator;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Retour d'un patient deja connu : ouvre un nouveau passage sous son identite
 * existante.
 *
 * Aucune ligne `patients` n'est creee, aucun nouveau `patient_code` n'est
 * genere — c'est tout l'interet de la separation identite / passage : le
 * dossier d'il y a six mois reste distinct du nouvel episode, sous le meme
 * identifiant.
 */
class OpenNewEpisode
{
    public function __construct(
        private readonly TokenAllocator $tokens,
        private readonly PatientHistoryRecorder $history,
        private readonly SmsGateway $sms,
        private readonly RouteThroughCaisse $routing,
    ) {}

    public function execute(Patient $patient, int $serviceId, ?string $reason = null): Visit
    {
        $visit = DB::transaction(function () use ($patient, $serviceId, $reason): Visit {
            [$file, $enAttente] = $this->routing->forRegistration(Service::findOrFail($serviceId));

            $visit = Visit::create([
                'patient_id' => $patient->getKey(),
                'service_id' => $file->getKey(),
                'pending_next_service_id' => $enAttente?->getKey(),
                'token' => $this->tokens->next($file),
                'status' => Visit::STATUS_WAITING,
                'opened_at' => now(),
            ]);

            $visit->load('service');

            $this->history->record(
                visit: $visit,
                type: PatientHistory::TYPE_REGISTRATION,
                description: trim(sprintf(
                    'Nouvel episode ouvert a l\'accueil, oriente vers %s (ticket n° %d).%s%s',
                    $visit->service->name,
                    $visit->token,
                    $enAttente ? ' Prise en charge prevue au service '.$enAttente->name.' apres paiement.' : '',
                    filled($reason) ? ' Motif : '.$reason : '',
                )),
            );

            return $visit;
        });

        Audit::log(
            Audit::EVENT_VISIT_OPENED,
            sprintf('Nouvel episode ouvert pour %s (%s) au service %s.', $patient->name, $patient->patient_code, $visit->service->name),
            $visit,
        );

        $this->sms->send($patient->mobile, sprintf(
            '%s : bonjour %s. Nouveau passage enregistre sous votre dossier %s. Service %s, ticket n° %d.',
            config('keneya.name'),
            $patient->name,
            $patient->patient_code,
            $visit->service->name,
            $visit->token,
        ));

        return $visit;
    }
}
