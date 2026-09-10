<?php

namespace App\Livewire\Shared;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Navigation verticale en onglets, partagee par les trois interfaces.
 *
 * Chaque interface fournit sa propre arborescence de sections ; le composant ne
 * connait rien du metier, il affiche la section active et une seule. Les
 * sections empilees sur une longue page laissent place a un panneau unique, ce
 * qui evite de faire defiler tout l'ecran pour atteindre la caisse ou le
 * planning.
 *
 * Chaque section designe une vue partielle qui contient ses composants
 * Livewire : leur contenu et leur comportement sont inchanges, seule leur
 * presentation bouge.
 *
 * Format attendu pour `sections` :
 *
 *     [
 *         ['key' => 'services', 'label' => 'Services', 'view' => 'sections.admin.services'],
 *         ['key' => 'personnel', 'label' => 'Personnel', 'children' => [
 *             ['key' => 'doctors', 'label' => 'Medecins', 'view' => '...'],
 *         ]],
 *     ]
 *
 * Une feuille peut porter une cle `context` : ses valeurs s'ajoutent au
 * contexte commun quand cette section est affichee.
 */
class VerticalTabNav extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $sections = [];

    public string $active = '';

    /**
     * Donnees transmises a la vue de la section active — par exemple le
     * service courant du medecin dans /service.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    /**
     * Groupes deplies au chargement. Un groupe contenant la section active
     * s'ouvre de lui-meme : on ne cache jamais a l'utilisateur ou il se trouve.
     *
     * C'est un etat de depart, pas l'etat courant : une fois la page affichee,
     * l'ouverture et la fermeture des groupes se font entierement dans le
     * navigateur. Deplier un menu n'a pas a passer par le serveur, et sur une
     * liaison lente le sous-menu apparaissait avec un temps de retard.
     *
     * @var array<int, string>
     */
    public array $expanded = [];

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $context
     */
    public function mount(array $sections, ?string $active = null, array $context = []): void
    {
        $this->sections = $sections;
        $this->context = $context;
        $this->active = $this->resolveActive($active);
        $this->expanded = $this->groupsContaining($this->active);
    }

    public function select(string $key): void
    {
        if (! $this->isSelectable($key)) {
            return;
        }

        $this->active = $key;

        // Le groupe de la section choisie reste ouvert.
        foreach ($this->groupsContaining($key) as $group) {
            if (! in_array($group, $this->expanded, true)) {
                $this->expanded[] = $group;
            }
        }
    }

    /**
     * Premiere section de l'espace : la destination du premier maillon du fil
     * d'Ariane, qui porte le nom de l'espace (« Accueil », « Service »,
     * « Caisse », « Poste »).
     */
    public function selectPremier(): void
    {
        if ($leaf = $this->firstLeaf($this->sections)) {
            $this->select($leaf['key']);
        }
    }

    /**
     * Premiere section d'une famille : la destination d'un maillon
     * intermediaire du fil d'Ariane, qui porte un intitule de famille
     * (« Etablissement », « Gestion », « Systeme »).
     *
     * Une famille n'est pas une page — c'est un intitule de reperage dans la
     * barre laterale. Cliquer dessus mene donc a sa premiere section, ce que
     * ferait la barre elle-meme. A defaut de famille, on accepte le libelle
     * d'une section : un fil peut nommer une section parente plutot qu'une
     * famille, et il vaut mieux y aller que ne rien faire.
     *
     * Un intitule qui ne correspond a rien ne fait rien, sans erreur : le fil
     * est ecrit a la main dans chaque vue de section, et une faute de frappe
     * ne doit pas casser la page.
     */
    public function selectFamille(string $famille): void
    {
        foreach ($this->sections as $section) {
            if (($section['famille'] ?? null) === $famille) {
                if ($leaf = $this->firstLeaf([$section])) {
                    $this->select($leaf['key']);
                }

                return;
            }
        }

        foreach ($this->sections as $section) {
            if (($section['label'] ?? null) === $famille) {
                if ($leaf = $this->firstLeaf([$section])) {
                    $this->select($leaf['key']);
                }

                return;
            }
        }
    }

    /**
     * La vue partielle de la section active, ou null si rien ne correspond.
     */
    public function activeView(): ?string
    {
        return $this->findLeaf($this->active)['view'] ?? null;
    }

    public function activeLabel(): string
    {
        return $this->findLeaf($this->active)['label'] ?? '';
    }

    /**
     * Donnees passees a la vue de la section active : le contexte commun a
     * l'interface, complete par celui que la section porte elle-meme.
     *
     * Une interface peut ainsi repeter la meme vue pour plusieurs entites —
     * une section par caisse, par exemple — sans avoir besoin d'une vue
     * partielle par entite.
     *
     * @return array<string, mixed>
     */
    public function activeContext(): array
    {
        return array_merge($this->context, $this->findLeaf($this->active)['context'] ?? []);
    }

    /**
     * Premiere section affichable de l'arborescence, utilisee par defaut.
     */
    private function resolveActive(?string $requested): string
    {
        if ($requested !== null && $this->isSelectable($requested)) {
            return $requested;
        }

        return $this->firstLeaf($this->sections)['key'] ?? '';
    }

    private function isSelectable(string $key): bool
    {
        return $this->findLeaf($key) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLeaf(string $key): ?array
    {
        foreach ($this->sections as $section) {
            foreach ($section['children'] ?? [$section] as $leaf) {
                if (($leaf['key'] ?? null) === $key) {
                    return $leaf;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, mixed>|null
     */
    private function firstLeaf(array $sections): ?array
    {
        foreach ($sections as $section) {
            if (empty($section['children'])) {
                return $section;
            }

            if ($leaf = $this->firstLeaf($section['children'])) {
                return $leaf;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function groupsContaining(string $key): array
    {
        $groups = [];

        foreach ($this->sections as $section) {
            foreach ($section['children'] ?? [] as $child) {
                if (($child['key'] ?? null) === $key) {
                    $groups[] = $section['key'];
                }
            }
        }

        return $groups;
    }

    public function render(): View
    {
        return view('livewire.shared.vertical-tab-nav');
    }
}
