<?php

namespace App\Livewire\Admin;

use App\Models\StaffType;
use App\Support\Audit;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Types de personnel administrables (v3.2.1, point 10).
 *
 * Deux chemins a la creation, et l'admin doit voir lequel il emprunte :
 *  - un type adosse a un role fixe reutilise une interface deja construite ;
 *  - un type sans role recoit /staff/{slug}, avec les seules sections cochees.
 *
 * L'apercu affiche en permanence la liste des sections qui apparaitront
 * reellement : l'admin doit comprendre ce qu'il cree avant qu'un membre du
 * personnel ne s'y connecte.
 */
class StaffTypeManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    /** '' = interface generique ; sinon un des trois roles reutilisables. */
    public string $matched_role = '';

    /** @var array<int, string> */
    public array $capabilities = [];

    /**
     * Les roles qu'un type peut reutiliser. `admin` en est volontairement
     * absent : l'administrateur n'a pas vocation a etre multiplie.
     *
     * @return array<string, string>
     */
    public function reusableRoles(): array
    {
        return [
            Roles::DOCTOR => Roles::label(Roles::DOCTOR),
            Roles::RECEPTIONIST => Roles::label(Roles::RECEPTIONIST),
            Roles::CASHIER => Roles::label(Roles::CASHIER),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'matched_role' => ['nullable', Rule::in(array_keys($this->reusableRoles()))],
            'capabilities' => ['array'],
            'capabilities.*' => [Rule::in(array_keys(StaffType::CAPABILITIES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['name' => 'nom du type', 'matched_role' => 'role reutilise', 'capabilities' => 'fonctions'];
    }

    /**
     * L'apercu des sections que ce type verra reellement, recalcule a chaque
     * case cochee.
     *
     * @return array<int, string>
     */
    public function previewSections(): array
    {
        if ($this->matched_role !== '') {
            return [];
        }

        $sections = [];

        foreach (StaffType::CAPABILITIES as $capability => $meta) {
            if (in_array($capability, $this->capabilities, true)) {
                $sections[] = $meta['section'];
            }
        }

        // Le planning personnel est offert a tout le monde, sans capacite.
        $sections[] = 'Mon planning';

        return $sections;
    }

    public function edit(int $typeId): void
    {
        $type = StaffType::findOrFail($typeId);

        $this->editingId = $type->getKey();
        $this->name = $type->name;
        $this->matched_role = (string) $type->matched_role;
        $this->capabilities = $type->capabilities ?? [];
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'matched_role', 'capabilities']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate();

        $adosse = ($data['matched_role'] ?? '') !== '';

        $attributs = [
            'name' => $data['name'],
            'matched_role' => $adosse ? $data['matched_role'] : null,
            // Un type adosse a un role n'a ni slug ni capacites : son interface
            // existe deja et n'est pas composable.
            'capabilities' => $adosse ? null : array_values($data['capabilities'] ?? []),
        ];

        if ($this->editingId) {
            $type = StaffType::findOrFail($this->editingId);

            $attributs['slug'] = $adosse
                ? null
                : ($type->slug ?: StaffType::makeSlug($data['name'], $type->getKey()));

            $type->update($attributs);

            Audit::log(
                Audit::EVENT_STAFF_TYPE_UPDATED,
                sprintf('Type de personnel « %s » modifie.', $type->name),
                $type,
            );

            session()->flash('admin.status', 'Type de personnel mis a jour.');
        } else {
            $attributs['slug'] = $adosse ? null : StaffType::makeSlug($data['name']);

            $type = StaffType::create($attributs);

            Audit::log(
                Audit::EVENT_STAFF_TYPE_CREATED,
                $adosse
                    ? sprintf('Type de personnel « %s » cree, adosse au role %s.', $type->name, $type->matched_role)
                    : sprintf(
                        'Type de personnel « %s » cree avec son interface /staff/%s (%d fonction(s)).',
                        $type->name,
                        $type->slug,
                        count($type->capabilities ?? []),
                    ),
                $type,
            );

            session()->flash('admin.status', 'Type de personnel cree.');
        }

        $this->cancel();
        $this->dispatch('types-de-personnel-mis-a-jour');
    }

    /**
     * Meme garde-fou que partout ailleurs : un type encore porte par quelqu'un
     * n'est pas supprimable.
     */
    public function delete(int $typeId): void
    {
        $type = StaffType::withCount('members')->findOrFail($typeId);

        if ($type->members_count > 0) {
            session()->flash('admin.error', sprintf(
                'Le type « %s » ne peut pas etre supprime : %d personne(s) le portent encore.',
                $type->name,
                $type->members_count,
            ));

            return;
        }

        $nom = $type->name;
        $type->delete();

        Audit::log(Audit::EVENT_STAFF_TYPE_DELETED, sprintf('Type de personnel « %s » supprime.', $nom));

        session()->flash('admin.status', 'Type de personnel supprime.');
        $this->dispatch('types-de-personnel-mis-a-jour');
    }

    public function render(): View
    {
        return view('livewire.admin.staff-type-manager', [
            'types' => StaffType::withCount('members')->orderBy('name')->get(),
            'allCapabilities' => StaffType::CAPABILITIES,
        ]);
    }
}
