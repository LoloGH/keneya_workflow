<?php

namespace App\Livewire\Staff;

use App\Actions\CompleteReferral;
use App\Models\Referral;
use App\Models\StaffMember;
use App\Models\StaffType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Renvois recus par un type de personnel generique (v3.2.1, point 10).
 *
 * Meme Action que cote medecin : saisir le resultat renvoie le patient dans la
 * file du prescripteur, sans repasser par la caisse.
 */
class StaffIncomingReferrals extends Component
{
    public ?int $answeringReferralId = null;

    public string $resultText = '';

    /**
     * La capacite est verifiee des le montage, pas seulement a l'action : un
     * composant qu'on n'a pas le droit d'utiliser ne doit meme pas s'afficher.
     */
    public function mount(): void
    {
        $this->member();
    }

    #[On('file-mise-a-jour')]
    public function refreshPanel(): void
    {
        // Un nouveau rendu suffit.
    }

    private function member(): StaffMember
    {
        $member = Auth::user()?->staffMember?->load(['staffType', 'service']);

        abort_unless($member && $member->service_id, 403, "Aucun service n'est rattache a votre compte.");
        abort_unless($member->staffType->can(StaffType::CAP_RECEIVE_REFERRAL), 403, 'Cette action ne releve pas de votre fonction.');

        return $member;
    }

    public function startAnswer(int $referralId): void
    {
        $this->answeringReferralId = $referralId;
        $this->resultText = '';
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['answeringReferralId', 'resultText']);
        $this->resetValidation();
    }

    public function submitResult(CompleteReferral $action): void
    {
        $member = $this->member();

        $this->validate([
            'answeringReferralId' => ['required', 'integer', 'exists:referrals,id'],
            'resultText' => ['required', 'string', 'min:3', 'max:5000'],
        ], attributes: ['answeringReferralId' => 'renvoi', 'resultText' => 'resultat']);

        // Un renvoi adresse a un autre service n'existe pas de mon point de vue.
        $referral = Referral::where('to_service_id', $member->service_id)
            ->findOrFail($this->answeringReferralId);

        try {
            $action->execute($referral, $member, $this->resultText);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['resultText' => $e->getMessage()]);
        }

        session()->flash('staff.status', 'Resultat saisi : le patient repart vers le service prescripteur.');

        $this->cancel();
        $this->dispatch('file-mise-a-jour');
    }

    public function render(): View
    {
        $member = $this->member();

        return view('livewire.staff.staff-incoming-referrals', [
            'referrals' => Referral::query()
                ->with(['patient', 'fromService', 'fromDoctor.user', 'fromStaffMember.user'])
                ->where('to_service_id', $member->service_id)
                ->where('status', Referral::STATUS_PENDING)
                ->orderBy('id')
                ->get(),
        ]);
    }
}
