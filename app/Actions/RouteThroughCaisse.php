<?php

namespace App\Actions;

use App\Models\Service;

/**
 * Routage sous condition de paiement (v3.2, point 6 ; revu en v3.2.1).
 *
 * Dans cet hopital, on regle avant d'etre pris en charge. Une visite qui vise un
 * service payant passe donc d'abord par une caisse : la destination reelle est
 * mise de cote dans `pending_next_service_id`, et le service courant devient la
 * caisse.
 *
 * Ce n'est qu'une etape de routage : la ligne `referrals` garde toujours la
 * destination metier reelle (Echographie, Laboratoire...), jamais la caisse.
 *
 * Deux chemins **distincts**, et non un seul parametre :
 *  - l'enregistrement a l'accueil passe toujours par la Caisse Ticket, c'est le
 *    ticket de consultation ;
 *  - un renvoi ne passe par la Caisse Services que si le type du service
 *    destinataire porte `requires_payment_gate` — un indicateur regle par
 *    l'admin (v3.2.1, point 10), plus une comparaison codee en dur sur
 *    « plateau technique ».
 *
 * Le **retour** d'un renvoi (`CompleteReferral`) n'appelle volontairement
 * aucune methode d'ici : on ne fait pas payer un patient pour revenir voir le
 * medecin qui l'a envoye.
 */
class RouteThroughCaisse
{
    /**
     * Enregistrement a l'accueil : le ticket de consultation se regle d'abord.
     *
     * @return array{0: Service, 1: Service|null} (file d'attente, destination mise en attente)
     */
    public function forRegistration(Service $destination): array
    {
        if ($destination->isCaisse()) {
            return [$destination, null];
        }

        return $this->gate(Service::caisseTicket(), $destination);
    }

    /**
     * Renvoi vers un autre service : peage seulement si le type le demande.
     *
     * @return array{0: Service, 1: Service|null}
     */
    public function forReferral(Service $destination): array
    {
        if ($destination->isCaisse() || ! $destination->requiresPaymentGate()) {
            return [$destination, null];
        }

        return $this->gate(Service::caisseServices(), $destination);
    }

    /**
     * Si la caisse attendue n'existe pas — deploiement qui n'en utilise pas —
     * la destination reste directe plutot que de bloquer le patient.
     *
     * @return array{0: Service, 1: Service|null}
     */
    private function gate(?Service $caisse, Service $destination): array
    {
        return $caisse ? [$caisse, $destination] : [$destination, null];
    }
}
