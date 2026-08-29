<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloisonnement strict : un role, une seule interface.
 *
 * Si un utilisateur atteint une interface qui n'est pas la sienne (typiquement
 * en tapant l'URL a la main), il est renvoye vers la sienne avec un message
 * lisible plutot que sur une page d'erreur 403 : le personnel hospitalier ne
 * doit jamais rester bloque devant une erreur technique.
 *
 * Deux formes d'argument :
 *  - un des quatre roles fixes (`role.scope:doctor`) ;
 *  - `role.scope:staff` pour l'interface generique /staff/{slug} (v3.2.1,
 *    point 10), ou l'autorisation se joue sur le slug du type de personnel de
 *    l'utilisateur et non sur un role Spatie. La regle reste la meme : une
 *    seule interface par personne, et la meme redirection propre.
 */
class EnsureRoleScope
{
    /** Argument reserve a l'interface generique des types de personnel. */
    public const GENERIC_STAFF = 'staff';

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        $autorise = $role === self::GENERIC_STAFF
            ? $this->ownsStaffSlug($user, (string) $request->route('slug'))
            : $user->hasRole($role);

        if ($autorise) {
            return $next($request);
        }

        $home = $user->homeUrl();

        if (! $home) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with(
                'error',
                "Aucun role n'est associe a votre compte. Contactez l'administrateur."
            );
        }

        return redirect()->to($home)->with('error', $this->refusal($role, $request));
    }

    /**
     * L'utilisateur porte-t-il exactement ce type de personnel generique ?
     *
     * Un slug inconnu est traite comme un refus, jamais comme un 404 : on ne
     * laisse pas deviner quels types existent depuis une URL tapee au hasard.
     */
    private function ownsStaffSlug(mixed $user, string $slug): bool
    {
        $type = $user->staffType();

        return $type !== null
            && ! $type->usesFixedRole()
            && $slug !== ''
            && $type->slug === $slug;
    }

    private function refusal(string $role, Request $request): string
    {
        if ($role !== self::GENERIC_STAFF) {
            return sprintf(
                'Cette page est reservee au role « %s ». Vous avez ete redirige vers votre espace.',
                Roles::label($role),
            );
        }

        return 'Cette page est reservee a un autre type de personnel. Vous avez ete redirige vers votre espace.';
    }
}
