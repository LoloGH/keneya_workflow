<?php

namespace App\Livewire\Staff;

use App\Models\Patient;
use App\Models\StaffMember;
use App\Models\StaffType;
use App\Services\PatientTimeline;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dossier patient en lecture pour un type de personnel generique.
 *
 * Meme frise unifiee que /service et /admin — c'est le meme service
 * PatientTimeline — mais sans aucune action d'ecriture : ce type ne prescrit
 * pas, il consulte.
 */
class StaffRecordPanel extends Component
{
    public ?int $patientId = null;

    #[On('afficher-dossier')]
    public function open(int $patientId): void
    {
        $this->patientId = $patientId;
    }

    public function close(): void
    {
        $this->patientId = null;
    }

    private function member(): StaffMember
    {
        $member = Auth::user()?->staffMember?->load('staffType');

        abort_unless($member, 403, "Aucun service n'est rattache a votre compte.");
        abort_unless($member->staffType->can(StaffType::CAP_VIEW_DOSSIER), 403, 'Cette action ne releve pas de votre fonction.');

        return $member;
    }

    public function render(PatientTimeline $timeline): View
    {
        $type = $this->member()->staffType;

        $patient = $this->patientId
            ? Patient::with(['companions', 'visits.service'])->find($this->patientId)
            : null;

        $frise = $patient
            ? $timeline->for($patient)
            : ['episodes' => collect(), 'orphans' => collect()];

        return view('livewire.staff.staff-record-panel', [
            'patient' => $patient,
            'episodes' => $frise['episodes'],
            'orphans' => $frise['orphans'],
            'slug' => $type->slug,
        ]);
    }
}
