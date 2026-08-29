<?php

namespace App\Livewire\Staff;

use App\Actions\CallNextPatient;
use App\Actions\CloseVisit;
use App\Actions\SendReferral;
use App\Models\Service;
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
 * File d'attente d'un type de personnel generique (v3.2.1, point 10).
 *
 * Aucune logique metier neuve : les memes Actions que l'interface medecin —
 * CallNextPatient, SendReferral, CloseVisit — pilotees ici par les capacites
 * cochees sur le type. Une capacite absente ne rend pas une section vide : elle
 * ne rend rien du tout.
 */
class StaffQueue extends Component
{
    /** Visite selectionnee pour un renvoi. */
    public ?int $referringVisitId = null;

    public ?int $toServiceId = null;

    public string $instructions = '';

    #[On('file-mise-a-jour')]
    public function refreshQueue(): void
    {
        // Un nouveau rendu suffit.
    }

    /**
     * Le rattachement du compte connecte. Tout passe par lui : un membre du
     * personnel n'agit que dans le service ou il exerce.
     */
    private function member(): StaffMember
    {
        $member = Auth::user()?->staffMember?->load(['staffType', 'service']);

        abort_unless($member && $member->service_id, 403, "Aucun service n'est rattache a votre compte.");

        return $member;
    }

    private function type(): StaffType
    {
        return $this->member()->staffType;
    }

    private function service(): Service
    {
        return $this->member()->service;
    }

    /** Refuse net une action dont la capacite n'est pas cochee sur le type. */
    private function assertCan(string $capability): void
    {
        abort_unless($this->type()->can($capability), 403, 'Cette action ne releve pas de votre fonction.');
    }

    public function callNext(CallNextPatient $action): void
    {
        $this->assertCan(StaffType::CAP_QUEUE);

        // Meme action que pour un medecin : le membre du personnel appelle en
        // son nom propre, sans etre enregistre comme praticien.
        $visit = $action->execute($this->service(), Auth::user());

        session()->flash(
            'staff.status',
            $visit
                ? sprintf('Ticket n° %d appele : %s.', $visit->token, $visit->patient->label())
                : 'Aucun patient en attente dans cette file.'
        );

        $this->dispatch('file-mise-a-jour');
    }

    public function startReferral(int $visitId): void
    {
        $this->assertCan(StaffType::CAP_SEND_REFERRAL);

        $this->referringVisitId = $visitId;
        $this->toServiceId = null;
        $this->instructions = '';
        $this->resetValidation();
    }

    public function cancelReferral(): void
    {
        $this->reset(['referringVisitId', 'toServiceId', 'instructions']);
        $this->resetValidation();
    }

    public function sendReferral(SendReferral $action): void
    {
        $this->assertCan(StaffType::CAP_SEND_REFERRAL);

        $this->validate([
            'referringVisitId' => ['required', 'integer', 'exists:visits,id'],
            'toServiceId' => ['required', 'integer', 'exists:services,id'],
            'instructions' => ['required', 'string', 'min:3', 'max:2000'],
        ], attributes: [
            'referringVisitId' => 'patient',
            'toServiceId' => 'service destinataire',
            'instructions' => 'instructions',
        ]);

        $visit = $this->visitInMyService($this->referringVisitId);

        try {
            $action->execute(
                $visit,
                $this->member(),
                Service::findOrFail($this->toServiceId),
                $this->instructions,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['toServiceId' => $e->getMessage()]);
        }

        session()->flash('staff.status', 'Patient envoye vers le service choisi.');

        $this->cancelReferral();
        $this->dispatch('file-mise-a-jour');
    }

    public function closeVisit(int $visitId, CloseVisit $action): void
    {
        $this->assertCan(StaffType::CAP_CLOSE_VISIT);

        try {
            $action->execute($this->visitInMyService($visitId), $this->member());
        } catch (InvalidArgumentException $e) {
            session()->flash('staff.error', $e->getMessage());

            return;
        }

        session()->flash('staff.status', 'Dossier cloture.');
        $this->dispatch('file-mise-a-jour');
    }

    public function showRecord(int $patientId): void
    {
        $this->assertCan(StaffType::CAP_VIEW_DOSSIER);

        $this->dispatch('afficher-dossier', patientId: $patientId);
    }

    /**
     * Une visite hors de mon service n'existe pas de mon point de vue — meme
     * garde-fou que le trait ScopedToOwnService cote medecin.
     */
    private function visitInMyService(?int $visitId): Visit
    {
        return Visit::with('patient')
            ->where('service_id', $this->service()->getKey())
            ->findOrFail($visitId);
    }

    public function render(): View
    {
        $type = $this->type();
        $service = $this->service();

        return view('livewire.staff.staff-queue', [
            'type' => $type,
            'service' => $service,
            'queue' => Visit::query()
                ->with(['patient', 'pendingNextService'])
                ->inTodaysQueue($service->getKey())
                ->orderByRaw("CASE status WHEN 'called' THEN 0 WHEN 'waiting' THEN 1 ELSE 2 END")
                ->orderBy('token')
                ->get(),
            'otherServices' => $type->can(StaffType::CAP_SEND_REFERRAL)
                ? Service::careServices()->where('id', '!=', $service->getKey())->orderBy('name')->get()
                : collect(),
        ]);
    }
}
