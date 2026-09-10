<?php

namespace App\Actions;

use App\Models\Cashier;
use App\Models\Doctor;
use App\Models\Receptionist;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Suppression d'un compte du personnel (medecin, receptionniste, caissier ou
 * personnel a interface dediee).
 *
 * Un rattachement qui a laisse une trace dans un dossier patient n'est **pas**
 * supprimable : les colonnes `*_doctor_id` et `*_staff_member_id` sont ce qui
 * dit qui a pose quel acte. Les effacer reviendrait a rendre anonymes des
 * consultations, des renvois et des ordonnances deja signes — exactement ce
 * que le journal du parcours est cense empecher.
 *
 * Ce qui reste supprimable, c'est donc l'erreur de saisie : un compte cree par
 * megarde, ou qui n'a jamais servi. Le refus dit toujours ce qui bloque.
 */
class DeleteStaffAccount
{
    /**
     * Traces qui interdisent la suppression, par type de rattachement.
     *
     * @var array<string, array<int, array{0: string, 1: string, 2: string}>> [table, colonne, libelle]
     */
    private const TRACES = [
        Doctor::class => [
            ['patient_history', 'doctor_id', 'entree(s) au dossier'],
            ['referrals', 'from_doctor_id', 'renvoi(s) envoye(s)'],
            ['referrals', 'completed_by_doctor_id', 'resultat(s) de renvoi'],
            ['referrals', 'closed_by_doctor_id', 'renvoi(s) cloture(s)'],
            ['prescriptions', 'doctor_id', 'ordonnance(s)'],
            ['appointments', 'doctor_id', 'rendez-vous'],
            ['hospitalizations', 'admitted_by_doctor_id', 'hospitalisation(s)'],
            ['care_tasks', 'prescribed_by_doctor_id', 'soin(s) prescrit(s)'],
        ],
        StaffMember::class => [
            ['patient_history', 'staff_member_id', 'entree(s) au dossier'],
            ['referrals', 'from_staff_member_id', 'renvoi(s) envoye(s)'],
            ['referrals', 'completed_by_staff_member_id', 'resultat(s) de renvoi'],
            ['referrals', 'closed_by_staff_member_id', 'renvoi(s) cloture(s)'],
        ],
    ];

    /**
     * Traces portees par le compte lui-meme, quel que soit son rattachement.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const USER_TRACES = [
        ['payments', 'recorded_by_user_id', 'encaissement(s)'],
        ['attachments', 'uploaded_by_user_id', 'piece(s) jointe(s)'],
        ['care_tasks', 'completed_by_user_id', 'soin(s) realise(s)'],
        ['care_tasks', 'assigned_to_user_id', 'soin(s) assigne(s)'],
        ['care_tasks', 'cancelled_by_user_id', 'soin(s) annule(s)'],
        ['handoff_notes', 'written_by_user_id', 'note(s) de releve'],
        ['visitors', 'registered_by_user_id', 'visiteur(s) enregistre(s)'],
        ['broadcast_messages', 'sent_by_user_id', 'diffusion(s) de SMS'],
        ['feedback_entries', 'submitted_by_user_id', 'retour(s) depose(s)'],
        ['feedback_entries', 'handled_by_user_id', 'retour(s) pris en charge'],
        ['feedback_entries', 'resolved_by_user_id', 'retour(s) traite(s)'],
        ['feedback_survey_ratings', 'user_id', 'note(s) de satisfaction recue(s)'],

        // Module Dossier Medical Electronique. C'est la seule table du module
        // qui retienne un compte : partout ailleurs, une suppression laisse
        // simplement la colonne a nul. Un soin programme, lui, doit garder son
        // prescripteur. La table n'existe que si le module est monte, d'ou le
        // garde-fou de blockers() — cette liste vaut dans les deux cas.
        ['dme_care_orders', 'prescriber_id', 'soin(s) programme(s) au dossier medical'],
    ];

    public function execute(Doctor|Receptionist|Cashier|StaffMember $membership, User $admin): void
    {
        $user = $membership->user()->firstOrFail();

        if ($blocages = $this->blockers($membership, $user)) {
            throw new InvalidArgumentException(sprintf(
                '%s ne peut pas etre supprime : %s. Ces traces documentent le parcours de patients.',
                $user->name,
                implode(', ', $blocages),
            ));
        }

        // Ce rattachement est-il le dernier du compte ? Si oui, le compte part
        // avec — sinon il resterait sans role, incapable d'atteindre la moindre
        // interface.
        $dernier = $this->remainingMemberships($user, $membership) === 0;

        // Journalise AVANT : apres, il ne resterait plus rien a designer.
        Audit::log(
            Audit::EVENT_STAFF_DELETED,
            sprintf(
                '%s (%s) supprime par %s.%s',
                $user->name,
                $user->email,
                $admin->name,
                $dernier ? ' Compte supprime.' : ' Rattachement retire, le compte conserve ses autres services.',
            ),
            null,
            [
                'nom' => $user->name,
                'email' => $user->email,
                'rattachement' => class_basename($membership),
                'compte_supprime' => $dernier,
                'supprime_par' => $admin->name,
            ],
        );

        DB::transaction(function () use ($membership, $user, $dernier): void {
            $membership->delete();

            if ($dernier) {
                // Le planning et la cloche sont la propriete du compte, pas
                // du dossier patient : ils partent avec lui. Les notifications
                // manquaient, et `staff_notifications.user_id` est une cle
                // etrangere en RESTRICT — tout compte ayant recu ne serait-ce
                // qu'une notification refusait donc d'etre supprime, avec un
                // 500 pour toute explication.
                $user->schedules()->delete();
                $user->staffNotifications()->delete();
                $user->syncRoles([]);
                $user->delete();
            }
        });
    }

    /**
     * @return array<int, string> ce qui bloque, en clair
     */
    private function blockers(Doctor|Receptionist|Cashier|StaffMember $membership, User $user): array
    {
        $blocages = [];

        foreach (self::TRACES[$membership::class] ?? [] as [$table, $colonne, $libelle]) {
            if ($nombre = DB::table($table)->where($colonne, $membership->getKey())->count()) {
                $blocages[] = $nombre.' '.$libelle;
            }
        }

        foreach (self::USER_TRACES as [$table, $colonne, $libelle]) {
            // Une table absente n'est pas une trace : le module DME n'est pas
            // toujours monte, et l'interroger alors ferait echouer la
            // suppression au lieu de l'autoriser.
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($nombre = DB::table($table)->where($colonne, $user->getKey())->count()) {
                $blocages[] = $nombre.' '.$libelle;
            }
        }

        return $blocages;
    }

    /** Les autres rattachements du compte, toutes tables confondues. */
    private function remainingMemberships(User $user, Doctor|Receptionist|Cashier|StaffMember $exclu): int
    {
        $total = $user->doctors()->count()
            + Receptionist::where('user_id', $user->getKey())->count()
            + Cashier::where('user_id', $user->getKey())->count()
            + StaffMember::where('user_id', $user->getKey())->count();

        return max(0, $total - 1);
    }
}
