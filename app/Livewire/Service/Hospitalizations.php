<?php

namespace App\Livewire\Service;

use App\Actions\AdmitPatient;
use App\Actions\DischargePatient;
use App\Actions\PrescribeCareTasks;
use App\Livewire\Service\Concerns\ScopedToOwnService;
use App\Models\CareTask;
use App\Models\CareTaskType;
use App\Models\Hospitalization;
use App\Models\Room;
use App\Models\StaffType;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Patients hospitalises du service (v3.2.1, point 11).
 *
 * Section distincte de la file d'attente et de « Mes patients » : un patient
 * hospitalise n'attend pas un tour, il occupe un lit.
 */
class Hospitalizations extends Component
{
    use ScopedToOwnService;

    /** Visite en cours d'admission. */
    public ?int $admittingVisitId = null;

    public ?int $roomId = null;

    /** L'admission au-dela de la capacite demande une confirmation explicite. */
    public bool $overCapacityConfirmed = false;

    /** Hospitalisation pour laquelle on prescrit des soins. */
    public ?int $prescribingId = null;

    public ?int $careTaskTypeId = null;

    public string $careInstructions = '';

    public string $careStartsAt = '';

    public int $careIntervalHours = 8;

    public int $careDurationDays = 3;

    /**
     * Assignation nommee, facultative : le medecin peut designer quelqu'un dans
     * un cas precis. Ce n'est jamais une restriction d'acces — voir
     * StaffCareTasks — mais une priorite d'affichage.
     */
    public ?int $careAssignedToUserId = null;

    #[On('service-change')]
    public function handleServiceChange(int $serviceId): void
    {
        $this->onServiceChanged($serviceId);
    }

    protected function resetServiceState(): void
    {
        $this->cancelAdmission();
        $this->cancelPrescription();
    }

    // ------------------------------------------------------------ Admission

    public function startAdmission(int $visitId): void
    {
        $this->admittingVisitId = $visitId;
        $this->roomId = null;
        $this->overCapacityConfirmed = false;
        $this->resetValidation();
    }

    public function cancelAdmission(): void
    {
        $this->reset(['admittingVisitId', 'roomId', 'overCapacityConfirmed']);
        $this->resetValidation();
    }

    public function admit(AdmitPatient $action): void
    {
        $this->validate([
            'admittingVisitId' => ['required', 'integer', 'exists:visits,id'],
            'roomId' => ['nullable', 'integer', 'exists:rooms,id'],
        ], attributes: ['admittingVisitId' => 'patient', 'roomId' => 'salle']);

        $visit = Visit::where('service_id', $this->serviceId)->findOrFail($this->admittingVisitId);
        $room = $this->roomId ? Room::findOrFail($this->roomId) : null;

        try {
            $action->execute($visit, $this->currentDoctor(), $room, $this->overCapacityConfirmed);
        } catch (InvalidArgumentException $e) {
            // Salle pleine : on n'interdit pas, on demande confirmation.
            throw ValidationException::withMessages(['roomId' => $e->getMessage()]);
        }

        session()->flash('service.status', sprintf('%s hospitalise.', $visit->patient->name));

        $this->cancelAdmission();
        $this->dispatch('file-mise-a-jour');
    }

    // ------------------------------------------------------- Soins prescrits

    public function startPrescription(int $hospitalizationId): void
    {
        $this->prescribingId = $hospitalizationId;
        $this->careTaskTypeId = null;
        $this->careInstructions = '';
        $this->careStartsAt = now()->addHour()->startOfHour()->format('Y-m-d\TH:i');
        $this->careIntervalHours = 8;
        $this->careDurationDays = 3;
        $this->careAssignedToUserId = null;
        $this->resetValidation();
    }

    public function cancelPrescription(): void
    {
        $this->reset(['prescribingId', 'careTaskTypeId', 'careInstructions', 'careStartsAt', 'careAssignedToUserId']);
        $this->resetValidation();
    }

    public function prescribe(PrescribeCareTasks $action): void
    {
        $this->validate([
            'prescribingId' => ['required', 'integer', 'exists:hospitalizations,id'],
            'careTaskTypeId' => ['required', 'integer', 'exists:care_task_types,id'],
            'careInstructions' => ['nullable', 'string', 'max:2000'],
            'careStartsAt' => ['required', 'date'],
            'careIntervalHours' => ['required', 'integer', 'min:1', 'max:168'],
            'careDurationDays' => ['required', 'integer', 'min:1', 'max:60'],
            'careAssignedToUserId' => ['nullable', 'integer', 'exists:users,id'],
        ], attributes: [
            'careTaskTypeId' => 'type de soin',
            'careStartsAt' => 'debut',
            'careIntervalHours' => 'intervalle',
            'careDurationDays' => 'duree',
        ]);

        $hospitalization = Hospitalization::where('service_id', $this->serviceId)
            ->findOrFail($this->prescribingId);

        try {
            $crees = $action->execute(
                hospitalization: $hospitalization,
                type: CareTaskType::findOrFail($this->careTaskTypeId),
                doctor: $this->currentDoctor(),
                start: Carbon::parse($this->careStartsAt),
                intervalHours: $this->careIntervalHours,
                durationDays: $this->careDurationDays,
                instructions: $this->careInstructions ?: null,
                assignedTo: $this->careAssignedToUserId
                    ? User::find($this->careAssignedToUserId)
                    : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['careIntervalHours' => $e->getMessage()]);
        }

        session()->flash('service.status', sprintf('%d administration(s) programmee(s).', $crees));

        $this->cancelPrescription();
    }

    // ---------------------------------------------------------------- Sortie

    public function discharge(int $hospitalizationId, DischargePatient $action): void
    {
        $hospitalization = Hospitalization::where('service_id', $this->serviceId)
            ->findOrFail($hospitalizationId);

        try {
            $action->execute($hospitalization, $this->currentDoctor());
        } catch (InvalidArgumentException $e) {
            session()->flash('service.error', $e->getMessage());

            return;
        }

        session()->flash('service.status', 'Sortie d\'hospitalisation enregistree.');
    }

    public function render(): View
    {
        return view('livewire.service.hospitalizations', [
            'hospitalizations' => Hospitalization::query()
                ->with(['patient', 'room', 'admittedByDoctor.user'])
                ->withCount([
                    'careTasks as pending_care_tasks_count' => fn ($q) => $q->where('status', CareTask::STATUS_PENDING),
                ])
                ->where('service_id', $this->serviceId)
                ->active()
                ->orderByDesc('admitted_at')
                ->get(),
            // Les patients appeles, candidats a une admission.
            'callable' => Visit::query()
                ->with('patient')
                ->inTodaysQueue($this->serviceId)
                ->where('status', Visit::STATUS_CALLED)
                ->orderBy('token')
                ->get(),
            'rooms' => Room::where('service_id', $this->serviceId)
                ->withCount(['hospitalizations as occupancy_count' => fn ($q) => $q->where('status', Hospitalization::STATUS_ACTIVE)])
                ->orderBy('name')
                ->get(),
            'careTaskTypes' => CareTaskType::orderBy('name')->get(),
            // Le personnel du service capable d'executer un soin : de quoi
            // designer quelqu'un nommement, sans y etre oblige.
            'carers' => User::query()
                ->whereHas('staffMember', fn ($q) => $q->where('service_id', $this->serviceId))
                ->orderBy('name')
                ->get()
                ->filter(fn (User $user) => (bool) $user->staffType()?->can(StaffType::CAP_CARE_TASKS))
                ->values(),
        ]);
    }
}
