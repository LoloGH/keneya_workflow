<?php

namespace App\Livewire\Staff;

use App\Actions\RecordPayment;
use App\Models\Payment;
use App\Models\StaffMember;
use App\Models\StaffType;
use App\Models\Visit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Encaissement pour un type de personnel generique (v3.2.1, point 10).
 *
 * A distinguer de l'interface /caisse : il ne s'agit pas de liberer une visite
 * en attente de paiement — ce mecanisme reste la propriete du role `cashier` —
 * mais d'encaisser un acte realise dans son propre service.
 */
class StaffPayments extends Component
{
    public ?int $payingVisitId = null;

    public ?int $amount = null;

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
        abort_unless($member->staffType->can(StaffType::CAP_ACCEPT_PAYMENT), 403, 'Cette action ne releve pas de votre fonction.');

        return $member;
    }

    public function startPayment(int $visitId): void
    {
        $this->payingVisitId = $visitId;
        $this->amount = null;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['payingVisitId', 'amount']);
        $this->resetValidation();
    }

    public function record(RecordPayment $action): void
    {
        $member = $this->member();

        $this->validate([
            'payingVisitId' => ['required', 'integer', 'exists:visits,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:9999999999'],
        ], attributes: ['payingVisitId' => 'patient', 'amount' => 'montant']);

        $visit = Visit::where('service_id', $member->service_id)->findOrFail($this->payingVisitId);

        try {
            $action->execute(
                $visit,
                Auth::user(),
                Payment::TYPE_SERVICE,
                (int) $this->amount,
                $member->service_id,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        session()->flash('staff.status', sprintf(
            '%s FCFA encaisse pour %s.',
            number_format((int) $this->amount, 0, ',', ' '),
            $visit->patient->name,
        ));

        $this->cancel();
        $this->dispatch('file-mise-a-jour');
    }

    public function render(): View
    {
        $member = $this->member();

        return view('livewire.staff.staff-payments', [
            'queue' => Visit::query()
                ->with('patient')
                ->inTodaysQueue($member->service_id)
                ->orderBy('token')
                ->get(),
            'todayTotal' => (int) Payment::query()
                ->where('status', Payment::STATUS_PAID)
                ->whereDate('created_at', today())
                ->where('recorded_by_user_id', Auth::id())
                ->sum('amount'),
        ]);
    }
}
