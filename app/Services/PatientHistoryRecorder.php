<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\PatientHistory;
use App\Models\Referral;
use App\Models\StaffMember;
use App\Models\Visit;
use App\Support\Caregiver;

/**
 * Unique point d'ecriture du journal `patient_history`, qui est append-only :
 * on n'y insere que des lignes, on ne les modifie jamais.
 *
 * Chaque ligne est ancree sur une visite — c'est ce qui permet de distinguer
 * deux passages du meme patient — tout en gardant `patient_id` pour lire un
 * dossier complet d'une seule requete.
 */
class PatientHistoryRecorder
{
    /**
     * `$doctor` accepte aussi un membre du personnel generique : la ligne est
     * alors signee par `staff_member_id`, jamais par `doctor_id` — on ne
     * fabrique pas de faux medecins dans le dossier.
     */
    public function record(
        Visit $visit,
        string $type,
        string $description,
        ?int $serviceId = null,
        Doctor|StaffMember|null $doctor = null,
        ?Referral $referral = null,
    ): PatientHistory {
        $agent = $doctor ? Caregiver::of($doctor) : null;

        return PatientHistory::create([
            'patient_id' => $visit->patient_id,
            'visit_id' => $visit->getKey(),
            'type' => $type,
            'service_id' => $serviceId ?? $visit->service_id,
            'doctor_id' => $agent?->doctorId(),
            'staff_member_id' => $agent?->staffMemberId(),
            'referral_id' => $referral?->getKey(),
            'description' => $description,
        ]);
    }
}
