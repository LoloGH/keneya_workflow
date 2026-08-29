<?php

namespace App\Livewire\Admin;

use App\Models\ServiceKind;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Types de service administrables (v3.2.1, point 10).
 *
 * L'admin cree ses propres types sans intervention de developpeur. Le seul
 * comportement porte par un type est `requires_payment_gate` : un renvoi vers un
 * service de ce type passe par la Caisse Services avant realisation.
 *
 * Les trois types d'origine sont modifiables — leur libelle, leur peage — mais
 * pas supprimables : du code s'appuie sur leur slug.
 */
class ServiceKindManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public bool $requires_payment_gate = false;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'requires_payment_gate' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['name' => 'nom du type', 'requires_payment_gate' => 'paiement prealable'];
    }

    public function edit(int $kindId): void
    {
        $kind = ServiceKind::findOrFail($kindId);

        $this->editingId = $kind->getKey();
        $this->name = $kind->name;
        $this->requires_payment_gate = $kind->requires_payment_gate;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'requires_payment_gate']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            $kind = ServiceKind::findOrFail($this->editingId);

            // Le slug d'un type d'origine est fige : c'est la cle que le code
            // interroge. Renommer « Plateau technique » reste possible sans
            // rien casser.
            $kind->update($kind->isBuiltIn()
                ? $data
                : $data + ['slug' => ServiceKind::makeSlug($data['name'], $kind->getKey())]);

            Audit::log(
                Audit::EVENT_SERVICE_KIND_UPDATED,
                sprintf(
                    'Type de service « %s » modifie (paiement prealable : %s).',
                    $kind->name,
                    $kind->requires_payment_gate ? 'oui' : 'non',
                ),
                $kind,
            );

            session()->flash('admin.status', 'Type de service mis a jour.');
        } else {
            $kind = ServiceKind::create($data + ['slug' => ServiceKind::makeSlug($data['name'])]);

            Audit::log(
                Audit::EVENT_SERVICE_KIND_CREATED,
                sprintf(
                    'Type de service « %s » cree (paiement prealable : %s).',
                    $kind->name,
                    $kind->requires_payment_gate ? 'oui' : 'non',
                ),
                $kind,
            );

            session()->flash('admin.status', 'Type de service cree.');
        }

        $this->cancel();
        $this->dispatch('types-de-service-mis-a-jour');
    }

    /**
     * Meme garde-fou que pour la suppression d'un service : un type encore
     * utilise laisserait des services sans type.
     */
    public function delete(int $kindId): void
    {
        $kind = ServiceKind::withCount('services')->findOrFail($kindId);

        if ($kind->isBuiltIn()) {
            session()->flash('admin.error', sprintf(
                'Le type « %s » est pose a l\'installation et ne peut pas etre supprime.',
                $kind->name,
            ));

            return;
        }

        if ($kind->services_count > 0) {
            session()->flash('admin.error', sprintf(
                'Le type « %s » ne peut pas etre supprime : %d service(s) l\'utilisent encore.',
                $kind->name,
                $kind->services_count,
            ));

            return;
        }

        $nom = $kind->name;
        $kind->delete();

        Audit::log(Audit::EVENT_SERVICE_KIND_DELETED, sprintf('Type de service « %s » supprime.', $nom));

        session()->flash('admin.status', 'Type de service supprime.');
        $this->dispatch('types-de-service-mis-a-jour');
    }

    public function render(): View
    {
        return view('livewire.admin.service-kind-manager', [
            'kinds' => ServiceKind::withCount('services')->orderBy('name')->get(),
        ]);
    }
}
