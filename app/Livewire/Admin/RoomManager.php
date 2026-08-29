<?php

namespace App\Livewire\Admin;

use App\Models\Hospitalization;
use App\Models\Room;
use App\Models\Service;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Salles d'hospitalisation (v3.2.1, point 11).
 *
 * L'occupation affichee est comptee a la volee sur les hospitalisations
 * actives : aucune colonne d'occupation a tenir a jour.
 */
class RoomManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?int $service_id = null;

    public ?int $capacity = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['name' => 'nom de la salle', 'service_id' => 'service', 'capacity' => 'nombre de lits'];
    }

    public function edit(int $roomId): void
    {
        $room = Room::findOrFail($roomId);

        $this->editingId = $room->getKey();
        $this->name = $room->name;
        $this->service_id = $room->service_id;
        $this->capacity = $room->capacity;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'service_id', 'capacity']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            $room = Room::findOrFail($this->editingId);
            $room->update($data);

            session()->flash('admin.status', 'Salle mise a jour.');
        } else {
            $room = Room::create($data);

            Audit::log(
                Audit::EVENT_CREATED,
                sprintf('Salle « %s » creee (%d lits).', $room->name, $room->capacity),
                $room,
            );

            session()->flash('admin.status', 'Salle creee.');
        }

        $this->cancel();
    }

    /**
     * Une salle qui heberge encore quelqu'un n'est pas supprimable — meme
     * garde-fou que pour un service ou un type.
     */
    public function delete(int $roomId): void
    {
        $room = Room::findOrFail($roomId);

        $actives = $room->hospitalizations()->where('status', Hospitalization::STATUS_ACTIVE)->count();

        if ($actives > 0) {
            session()->flash('admin.error', sprintf(
                'La salle « %s » accueille %d patient(s) : elle ne peut pas etre supprimee.',
                $room->name,
                $actives,
            ));

            return;
        }

        $nom = $room->name;
        $room->delete();

        Audit::log(Audit::EVENT_DELETED, sprintf('Salle « %s » supprimee.', $nom));

        session()->flash('admin.status', 'Salle supprimee.');
    }

    public function render(): View
    {
        return view('livewire.admin.room-manager', [
            'rooms' => Room::with('service')
                ->withCount(['hospitalizations as occupancy_count' => fn ($q) => $q->where('status', Hospitalization::STATUS_ACTIVE)])
                ->orderBy('name')
                ->get(),
            'services' => Service::careServices()->orderBy('name')->get(),
        ]);
    }
}
