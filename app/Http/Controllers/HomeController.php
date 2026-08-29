<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Aiguillage : renvoie chaque utilisateur vers l'unique interface de son role.
 * Il n'y a jamais de tableau de bord commun affiche ici.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $home = $request->user()->homeUrl();

        if (! $home) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with(
                'error',
                "Aucun role n'est associe a votre compte. Contactez l'administrateur."
            );
        }

        return redirect()->to($home);
    }
}
