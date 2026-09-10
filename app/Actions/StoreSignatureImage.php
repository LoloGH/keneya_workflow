<?php

namespace App\Actions;

use App\Models\Doctor;
use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Depot d'une signature ou d'un tampon (v3.2.9, point 2).
 *
 * Trois images, un seul chemin de code : signature du medecin, tampon du
 * medecin, tampon de l'etablissement. Elles partagent les memes exigences —
 * image uniquement, taille bornee, et toute modification tracee.
 *
 * Les controles sont refaits ici, cote serveur, comme pour les pieces jointes :
 * un formulaire se contourne, pas une action. Et ces trois images-la portent
 * une valeur legale — apposer la signature d'un praticien sur une ordonnance
 * n'est pas un geste anodin.
 */
class StoreSignatureImage
{
    /** 2 Mo : largement de quoi scanner une signature, trop peu pour un abus. */
    public const TAILLE_MAX = 2 * 1024 * 1024;

    /**
     * Cote le plus long conserve, en pixels.
     *
     * Un cachet fait cinq centimetres de large sur une ordonnance. A 300
     * points par pouce — la finesse d'une imprimante de bureau —, cela fait
     * six cents pixels : 1200 laisse donc le double de ce que le papier peut
     * rendre, marge confortable pour un scan de travers qu'on recadre.
     *
     * Ce n'est pas une coquetterie d'octets. Ces images sont encodees dans la
     * page imprimable : un cachet depose en 1720 pixels de large pesait 1,3 Mo
     * a lui seul, et trois images de ce calibre faisaient une ordonnance de
     * quatre mega-octets a charger sur le Wi-Fi d'un hopital.
     */
    public const COTE_MAX = 1200;

    /**
     * Formats acceptes, verifies sur le type reel du fichier et non sur son
     * extension. Pas de SVG : il peut porter du script.
     *
     * @var array<int, string>
     */
    public const TYPES_ACCEPTES = ['image/png', 'image/jpeg', 'image/webp'];

    public function forDoctorSignature(UploadedFile $file, Doctor $doctor): string
    {
        return $this->poser(
            $file,
            $doctor,
            'signature_path',
            sprintf('Signature du medecin %s modifiee.', $doctor->name()),
        );
    }

    public function forDoctorStamp(UploadedFile $file, Doctor $doctor): string
    {
        return $this->poser(
            $file,
            $doctor,
            'stamp_path',
            sprintf('Tampon du medecin %s modifie.', $doctor->name()),
        );
    }

    /**
     * Le tampon de l'etablissement vit dans `settings`, pas sur une fiche : il
     * vaut pour tous les praticiens.
     */
    public function forHospitalStamp(UploadedFile $file): string
    {
        $this->assertAcceptable($file);

        $ancien = Setting::get(Setting::HOSPITAL_STAMP_PATH);
        $chemin = $this->ranger($file, 'etablissement');

        Setting::put(Setting::HOSPITAL_STAMP_PATH, $chemin);
        $this->oublier($ancien);

        Audit::log(Audit::EVENT_SIGNATURE_CHANGED, "Tampon de l'etablissement modifie.");

        return $chemin;
    }

    private function poser(UploadedFile $file, Doctor $doctor, string $colonne, string $trace): string
    {
        $this->assertAcceptable($file);

        $ancien = $doctor->{$colonne};
        $chemin = $this->ranger($file, 'medecins/'.$doctor->getKey());

        $doctor->forceFill([$colonne => $chemin])->save();
        $this->oublier($ancien);

        Audit::log(Audit::EVENT_SIGNATURE_CHANGED, $trace, $doctor);

        return $chemin;
    }

    private function ranger(UploadedFile $file, string $dossier): string
    {
        // Une reduction qui echoue rend null, et l'original part alors tel
        // quel : mieux vaut une image lourde qu'un depot refuse. C'est la meme
        // regle que partout ailleurs ici — l'absence d'une signature ne doit
        // jamais empecher d'imprimer une ordonnance.
        $binaire = $this->reduire($file) ?? @file_get_contents($file->getRealPath());

        if ($binaire === false || $binaire === null) {
            throw new InvalidArgumentException("L'image n'a pas pu etre lue.");
        }

        $chemin = $dossier.'/'.Str::random(40).'.'.($file->extension() ?: 'png');

        if (! Storage::disk('signatures')->put($chemin, $binaire)) {
            throw new InvalidArgumentException("L'image n'a pas pu etre enregistree.");
        }

        return $chemin;
    }

    /**
     * L'image ramenee a `COTE_MAX` sur son plus long cote, ou null s'il n'y a
     * rien a faire — image deja assez petite, format que GD ne sait pas lire,
     * extension absente.
     *
     * On ne grandit jamais une image : agrandir un scan ne lui ajoute aucun
     * detail, cela ne ferait que gonfler le fichier.
     *
     * La transparence est preservee pour PNG et WebP, ou elle sert : un cachet
     * detoure se pose sur le papier sans rectangle blanc autour. Un JPEG n'en
     * a pas et recoit un fond blanc — sans lui, GD rendrait le fond noir.
     */
    private function reduire(UploadedFile $file): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $donnees = @file_get_contents($file->getRealPath());

        if ($donnees === false) {
            return null;
        }

        $source = @imagecreatefromstring($donnees);

        if ($source === false) {
            return null;
        }

        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $cote = max($largeur, $hauteur);

        if ($cote <= self::COTE_MAX) {
            imagedestroy($source);

            return null;
        }

        $facteur = self::COTE_MAX / $cote;
        $nouvelleLargeur = max(1, (int) round($largeur * $facteur));
        $nouvelleHauteur = max(1, (int) round($hauteur * $facteur));

        $cible = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);
        $type = (string) $file->getMimeType();

        if ($type === 'image/jpeg') {
            imagefill($cible, 0, 0, imagecolorallocate($cible, 255, 255, 255));
        } else {
            imagealphablending($cible, false);
            imagesavealpha($cible, true);
            imagefill($cible, 0, 0, imagecolorallocatealpha($cible, 0, 0, 0, 127));
        }

        imagecopyresampled(
            $cible, $source,
            0, 0, 0, 0,
            $nouvelleLargeur, $nouvelleHauteur,
            $largeur, $hauteur,
        );

        ob_start();

        match ($type) {
            'image/jpeg' => imagejpeg($cible, null, 90),
            'image/webp' => imagewebp($cible, null, 90),
            default => imagepng($cible, null, 6),
        };

        $reduite = ob_get_clean();

        imagedestroy($source);
        imagedestroy($cible);

        return $reduite ?: null;
    }

    /**
     * L'image remplacee est effacee du disque : une signature perimee qui
     * traine reste une signature utilisable.
     */
    private function oublier(?string $chemin): void
    {
        if (filled($chemin)) {
            Storage::disk('signatures')->delete($chemin);
        }
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if ($file->getSize() > self::TAILLE_MAX) {
            throw new InvalidArgumentException('L\'image ne doit pas depasser 2 Mo.');
        }

        if (! in_array((string) $file->getMimeType(), self::TYPES_ACCEPTES, true)) {
            throw new InvalidArgumentException('Seules les images PNG, JPEG ou WebP sont acceptees.');
        }
    }
}
