<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Doctor extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['user_id', 'service_id', 'phone', 'signature_path', 'stamp_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function sentReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'from_doctor_id');
    }

    public function completedReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'completed_by_doctor_id');
    }

    public function name(): string
    {
        return $this->user?->name ?? '';
    }

    public static function auditLabel(): string
    {
        return 'Medecin';
    }

    /**
     * La signature prete a etre posee sur une ordonnance — nulle si le fichier
     * n'est pas la (v3.2.9, point 2).
     *
     * C'est ce controle d'existence qui tient la promesse « une ordonnance
     * s'imprime meme sans signature » : un chemin en base dont le fichier a
     * disparu ferait echouer la generation, et une ordonnance qu'on ne peut
     * plus imprimer est un probleme bien plus grave qu'une signature manquante.
     */
    public function signatureFile(): ?string
    {
        return self::fichierEncode($this->signature_path);
    }

    public function stampFile(): ?string
    {
        return self::fichierEncode($this->stamp_path);
    }

    /**
     * L'image encodee en source de donnees, ou null.
     *
     * Le disque `signatures` vit hors de `public/` — c'est voulu, une signature
     * de medecin ne s'attrape pas en devinant une URL. Elle n'a donc pas
     * d'adresse, et un chemin de fichier ne veut rien dire pour un navigateur :
     * la vue imprimable affichait une case vide la ou le PDF montrait le
     * cachet. Encodee dans la page, l'image arrive aux deux rendus sans ouvrir
     * la moindre route, et elle est la au moment ou l'on appuie sur Imprimer —
     * une image encore en cours de chargement ne part pas a l'imprimante.
     *
     * dompdf accepte cette forme comme il acceptait un chemin absolu.
     */
    public static function fichierEncode(?string $chemin): ?string
    {
        if (! $absolu = self::fichierExistant($chemin)) {
            return null;
        }

        $binaire = @file_get_contents($absolu);

        if ($binaire === false) {
            return null;
        }

        $type = Storage::disk('signatures')->mimeType($chemin) ?: 'image/png';

        return 'data:'.$type.';base64,'.base64_encode($binaire);
    }

    /** Le chemin absolu si, et seulement si, le fichier est reellement lisible. */
    public static function fichierExistant(?string $chemin): ?string
    {
        if (blank($chemin)) {
            return null;
        }

        $disque = Storage::disk('signatures');

        return $disque->exists($chemin) ? $disque->path($chemin) : null;
    }
}
