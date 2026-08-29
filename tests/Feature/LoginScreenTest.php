<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ecran de connexion : la refonte visuelle de la v3.2 ne doit rien changer au
 * contrat du formulaire ni aux protections qui l'entourent.
 */
class LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- Le contrat du formulaire

    public function test_le_formulaire_poste_vers_la_route_de_connexion_avec_un_jeton(): void
    {
        $response = $this->get('/connexion');

        $response->assertOk()
            ->assertSee('method="POST"', escape: false)
            ->assertSee('action="'.route('login.store').'"', escape: false)
            ->assertSee('name="_token"', escape: false);
    }

    public function test_les_trois_champs_attendus_par_le_serveur_sont_presents(): void
    {
        $response = $this->get('/connexion');

        $response->assertOk()
            ->assertSee('name="email"', escape: false)
            ->assertSee('name="password"', escape: false)
            ->assertSee('name="remember"', escape: false);
    }

    // ----------------------------------------------------- La scene et la carte

    public function test_la_scene_accompagne_la_carte_sans_masquer_le_formulaire(): void
    {
        $response = $this->get('/connexion');

        $response->assertOk()
            ->assertSee('login-scene', escape: false)
            ->assertSee('login-card', escape: false)
            ->assertSee('Bienvenue', escape: false)
            ->assertSee(hospital_name());

        // La scene est decorative : elle ne doit rien annoncer aux lecteurs
        // d'ecran, qui ne rencontrent que le logo et le formulaire.
        $this->assertStringContainsString(
            '<svg class="login-scene__art" viewBox="0 0 760 560" preserveAspectRatio="xMidYMax meet" aria-hidden="true">',
            $response->getContent(),
        );
    }

    public function test_la_mention_de_droits_pointe_vers_le_site_de_l_editeur(): void
    {
        $response = $this->get('/connexion');

        $response->assertOk()
            ->assertSee('Toutes les droits réservés.', escape: false)
            ->assertSee('href="https://sukaxess.com"', escape: false)
            ->assertSee('>AXESs</a>', escape: false);
    }

    // --------------------------------------------------------- Les protections

    public function test_le_message_du_serveur_est_repris_dans_la_carte(): void
    {
        $this->from('/connexion')
            ->post(route('login.store'), [
                'email' => 'inconnu@keneya.local',
                'password' => 'mauvais-mot-de-passe',
            ])
            ->assertRedirect('/connexion')
            ->assertSessionHasErrors('email');

        $this->followingRedirects()
            ->get('/connexion')
            ->assertSee('Ces identifiants ne correspondent a aucun compte.', escape: false);
    }

    public function test_la_limitation_des_tentatives_reste_active(): void
    {
        for ($essai = 0; $essai < 6; $essai++) {
            $this->post(route('login.store'), [
                'email' => 'admin@keneya.local',
                'password' => 'mauvais-mot-de-passe',
            ]);
        }

        $this->post(route('login.store'), [
            'email' => 'admin@keneya.local',
            'password' => 'mauvais-mot-de-passe',
        ])->assertSessionHasErrorsIn('default', ['email']);

        $this->assertStringContainsString(
            'Trop de tentatives de connexion.',
            (string) session('errors')->first('email'),
        );
    }

    public function test_un_compte_reel_se_connecte_et_repart_vers_son_interface(): void
    {
        $admin = $this->makeAdmin();
        $admin->update(['password' => 'mot-de-passe-de-test']);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'mot-de-passe-de-test',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($admin);
    }
}
