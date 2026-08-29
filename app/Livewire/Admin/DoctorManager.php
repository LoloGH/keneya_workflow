<?php

namespace App\Livewire\Admin;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Models\User;
use App\Support\Audit;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Gestion des medecins : creation du compte, rattachement a un service et
 * reaffectation vers un autre service a tout moment.
 */
class DoctorManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $phone = '';

    public ?int $service_id = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $userId = $this->editingId
            ? Doctor::find($this->editingId)?->user_id
            : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Un medecin ne tient jamais une caisse : la regle exclut ce type
            // de service cote serveur, pas seulement dans la liste deroulante.
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->whereNotIn(
                    'service_kind_id',
                    ServiceKind::where('slug', ServiceKind::SLUG_CAISSE)->pluck('id'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom du medecin',
            'email' => 'adresse e-mail',
            'password' => 'mot de passe',
            'phone' => 'telephone',
            'service_id' => 'service',
        ];
    }

    #[On('services-mis-a-jour')]
    public function refreshServices(): void
    {
        // Nouveau rendu pour recharger la liste des services.
    }

    public function edit(int $doctorId): void
    {
        $doctor = Doctor::with('user')->findOrFail($doctorId);

        $this->editingId = $doctor->getKey();
        $this->name = $doctor->user->name;
        $this->email = $doctor->user->email;
        $this->password = '';
        $this->phone = (string) $doctor->phone;
        $this->service_id = $doctor->service_id;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password', 'phone', 'service_id']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data): void {
            if ($this->editingId) {
                $doctor = Doctor::with('user')->findOrFail($this->editingId);

                $doctor->user->update(array_filter([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'] ?: null,
                ], fn ($value) => $value !== null));

                $previousServiceId = $doctor->service_id;

                // Reaffectation de service : un simple changement de service_id.
                $doctor->update([
                    'service_id' => $data['service_id'],
                    'phone' => $data['phone'] ?: null,
                ]);

                if ((int) $previousServiceId !== (int) $data['service_id']) {
                    Audit::log(
                        Audit::EVENT_DOCTOR_REASSIGNED,
                        sprintf('%s reaffecte vers %s.', $doctor->user->name, $doctor->service()->first()?->name),
                        $doctor,
                    );
                }

                return;
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $user->syncRoles([Roles::DOCTOR]);

            $doctor = Doctor::create([
                'user_id' => $user->getKey(),
                'service_id' => $data['service_id'],
                'phone' => $data['phone'] ?: null,
            ]);

            Audit::log(Audit::EVENT_DOCTOR_CREATED, sprintf('Medecin %s cree.', $user->name), $doctor);
        });

        session()->flash('admin.status', $this->editingId ? 'Medecin mis a jour.' : 'Medecin cree.');

        $this->cancel();
    }

    public function render(): View
    {
        return view('livewire.admin.doctor-manager', [
            'doctors' => Doctor::with(['user', 'service'])
                ->get()
                ->sortBy(fn (Doctor $doctor) => $doctor->user->name)
                ->values(),
            'services' => Service::careServices()->orderBy('name')->get(),
        ]);
    }
}
