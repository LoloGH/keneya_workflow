<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\StaffMember;
use App\Models\User;

/**
 * L'agent qui pose un acte de soin, medecin ou membre du personnel generique.
 *
 * Les colonnes `*_doctor_id` restent reservees aux vrais medecins : un
 * infirmier cree par l'admin ne doit pas apparaitre comme praticien dans un
 * dossier. Ce petit objet evite pour autant de dupliquer chaque Action en deux
 * versions — il traduit une fois pour toutes « qui agit » vers le bon couple de
 * colonnes.
 */
final class Caregiver
{
    private function __construct(
        public readonly ?Doctor $doctor,
        public readonly ?StaffMember $staffMember,
    ) {}

    public static function of(Doctor|StaffMember $agent): self
    {
        return $agent instanceof Doctor
            ? new self($agent, null)
            : new self(null, $agent);
    }

    public function doctorId(): ?int
    {
        return $this->doctor?->getKey();
    }

    public function staffMemberId(): ?int
    {
        return $this->staffMember?->getKey();
    }

    /** Le service dans lequel cet agent exerce. */
    public function serviceId(): ?int
    {
        return $this->doctor?->service_id ?? $this->staffMember?->service_id;
    }

    public function user(): ?User
    {
        return $this->doctor?->user ?? $this->staffMember?->user;
    }

    public function name(): string
    {
        return (string) ($this->doctor?->name() ?? $this->staffMember?->name());
    }

    /**
     * Le couple de colonnes a ecrire, pour un prefixe donne
     * (`from`, `completed_by`, `closed_by`, ou '' pour patient_history).
     *
     * @return array<string, int|null>
     */
    public function columns(string $prefix = ''): array
    {
        $doctor = $prefix === '' ? 'doctor_id' : $prefix.'_doctor_id';
        $staff = $prefix === '' ? 'staff_member_id' : $prefix.'_staff_member_id';

        return [$doctor => $this->doctorId(), $staff => $this->staffMemberId()];
    }
}
