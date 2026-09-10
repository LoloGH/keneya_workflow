<?php

namespace Tests\Feature;

use App\Actions\StoreSignatureImage;
use App\Livewire\Admin\HospitalSettings;
use App\Livewire\Shared\ProfileCard;
use App\Models\Doctor;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Visit;
use App\Support\Audit;
use App\Support\PrescriptionPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Signature, tampons et en-tete d'ordonnance (v3.2.9, point 2).
 *
 * Le fil conducteur : ces trois images ont une valeur legale, mais leur
 * absence ne doit jamais empecher d'imprimer une ordonnance.
 */
class PrescriptionSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake('signatures');
    }

    private function makePrescription(Doctor $doctor, Visit $visit): Prescription
    {
        return Prescription::create([
            'patient_id' => $visit->patient_id,
            'visit_id' => $visit->getKey(),
            'doctor_id' => $doctor->getKey(),
            'content' => 'Paracetamol 500 mg, 3 fois par jour, 5 jours.',
        ]);
    }

    // --------------------------------------------- L'absence n'empeche rien

    /**
     * La verification demandee : sans signature, sans tampon medecin et sans
     * tampon d'etablissement, l'ordonnance s'imprime normalement.
     */
    public function test_une_ordonnance_sans_aucune_image_s_imprime_normalement(): void
    {
        $service = Service::factory()->create();
        $medecin = $this->makeDoctor($service);
        $visit = $this->makeVisit($service);
        $ordonnance = $this->makePrescription($medecin, $visit);

        $donnees = PrescriptionPdfData::for($ordonnance->fresh(['patient', 'doctor.user', 'visit.service']));

        $this->assertNull($donnees['doctorSignature']);
        $this->assertNull($donnees['doctorStamp']);
        $this->assertNull($donnees['hospitalStamp']);

        $this->actingAs($medecin->user)
            ->get(route('service.prescription.pdf', $ordonnance))
            ->assertOk();
    }

    /**
     * Un chemin enregistre dont le fichier a disparu ne doit pas faire echouer
     * la generation : c'est le cas qui casserait dompdf sans ce garde-fou.
     */
    public function test_un_chemin_dont_le_fichier_a_disparu_laisse_l_espace_vide(): void
    {
        $service = Service::factory()->create();
        $medecin = $this->makeDoctor($service);
        $medecin->forceFill(['signature_path' => 'medecins/1/disparue.png'])->save();
        Setting::put(Setting::HOSPITAL_STAMP_PATH, 'etablissement/disparu.png');

        $visit = $this->makeVisit($service);
        $ordonnance = $this->makePrescription($medecin, $visit);

        $donnees = PrescriptionPdfData::for($ordonnance);

        $this->assertNull($donnees['doctorSignature']);
        $this->assertNull($donnees['hospitalStamp']);

        $this->actingAs($medecin->user)
            ->get(route('service.prescription.pdf', $ordonnance))
            ->assertOk();
    }

    // ----------------------------------------- Le poids des images deposees

    /**
     * Une image plus large que `COTE_MAX` est ramenee a cette taille.
     *
     * Ces trois images sont encodees dans la page imprimable : un cachet
     * depose en 2000 pixels de large y pesait plus d'un mega-octet a lui seul.
     * Les proportions sont conservees — un cachet rond ne doit pas devenir
     * ovale.
     */
    public function test_une_image_trop_large_est_ramenee_a_la_taille_utile(): void
    {
        $medecin = $this->makeDoctor(Service::factory()->create());

        app(StoreSignatureImage::class)->forDoctorStamp(
            UploadedFile::fake()->image('tampon.png', 2400, 1200),
            $medecin,
        );

        $chemin = $medecin->fresh()->stamp_path;
        $mesures = getimagesizefromstring(Storage::disk('signatures')->get($chemin));

        $this->assertSame(StoreSignatureImage::COTE_MAX, $mesures[0]);
        $this->assertSame(StoreSignatureImage::COTE_MAX / 2, $mesures[1]);
    }

    /**
     * Une image deja assez petite passe telle quelle : on ne la reencode pas,
     * et surtout on ne l'agrandit jamais — agrandir un scan ne lui ajoute
     * aucun detail, cela ne ferait que gonfler le fichier.
     */
    public function test_une_image_assez_petite_n_est_pas_touchee(): void
    {
        $medecin = $this->makeDoctor(Service::factory()->create());

        app(StoreSignatureImage::class)->forDoctorSignature(
            UploadedFile::fake()->image('signature.png', 400, 200),
            $medecin,
        );

        $chemin = $medecin->fresh()->signature_path;
        $mesures = getimagesizefromstring(Storage::disk('signatures')->get($chemin));

        $this->assertSame(400, $mesures[0]);
        $this->assertSame(200, $mesures[1]);
    }

    /** Le tampon de l'etablissement suit la meme regle. */
    public function test_le_tampon_de_l_etablissement_est_reduit_aussi(): void
    {
        app(StoreSignatureImage::class)->forHospitalStamp(
            UploadedFile::fake()->image('cachet.png', 3000, 3000),
        );

        $chemin = Setting::get(Setting::HOSPITAL_STAMP_PATH);
        $mesures = getimagesizefromstring(Storage::disk('signatures')->get($chemin));

        $this->assertSame(StoreSignatureImage::COTE_MAX, $mesures[0]);
        $this->assertSame(StoreSignatureImage::COTE_MAX, $mesures[1]);
    }

    // ------------------------------------- Les images arrivent sur le papier

    /**
     * Le defaut signale : la vue imprimable ne montrait ni le cachet de
     * l'etablissement ni la signature du medecin.
     *
     * Les donnees etaient pourtant bien passees au gabarit — c'est le gabarit
     * qui ne les affichait pas, et la forme qu'elles prenaient, un chemin de
     * fichier sur le disque du serveur, n'aurait de toute facon rien dit a un
     * navigateur. Ce test tient les deux bouts : les trois images sont dans la
     * page, et sous une forme qu'un navigateur sait afficher.
     */
    public function test_la_vue_imprimable_montre_le_cachet_et_la_signature(): void
    {
        $service = Service::factory()->create();
        $medecin = $this->makeDoctor($service);

        Storage::disk('signatures')->put('medecins/1/signature.png', 'PNG-signature');
        Storage::disk('signatures')->put('medecins/1/tampon.png', 'PNG-tampon-medecin');
        Storage::disk('signatures')->put('etablissement/cachet.png', 'PNG-cachet');

        $medecin->forceFill([
            'signature_path' => 'medecins/1/signature.png',
            'stamp_path' => 'medecins/1/tampon.png',
        ])->save();

        Setting::put(Setting::HOSPITAL_STAMP_PATH, 'etablissement/cachet.png');

        $ordonnance = $this->makePrescription($medecin, $this->makeVisit($service));

        $reponse = $this->actingAs($medecin->user)
            ->get(route('service.prescription.print', $ordonnance))
            ->assertOk();

        $html = $reponse->getContent();

        foreach ([
            'PNG-cachet' => "le cachet de l'etablissement",
            'PNG-signature' => 'la signature du medecin',
            'PNG-tampon-medecin' => 'le tampon du medecin',
        ] as $contenu => $quoi) {
            $this->assertStringContainsString(
                'data:image/png;base64,'.base64_encode($contenu),
                $html,
                $quoi." n'est pas sur la vue imprimable.",
            );
        }

        // Le cadre pointille ne sert qu'a signaler une image absente : il n'a
        // rien a faire la ou le cachet est bien present.
        $this->assertStringNotContainsString('<div class="cadre"></div>', $html);
    }

    /**
     * Le PDF et la vue imprimable recoivent exactement les memes images. C'est
     * la promesse de PrescriptionPdfData, et elle vaut aussi pour la forme :
     * une seule representation sert les deux rendus.
     */
    public function test_le_pdf_et_la_vue_imprimable_portent_les_memes_images(): void
    {
        $service = Service::factory()->create();
        $medecin = $this->makeDoctor($service);

        Storage::disk('signatures')->put('medecins/1/signature.png', 'PNG-signature');
        $medecin->forceFill(['signature_path' => 'medecins/1/signature.png'])->save();

        $ordonnance = $this->makePrescription($medecin, $this->makeVisit($service));
        $donnees = PrescriptionPdfData::for($ordonnance->fresh(['patient', 'doctor.user', 'visit.service']));

        $this->assertSame(
            'data:image/png;base64,'.base64_encode('PNG-signature'),
            $donnees['doctorSignature'],
        );

        $this->actingAs($medecin->user)
            ->get(route('service.prescription.pdf', $ordonnance))
            ->assertOk();
    }

    // --------------------------------------------- En-tete configurable

    public function test_l_en_tete_reprend_les_coordonnees_reglees_dans_l_administration(): void
    {
        Setting::put(Setting::HOSPITAL_ADDRESS, 'Quartier Legal Segou, Kayes');
        Setting::put(Setting::HOSPITAL_PHONE, '+223 21 52 00 00');
        Setting::put(Setting::HOSPITAL_EMAIL, 'contact@hfd.ml');

        $service = Service::factory()->create();
        $medecin = $this->makeDoctor($service);
        $ordonnance = $this->makePrescription($medecin, $this->makeVisit($service));

        $donnees = PrescriptionPdfData::for($ordonnance);

        $this->assertSame('Quartier Legal Segou, Kayes', $donnees['hospitalAddress']);
        $this->assertSame('+223 21 52 00 00', $donnees['hospitalPhone']);
        $this->assertSame('contact@hfd.ml', $donnees['hospitalEmail']);
    }

    public function test_l_administration_enregistre_les_coordonnees(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(HospitalSettings::class)
            ->set('hospitalName', 'Hopital Fousseyni Daou')
            ->set('hospitalAddress', 'Quartier Legal Segou, Kayes')
            ->set('hospitalPhone', '+223 21 52 00 00')
            ->set('hospitalEmail', 'contact@hfd.ml')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('contact@hfd.ml', Setting::get(Setting::HOSPITAL_EMAIL));
    }

    // --------------------------------------------- Depot et securite

    public function test_le_medecin_depose_sa_signature_et_son_tampon(): void
    {
        $service = Service::factory()->create();
        $medecin = $this->makeDoctor($service);

        Livewire::actingAs($medecin->user)
            ->test(ProfileCard::class)
            ->call('startSignatureChange')
            ->set('signatureFile', UploadedFile::fake()->image('signature.png'))
            ->set('stampFile', UploadedFile::fake()->image('tampon.png'))
            ->call('saveSignature')
            ->assertHasNoErrors();

        $medecin->refresh();

        $this->assertNotNull($medecin->signature_path);
        $this->assertNotNull($medecin->stamp_path);
        Storage::disk('signatures')->assertExists($medecin->signature_path);
    }

    /** Une image remplacee disparait du disque : une signature perimee reste utilisable. */
    public function test_l_image_remplacee_est_effacee_du_disque(): void
    {
        $medecin = $this->makeDoctor(Service::factory()->create());
        $action = app(StoreSignatureImage::class);

        $premier = $action->forDoctorSignature(UploadedFile::fake()->image('une.png'), $medecin);
        $action->forDoctorSignature(UploadedFile::fake()->image('deux.png'), $medecin->fresh());

        Storage::disk('signatures')->assertMissing($premier);
    }

    /** Un fichier qui n'est pas une image est refuse cote serveur, pas seulement au formulaire. */
    public function test_un_fichier_non_image_est_refuse_par_l_action(): void
    {
        $medecin = $this->makeDoctor(Service::factory()->create());

        $this->expectException(InvalidArgumentException::class);

        app(StoreSignatureImage::class)->forDoctorSignature(
            UploadedFile::fake()->create('ordonnance.pdf', 10, 'application/pdf'),
            $medecin,
        );
    }

    public function test_une_image_trop_lourde_est_refusee(): void
    {
        $medecin = $this->makeDoctor(Service::factory()->create());

        $this->expectException(InvalidArgumentException::class);

        app(StoreSignatureImage::class)->forDoctorSignature(
            UploadedFile::fake()->image('enorme.png')->size(3000),
            $medecin,
        );
    }

    /**
     * La verification demandee : tout changement sur l'une des trois images
     * apparait au journal d'audit.
     */
    public function test_chaque_changement_d_image_est_journalise(): void
    {
        $medecin = $this->makeDoctor(Service::factory()->create());
        $action = app(StoreSignatureImage::class);

        $this->actingAs($medecin->user);
        $action->forDoctorSignature(UploadedFile::fake()->image('signature.png'), $medecin);
        $action->forDoctorStamp(UploadedFile::fake()->image('tampon.png'), $medecin->fresh());

        $this->actingAs($this->makeAdmin());
        $action->forHospitalStamp(UploadedFile::fake()->image('cachet.png'));

        $traces = Activity::where('event', Audit::EVENT_SIGNATURE_CHANGED)->get();

        $this->assertCount(3, $traces);
        $this->assertTrue($traces->contains(fn ($t) => str_contains($t->description, "Tampon de l'etablissement")));
    }

    /** Le tampon institutionnel ne se regle que depuis /admin. */
    public function test_le_tampon_de_l_etablissement_se_depose_depuis_l_administration(): void
    {
        Livewire::actingAs($this->makeAdmin())
            ->test(HospitalSettings::class)
            ->set('stampFile', UploadedFile::fake()->image('cachet.png'))
            ->call('saveStamp')
            ->assertHasNoErrors();

        $chemin = Setting::get(Setting::HOSPITAL_STAMP_PATH);

        $this->assertNotNull($chemin);
        Storage::disk('signatures')->assertExists($chemin);
    }
}
