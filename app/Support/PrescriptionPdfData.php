<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\Prescription;
use App\Models\Setting;

/**
 * Les donnees du gabarit d'ordonnance, en un seul endroit (v3.2.9, point 2).
 *
 * Deux controleurs rendent ce PDF — celui du medecin et celui du portail
 * patient. Sans point commun, l'un aurait fini par afficher un en-tete que
 * l'autre ignore, et le patient n'aurait pas vu la meme ordonnance que son
 * medecin.
 */
final class PrescriptionPdfData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Prescription $prescription): array
    {
        $medecin = $prescription->doctor;

        return [
            'prescription' => $prescription,
            'productName' => config('keneya.name'),

            // En-tete : tout vient de `settings`, rien du gabarit.
            'hospitalName' => hospital_name(),
            'hospitalAddress' => Setting::get(Setting::HOSPITAL_ADDRESS),
            'hospitalPhone' => Setting::get(Setting::HOSPITAL_PHONE),
            'hospitalEmail' => Setting::get(Setting::HOSPITAL_EMAIL),
            'hospitalWebsite' => Setting::get(Setting::HOSPITAL_WEBSITE),
            'hospitalHours' => Setting::get(Setting::HOSPITAL_HOURS),
            'hospitalMotto' => Setting::get(Setting::HOSPITAL_MOTTO),

            // Signature et tampons : des images encodees dans la page, ou null.
            // C'est le modele qui verifie que le fichier est bien la — un
            // chemin mort ferait echouer dompdf, et une ordonnance qu'on ne
            // peut plus imprimer serait pire qu'une signature absente.
            //
            // Encodees et non pointees par un chemin : le PDF s'accommodait
            // d'un chemin de fichier, la vue imprimable non — elle est lue par
            // un navigateur, qui n'a acces ni au disque ni a ces fichiers,
            // ranges hors de `public/`. Une seule forme sert donc les deux
            // rendus, ce qui est la raison d'etre de cette classe.
            'hospitalStamp' => Doctor::fichierEncode(Setting::get(Setting::HOSPITAL_STAMP_PATH)),
            'doctorSignature' => $medecin?->signatureFile(),
            'doctorStamp' => $medecin?->stampFile(),
        ];
    }
}
