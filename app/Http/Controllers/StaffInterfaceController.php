<?php

namespace App\Http\Controllers;

use App\Models\StaffType;
use Illuminate\Contracts\View\View;

/**
 * Interface generique d'un type de personnel (v3.2.1, point 10).
 *
 * **Une seule route pour tous ces types**, jamais une route generee a la volee
 * par type : le cache de routes de production ne saurait pas les voir, et
 * declarer des routes depuis la base a chaud est fragile. Le `slug` n'est
 * qu'une valeur lue en base par une route qui existe deja dans le code.
 *
 * Le cloisonnement est assure en amont par EnsureRoleScope, qui compare le type
 * du compte connecte au slug demande.
 */
class StaffInterfaceController extends Controller
{
    public function __invoke(string $slug): View
    {
        $type = StaffType::where('slug', $slug)->whereNull('matched_role')->firstOrFail();

        return view('pages.staff', ['type' => $type]);
    }
}
