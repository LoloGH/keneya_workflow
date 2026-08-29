<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un utilisateur deja connecte qui revient sur l'ecran de connexion repart
 * directement vers son interface.
 */
class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ($home = Auth::user()->homeUrl())) {
            return redirect()->to($home);
        }

        return $next($request);
    }
}
