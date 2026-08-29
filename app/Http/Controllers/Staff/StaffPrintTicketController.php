<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\StaffType;
use App\Models\Visit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Ticket imprimable depuis une interface generique (capacite
 * `can_print_ticket`).
 *
 * Meme vue que l'accueil : il n'existe qu'un seul format de ticket dans
 * l'etablissement.
 */
class StaffPrintTicketController extends Controller
{
    public function __invoke(Request $request, string $slug, Visit $visit): View
    {
        $member = $request->user()->staffMember;

        abort_unless(
            $member?->staffType?->can(StaffType::CAP_PRINT_TICKET),
            403,
            'Cette action ne releve pas de votre fonction.',
        );

        // On n'imprime que les tickets de sa propre file.
        abort_unless((int) $visit->service_id === (int) $member->service_id, 403);

        $visit->load(['patient', 'service', 'pendingNextService']);

        return view('reception.print-ticket', [
            'kind' => 'patient',
            'hospitalName' => hospital_name(),
            'visit' => $visit,
            'visitor' => null,
        ]);
    }
}
