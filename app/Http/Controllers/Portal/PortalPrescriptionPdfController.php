<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Telechargement d'une ordonnance en PDF depuis le portail patient.
 *
 * Memes garde-fous que le telechargement des pieces jointes : l'ordonnance
 * doit appartenir au patient designe par le jeton, et le code a quatre
 * chiffres doit avoir ete valide dans cette session. Sans cela, connaitre
 * l'identifiant d'une ordonnance suffirait a la lire.
 */
class PortalPrescriptionPdfController extends Controller
{
    public function __invoke(Request $request, string $token, Prescription $prescription): Response
    {
        $patient = Patient::where('portal_token', $token)->firstOrFail();

        abort_unless($request->session()->get('portal.'.$patient->getKey()) === true, 403,
            "Saisissez d'abord votre code d'acces.");

        abort_unless((int) $prescription->patient_id === (int) $patient->getKey(), 404);

        $prescription->load(['patient', 'doctor.user', 'visit.service']);

        $pdf = Pdf::loadView('pdf.prescription', [
            'prescription' => $prescription,
            'hospitalName' => hospital_name(),
            'productName' => config('keneya.name'),
        ])->setPaper('a4');

        return $pdf->download(sprintf(
            'ordonnance-%s-%d.pdf',
            $prescription->patient->patient_code,
            $prescription->getKey(),
        ));
    }
}
