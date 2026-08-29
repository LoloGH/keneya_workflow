<?php

namespace App\Livewire\Admin;

use App\Models\Service;
use App\Models\StaffMember;
use App\Models\StaffType;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Personnel portant un type generique (v3.2.1, point 10).
 *
 * Les types adosses a un role continuent d'etre geres par leurs sections
 * existantes — « Medecins », « Receptionnistes » — qui utilisent les tables
 * `doctors` / `receptionists` / `cashiers` exactement comme avant. Rien n'est
 * deplace : cette section ne couvre que les types qui n'ont pas de role.
 */
class StaffMemberManager extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public ?int $staff_type_id = null;

    public ?int $service_id = null;

    #[On('types-de-personnel-mis-a-jour')]
    public function refreshTypes(): void
    {
        // Un nouveau rendu suffit.
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->editingUserId()),
            ],
            // Le mot de passe n'est exige qu'a la creation : le laisser vide en
            // modification veut dire « ne pas y toucher ».
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'staff_type_id' => [
                'required', 'integer',
                Rule::exists('staff_types', 'id')->whereNull('matched_role'),
            ],
            'service_id' => ['required', 'integer', 'exists:services,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom', 'email' => 'adresse e-mail', 'password' => 'mot de passe',
            'staff_type_id' => 'type de personnel', 'service_id' => 'service',
        ];
    }

    private function editingUserId(): ?int
    {
        return $this->editingId ? StaffMember::whereKey($this->editingId)->value('user_id') : null;
    }

    public function edit(int $memberId): void
    {
        $member = StaffMember::with('user')->findOrFail($memberId);

        $this->editingId = $member->getKey();
        $this->name = $member->user->name;
        $this->email = $member->user->email;
        $this->password = '';
        $this->staff_type_id = $member->staff_type_id;
        $this->service_id = $member->service_id;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password', 'staff_type_id', 'service_id']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data): void {
            if ($this->editingId) {
                $member = StaffMember::with('user')->findOrFail($this->editingId);

                $member->user->update(array_filter([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'] ?: null,
                ], fn ($value) => $value !== null));

                $member->update([
                    'staff_type_id' => $data['staff_type_id'],
                    'service_id' => $data['service_id'],
                ]);

                return;
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            // Aucun role Spatie : le cloisonnement d'un type generique se joue
            // sur son slug, pas sur un role.
            $member = StaffMember::create([
                'user_id' => $user->getKey(),
                'staff_type_id' => $data['staff_type_id'],
                'service_id' => $data['service_id'],
            ]);

            Audit::log(
                Audit::EVENT_STAFF_MEMBER_CREATED,
                sprintf(
                    '%s cree comme %s au service %s.',
                    $user->name,
                    $member->staffType->name,
                    $member->service?->name ?? '—',
                ),
                $member,
            );
        });

        session()->flash('admin.status', $this->editingId ? 'Membre du personnel mis a jour.' : 'Membre du personnel cree.');

        $this->cancel();
    }

    public function render(): View
    {
        return view('livewire.admin.staff-member-manager', [
            'members' => StaffMember::with(['user', 'staffType', 'service'])
                ->join('users', 'users.id', '=', 'staff_members.user_id')
                ->orderBy('users.name')
                ->select('staff_members.*')
                ->get(),
            // Seuls les types sans role : les autres ont leurs propres sections.
            'types' => StaffType::whereNull('matched_role')->orderBy('name')->get(),
            'services' => Service::careServices()->orderBy('name')->get(),
        ]);
    }
}
