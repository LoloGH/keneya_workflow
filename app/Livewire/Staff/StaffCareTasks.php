<?php

namespace App\Livewire\Staff;

use App\Actions\CompleteCareTask;
use App\Models\CareTask;
use App\Models\Hospitalization;
use App\Models\StaffMember;
use App\Models\StaffType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Soins programmes vus par le personnel de garde (v3.2.1, point 11).
 *
 * **Filtrage « de garde »** : la liste se limite aux services pour lesquels le
 * compte connecte a une ligne `schedules` couvrant l'heure actuelle. Pas
 * d'acces a l'ensemble des soins de l'hopital — uniquement ceux du service ou
 * l'on est effectivement present.
 *
 * L'assignation nommee par le medecin reste une **priorite d'affichage**, pas
 * une restriction : un soin ne doit jamais rester bloque parce que la personne
 * designee est absente.
 */
class StaffCareTasks extends Component
{
    /** Inclure les soins deja traites, pour relire la journee. */
    public bool $showDone = false;

    public function mount(): void
    {
        $this->member();
    }

    private function member(): StaffMember
    {
        $member = Auth::user()?->staffMember?->load(['staffType', 'service']);

        abort_unless($member && $member->service_id, 403, "Aucun service n'est rattache a votre compte.");
        abort_unless($member->staffType->can(StaffType::CAP_CARE_TASKS), 403, 'Cette action ne releve pas de votre fonction.');

        return $member;
    }

    /** De garde, maintenant, sur le service de rattachement ? */
    public function onDuty(): bool
    {
        return Auth::user()->isOnDutyFor($this->member()->service_id);
    }

    public function markDone(int $taskId, CompleteCareTask $action): void
    {
        $task = $this->taskInMyService($taskId);

        try {
            $action->execute($task, Auth::user());
        } catch (InvalidArgumentException $e) {
            session()->flash('staff.error', $e->getMessage());

            return;
        }

        session()->flash('staff.status', 'Soin marque comme fait.');
    }

    public function markMissed(int $taskId, CompleteCareTask $action): void
    {
        $task = $this->taskInMyService($taskId);

        try {
            $action->markMissed($task, Auth::user());
        } catch (InvalidArgumentException $e) {
            session()->flash('staff.error', $e->getMessage());

            return;
        }

        session()->flash('staff.status', 'Soin marque comme manque.');
    }

    /** Un soin d'un autre service n'existe pas de mon point de vue. */
    private function taskInMyService(int $taskId): CareTask
    {
        $member = $this->member();

        return CareTask::with(['type', 'hospitalization.patient', 'hospitalization.visit'])
            ->whereHas('hospitalization', fn ($q) => $q->where('service_id', $member->service_id))
            ->findOrFail($taskId);
    }

    public function render(): View
    {
        $member = $this->member();
        $onDuty = $this->onDuty();

        // Hors garde, la liste reste vide : on ne consulte pas les soins d'un
        // service ou l'on n'est pas present.
        $tasks = $onDuty
            ? CareTask::query()
                ->with(['type', 'hospitalization.patient', 'hospitalization.room', 'assignedTo', 'completedBy'])
                ->whereHas(
                    'hospitalization',
                    fn ($q) => $q->where('service_id', $member->service_id)
                        ->where('status', Hospitalization::STATUS_ACTIVE),
                )
                ->when(! $this->showDone, fn ($q) => $q->where('status', CareTask::STATUS_PENDING))
                ->orderBy('scheduled_at')
                ->limit(100)
                ->get()
            : collect();

        return view('livewire.staff.staff-care-tasks', [
            'tasks' => $tasks,
            'onDuty' => $onDuty,
            'service' => $member->service,
            'myUserId' => Auth::id(),
        ]);
    }
}
