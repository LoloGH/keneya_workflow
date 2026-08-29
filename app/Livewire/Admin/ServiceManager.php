<?php

namespace App\Livewire\Admin;

use App\Models\Service;
use App\Models\ServiceKind;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Gestion des services de l'etablissement (creation, renommage, type).
 */
class ServiceManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    /** Le type est desormais une ligne administrable, plus une valeur d'enum. */
    public ?int $service_kind_id = null;

    #[On('types-de-service-mis-a-jour')]
    public function refreshKinds(): void
    {
        // Un nouveau rendu suffit : la liste des types est relue a chaque rendu.
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Une caisse ne se cree pas a la main : les deux caisses sont
            // posees par le seeder et leur mecanique est cablee au routage.
            'service_kind_id' => [
                'required',
                'integer',
                'exists:service_kinds,id',
                function (string $attribut, $valeur, callable $refuser) {
                    if (ServiceKind::whereKey($valeur)->value('slug') === ServiceKind::SLUG_CAISSE) {
                        $refuser('Le type « Caisse » est reserve aux deux caisses de l\'etablissement.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['name' => 'nom du service', 'service_kind_id' => 'type de service'];
    }

    public function edit(int $serviceId): void
    {
        $service = Service::findOrFail($serviceId);

        $this->editingId = $service->getKey();
        $this->name = $service->name;
        $this->service_kind_id = $service->service_kind_id;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'service_kind_id']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            $service = Service::findOrFail($this->editingId);
            $service->update($data);

            Audit::log(Audit::EVENT_SERVICE_UPDATED, sprintf('Service « %s » modifie.', $service->name), $service);
            session()->flash('admin.status', 'Service mis a jour.');
        } else {
            $service = Service::create($data);

            Audit::log(Audit::EVENT_SERVICE_CREATED, sprintf('Service « %s » cree.', $service->name), $service);
            session()->flash('admin.status', 'Service cree.');
        }

        $this->cancel();
        $this->dispatch('services-mis-a-jour');
    }

    /**
     * Un service encore rattache a un medecin ou a une visite n'est pas
     * supprimable : on romprait des references du dossier patient.
     */
    public function delete(int $serviceId): void
    {
        $service = Service::withCount(['doctors', 'visits'])->findOrFail($serviceId);

        if ($service->doctors_count > 0 || $service->visits_count > 0) {
            session()->flash('admin.error', sprintf(
                'Le service « %s » ne peut pas etre supprime : il compte %d medecin(s) et %d passage(s).',
                $service->name,
                $service->doctors_count,
                $service->visits_count,
            ));

            return;
        }

        $name = $service->name;
        $service->delete();

        Audit::log(Audit::EVENT_SERVICE_DELETED, sprintf('Service « %s » supprime.', $name));

        session()->flash('admin.status', 'Service supprime.');
        $this->dispatch('services-mis-a-jour');
    }

    public function render(): View
    {
        return view('livewire.admin.service-manager', [
            'services' => Service::with('serviceKind')
                ->withCount(['doctors', 'visits'])
                ->orderBy('name')
                ->get(),
            // Les caisses ne sont pas proposees : elles ne se creent pas a la main.
            'kinds' => ServiceKind::where('slug', '!=', ServiceKind::SLUG_CAISSE)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
