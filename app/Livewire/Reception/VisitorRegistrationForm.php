<?php

namespace App\Livewire\Reception;

use App\Actions\RegisterVisitor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServiceKind;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

class VisitorRegistrationForm extends Component
{
    public string $name = '';

    public string $mobile = '';

    public ?int $service_id = null;

    public string $reason = '';

    /** Recherche du patient visite. */
    public string $patientSearch = '';

    public ?int $patient_id = null;

    public ?array $lastRegistered = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            // Requis des qu'un service clinique est choisi : on rend visite a
            // quelqu'un, pas a un couloir. Un service non clinique (une
            // demarche administrative) peut s'en passer.
            'patient_id' => [
                $this->requiresPatient() ? 'required' : 'nullable',
                'integer', 'exists:patients,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom du visiteur',
            'mobile' => 'telephone',
            'service_id' => 'service',
            'reason' => 'motif',
            'patient_id' => 'patient visite',
        ];
    }

    /**
     * Le service choisi est-il clinique ? Determine si le patient visite est
     * obligatoire.
     */
    public function requiresPatient(): bool
    {
        return $this->service_id
            ? (bool) Service::where('id', $this->service_id)
                ->ofKindSlug(ServiceKind::SLUG_CLINIQUE)
                ->exists()
            : false;
    }

    public function selectPatient(int $patientId): void
    {
        $this->patient_id = $patientId;
        $this->patientSearch = '';
        $this->resetValidation('patient_id');
    }

    public function clearPatient(): void
    {
        $this->reset(['patient_id', 'patientSearch']);
    }

    public function save(RegisterVisitor $register): void
    {
        $data = $this->validate();

        try {
            $visitor = $register->execute($data);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['patient_id' => $e->getMessage()]);
        }

        $this->lastRegistered = [
            'visitor_code' => $visitor->visitor_code,
            'name' => $visitor->name,
            'service' => $visitor->service->name,
            'token' => $visitor->token,
            'visited' => $visitor->patient?->name,
            'id' => $visitor->getKey(),
        ];

        $this->reset(['name', 'mobile', 'reason', 'patient_id', 'patientSearch']);

        $this->dispatch('visiteur-enregistre');
    }

    public function render(): View
    {
        $terme = trim($this->patientSearch);

        return view('livewire.reception.visitor-registration-form', [
            // Un visiteur ne se presente pas a une caisse : on ne propose que
            // les services ou l'on peut effectivement rendre visite.
            'services' => Service::careServices()->orderBy('name')->get(),
            'matches' => $terme === '' ? collect() : Patient::query()
                ->where(fn ($q) => $q->where('patient_code', 'like', "%{$terme}%")
                    ->orWhere('name', 'like', "%{$terme}%"))
                ->orderBy('name')->limit(8)->get(),
            'selectedPatient' => $this->patient_id ? Patient::find($this->patient_id) : null,
        ]);
    }
}
