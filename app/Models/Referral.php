<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referral extends Model
{
    use HasFactory, RecordsActivity;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    /** Le prescripteur a pris connaissance du resultat et ferme la boucle. */
    public const STATUS_CLOSED = 'closed';

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'En attente',
        self::STATUS_DONE => 'Resultat recu',
        self::STATUS_CLOSED => 'Cloture',
    ];

    protected $fillable = [
        'patient_id',
        'visit_id',
        'from_service_id',
        'to_service_id',
        'from_doctor_id',
        'from_staff_member_id',
        'completed_by_doctor_id',
        'completed_by_staff_member_id',
        'instructions',
        'status',
        'result_text',
        'completed_at',
        'closed_by_doctor_id',
        'closed_by_staff_member_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function fromService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'from_service_id');
    }

    public function toService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'to_service_id');
    }

    public function fromDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'from_doctor_id');
    }

    public function completedByDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'completed_by_doctor_id');
    }

    public function closedByDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'closed_by_doctor_id');
    }

    public function fromStaffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'from_staff_member_id');
    }

    public function completedByStaffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'completed_by_staff_member_id');
    }

    public function closedByStaffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'closed_by_staff_member_id');
    }

    /** Le prescripteur, qu'il soit medecin ou personnel generique. */
    public function prescriberName(): ?string
    {
        return $this->fromDoctor?->name() ?? $this->fromStaffMember?->name();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public static function auditLabel(): string
    {
        return 'Renvoi';
    }
}
