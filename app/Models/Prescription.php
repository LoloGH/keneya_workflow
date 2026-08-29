<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['patient_id', 'visit_id', 'doctor_id', 'content'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Decoupe le contenu libre de l'ordonnance en lignes exploitables pour
     * l'affichage : une entree par medicament, debarrassee de la numerotation
     * eventuellement saisie par le praticien pour ne pas doubler celle de la
     * liste.
     *
     * Le premier fragment est le medicament ; les suivants (posologie, duree,
     * remarque) sont restitues tels quels, sans leur inventer d'etiquette : le
     * champ de saisie est libre, et decider que « 1/2 » designe une posologie
     * plutot qu'une duree serait un contresens clinique.
     *
     * @return array<int, array{medicament: string, precisions: array<int, string>}>
     */
    public function lines(): array
    {
        $lignes = preg_split('/\R/u', (string) $this->content) ?: [];
        $resultat = [];

        foreach ($lignes as $ligne) {
            $ligne = preg_replace('/^\s*\d+\s*[.)\x{00B0}-]\s*/u', '', trim($ligne)) ?? '';

            $fragments = array_values(array_filter(
                array_map('trim', preg_split('/\s*(?:\x{2014}|\x{2013}|\||;|\s-\s)\s*/u', $ligne) ?: []),
                static fn (string $fragment): bool => $fragment !== '',
            ));

            if ($fragments === []) {
                continue;
            }

            $resultat[] = [
                'medicament' => array_shift($fragments),
                'precisions' => $fragments,
            ];
        }

        return $resultat;
    }

    public static function auditLabel(): string
    {
        return 'Ordonnance';
    }
}
