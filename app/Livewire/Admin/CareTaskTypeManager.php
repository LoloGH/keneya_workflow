<?php

namespace App\Livewire\Admin;

use App\Models\CareTaskType;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Catalogue des types de soins (v3.2.1, point 11).
 *
 * Meme logique que les types de service : la liste s'enrichira, elle n'a pas
 * sa place figee dans le code.
 */
class CareTaskTypeManager extends Component
{
    public string $name = '';

    public function save(): void
    {
        $data = $this->validate(
            ['name' => ['required', 'string', 'max:255', 'unique:care_task_types,name']],
            attributes: ['name' => 'nom du type de soin'],
        );

        $type = CareTaskType::create($data);

        Audit::log(Audit::EVENT_CREATED, sprintf('Type de soin « %s » cree.', $type->name), $type);

        session()->flash('admin.status', 'Type de soin cree.');

        $this->reset('name');
    }

    public function delete(int $typeId): void
    {
        $type = CareTaskType::withCount('tasks')->findOrFail($typeId);

        if ($type->tasks_count > 0) {
            session()->flash('admin.error', sprintf(
                'Le type « %s » ne peut pas etre supprime : %d soin(s) s\'y rattachent.',
                $type->name,
                $type->tasks_count,
            ));

            return;
        }

        $nom = $type->name;
        $type->delete();

        Audit::log(Audit::EVENT_DELETED, sprintf('Type de soin « %s » supprime.', $nom));

        session()->flash('admin.status', 'Type de soin supprime.');
    }

    public function render(): View
    {
        return view('livewire.admin.care-task-type-manager', [
            'types' => CareTaskType::withCount('tasks')->orderBy('name')->get(),
        ]);
    }
}
