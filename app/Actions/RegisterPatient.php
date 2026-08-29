<?php

namespace App\Actions;

use App\Models\Companion;
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
 * Premiere venue d'un patient : cree son identite permanente, ouvre son
 * premier passage et l'informe par SMS.
 *
 * Pour un patient deja connu qui revient, c'est OpenNewEpisode qui prend le
 * relais : on ne cree jamais deux identites pour la meme personne.
 */
class RegisterPatient
{
    public function __construct(
        private readonly TokenAllocator $tokens,
        private readonly PatientHistoryRecorder $history,
        private readonly SmsGateway $sms,
        private readonly RouteThroughCaisse $routing,
    ) {}

    /**
     * @param  array{name: string, age: int, gender: string, mobile: string, crno?: ?string, service_id: int, reason?: ?string}  $data
     * @param  array<int, array{name: string, phone?: ?string, relation?: ?string}>  $companions
     */
    public function execute(array $data, array $companions = []): Visit
    {
        $visit = DB::transaction(function () use ($data, $companions): Visit {
            $patient = Patient::create([
                'name' => $data['name'],
                'age' => $data['age'],
                'gender' => $data['gender'],
                'mobile' => $data['mobile'],
                'crno' => $data['crno'] ?? null,
            ]);

            foreach ($companions as $companion) {
                if (blank($companion['name'] ?? null)) {
                    continue;
                }

                Companion::create([
                    'patient_id' => $patient->getKey(),
                    'name' => $companion['name'],
                    'phone' => $companion['phone'] ?? null,
                    'relation' => $companion['relation'] ?? null,
                ]);
            }

            // La receptionniste choisit le service clinique, mais le patient
            // patiente d'abord a la caisse : on regle avant d'etre pris en charge.
            [$file, $enAttente] = $this->routing->forRegistration(Service::findOrFail($data['service_id']));

            $visit = Visit::create([
                'patient_id' => $patient->getKey(),
                'service_id' => $file->getKey(),
                'pending_next_service_id' => $enAttente?->getKey(),
                'token' => $this->tokens->next($file),
                'status' => Visit::STATUS_WAITING,
                'opened_at' => now(),
            ]);

            $visit->load(['service', 'patient', 'pendingNextService']);

            $this->history->record(
                visit: $visit,
                type: PatientHistory::TYPE_REGISTRATION,
                description: trim(sprintf(
                    'Enregistrement a l\'accueil, oriente vers %s (ticket n° %d).%s%s',
                    $visit->service->name,
                    $visit->token,
                    $enAttente ? ' Prise en charge prevue au service '.$enAttente->name.' apres paiement.' : '',
                    filled($data['reason'] ?? null) ? ' Motif : '.$data['reason'] : '',
                )),
            );

            return $visit;
        });

        Audit::log(
            Audit::EVENT_PATIENT_CREATED,
            sprintf('Patient %s (%s) enregistre au service %s.', $visit->patient->name, $visit->patient->patient_code, $visit->service->name),
            $visit->patient,
        );

        $this->sms->send($visit->patient->mobile, sprintf(
            '%s : bonjour %s. Votre dossier est le %s. Vous etes attendu(e) au service %s, ticket n° %d.',
            config('keneya.name'),
            $visit->patient->name,
            $visit->patient->patient_code,
            $visit->service->name,
            $visit->token,
        ));

        return $visit;
    }
}
