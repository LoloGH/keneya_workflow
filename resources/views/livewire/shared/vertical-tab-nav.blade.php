{{-- Navigation verticale + panneau actif.
     Le tiroir mobile est pilote par Alpine : sur tablette la barre laterale ne
     doit pas manger une portion fixe de l'ecran. --}}
{{-- `replie` est memorise dans le navigateur : une barre qu'on replie a chaque
     changement de section ne rend pas le service qu'on lui demande. Le
     stockage peut echouer (navigation privee, site data bloque) : on retombe
     alors simplement sur « depliee », sans rien casser.

     Le glissement horizontal ouvre et ferme : vers la droite on deplie, vers la
     gauche on replie. Le bouton fait la meme chose pour qui prefere cliquer, et
     reste le seul chemin accessible au clavier. --}}
<div class="workspace" :class="replie && 'workspace--replie'"
     x-data="{
        drawer: false,
        replie: false,
        depart: null,
        init() {
            try { this.replie = localStorage.getItem('keneya.nav.replie') === '1'; } catch (e) {}
        },
        basculer() {
            this.replie = ! this.replie;
            try { localStorage.setItem('keneya.nav.replie', this.replie ? '1' : '0'); } catch (e) {}
        },
        // Le relachement est ecoute sur la fenetre et non sur la barre : une
        // barre repliee ne fait que 66 px de large, et un geste vers la droite
        // se termine donc hors d'elle. Ecoute sur la barre seule, l'evenement
        // n'arrivait jamais et le glissement ne faisait rien.
        large() { return window.matchMedia('(min-width: 900px)').matches; },

        prise(e) {
            if (this.large()) {
                // Sur grand ecran, seul un geste NE la barre elle-meme la
                // replie : ailleurs dans la page, un glissement horizontal
                // appartient au contenu — a un tableau large, par exemple.
                if (! e.target.closest?.('.tabnav')) return;
            } else if (! this.drawer && e.clientX >= 40) {
                // Tiroir ferme : le geste qui l'ouvre part du bord gauche de
                // l'ecran, comme partout ailleurs. L'ecoute est posee sur la
                // fenetre et non sur l'espace de travail : les premiers pixels
                // de l'ecran sont la marge de la page, hors de cet element, et
                // un geste depuis le bord ne l'atteignait donc jamais.
                return;
            }

            this.depart = { x: e.clientX, y: e.clientY };
        },

        relache(e) {
            if (! this.depart) return;

            const dx = e.clientX - this.depart.x;
            const dy = e.clientY - this.depart.y;
            this.depart = null;

            // 40 px : au-dela d'un tremblement de main, en deca d'un geste
            // ample. Et le mouvement doit etre franchement horizontal, sinon
            // un defilement un peu oblique replierait la barre sans qu'on l'ait
            // demande.
            if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy) * 1.5) return;

            const versLaDroite = dx > 0;

            if (this.large()) {
                if (versLaDroite === this.replie) this.basculer();

                return;
            }

            // Petit ecran : le meme geste ouvre et ferme le tiroir.
            this.drawer = versLaDroite;
        },
     }"
     @keydown.escape.window="drawer = false"
     @pointerdown.window="prise($event)" @pointerup.window="relache($event)">

    <button type="button" class="workspace__toggle" @click="drawer = !drawer"
            :aria-expanded="drawer ? 'true' : 'false'" aria-controls="nav-sections">
        <x-icon name="file" size="22" />
        <span x-text="drawer ? 'Masquer les sections' : 'Sections'">Sections</span>
        <span class="workspace__toggle-current">{{ $this->activeLabel() }}</span>
    </button>

    <nav id="nav-sections" class="tabnav" :class="drawer && 'tabnav--open'"
         aria-label="Sections de cet espace">

        {{-- Le bouton de repli n'apparait qu'a partir de la tablette en
             paysage : sur petit ecran la barre est deja un tiroir, la replier
             n'aurait aucun sens. --}}
        <button type="button" class="tabnav__replier" @click="basculer()"
                :aria-expanded="replie ? 'false' : 'true'" aria-controls="nav-sections"
                :aria-label="replie ? 'Deplier la navigation' : 'Replier la navigation'"
                :title="replie ? 'Deplier la navigation' : 'Replier la navigation'">
            <x-icon name="chevron" size="16" class="tabnav__replier-icone" />
        </button>

        <ul class="tabnav__list">
            @php $familleRendue = null; @endphp

            @foreach ($sections as $section)
                @php $isGroup = ! empty($section['children']); @endphp

                {{-- Une entree peut annoncer la famille qui commence avec elle
                     (« ETABLISSEMENT », « GESTION », « SYSTEME »). C'est une
                     simple cle facultative : l'arbre de sections garde
                     exactement la meme forme, et une interface qui n'en pose
                     aucune — /caisse et ses trois sections — n'affiche aucun
                     intitule plutot qu'un decoupage qui n'apprendrait rien. --}}
                @if (($section['famille'] ?? null) && $section['famille'] !== $familleRendue)
                    @php $familleRendue = $section['famille']; @endphp
                    <li class="tabnav__famille" aria-hidden="true">{{ $section['famille'] }}</li>
                @endif

                @php $open = $isGroup && in_array($section['key'], $expanded, true); @endphp

                {{-- Un groupe porte son propre etat d'ouverture cote navigateur.
                     Avant, deplier « Personnel » etait un aller-retour serveur :
                     on cliquait, et le sous-menu apparaissait un instant plus
                     tard. Alpine le fait maintenant sans reseau, et le serveur
                     ne donne plus que l'etat de depart — celui qui garantit que
                     le groupe de la section courante s'ouvre au chargement. --}}
                <li class="tabnav__item" wire:key="sec-{{ $section['key'] }}"
                    @if ($isGroup) x-data="{ ouvert: {{ $open ? 'true' : 'false' }} }" @endif>
                    @if ($isGroup)
                        <button type="button" class="tabnav__group"
                                @click="ouvert = ! ouvert"
                                :aria-expanded="ouvert ? 'true' : 'false'"
                                aria-controls="grp-{{ $section['key'] }}">
                            <x-icon name="{{ $section['icon'] ?? 'chevron' }}" size="18" class="tabnav__icone" />
                            <span>{{ $section['label'] }}</span>
                            {{-- `::class` et non `:class` : sur un composant Blade,
                                 le simple deux-points est une valeur PHP. Le double
                                 laisse passer la liaison Alpine telle quelle. --}}
                            <x-icon name="chevron" size="16" class="tabnav__chevron"
                                    ::class="ouvert && 'tabnav__chevron--open'" />
                        </button>

                        {{-- Les sous-sections sont toujours rendues : c'est ce qui
                             permet a Alpine de les montrer sans rien demander au
                             serveur. Le `style` initial evite qu'un groupe ferme
                             clignote le temps qu'Alpine demarre. --}}
                        <ul class="tabnav__list tabnav__list--nested" id="grp-{{ $section['key'] }}"
                            x-show="ouvert" @if (! $open) style="display: none;" @endif>
                            @foreach ($section['children'] as $child)
                                <li wire:key="sec-{{ $child['key'] }}">
                                    <button type="button"
                                            class="tabnav__link @if ($active === $child['key']) tabnav__link--active @endif"
                                            wire:click="select('{{ $child['key'] }}')"
                                            wire:loading.class="tabnav__link--attente"
                                            wire:target="select('{{ $child['key'] }}')"
                                            @click="drawer = false"
                                            @if ($active === $child['key']) aria-current="page" @endif>
                                        {{ $child['label'] }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <button type="button"
                                class="tabnav__link @if ($active === $section['key']) tabnav__link--active @endif"
                                wire:click="select('{{ $section['key'] }}')"
                                wire:loading.class="tabnav__link--attente"
                                wire:target="select('{{ $section['key'] }}')"
                                @click="drawer = false"
                                @if ($active === $section['key']) aria-current="page" @endif>
                            <x-icon name="{{ $section['icon'] ?? 'chevron' }}" size="18" class="tabnav__icone" />
                            <span>{{ $section['label'] }}</span>
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Un seul panneau rendu a la fois : les sections inactives ne sont pas
         seulement masquees, elles ne sont pas montees — pas de wire:poll qui
         continuerait a tourner dans le vide. --}}
    <section class="workspace__panel" aria-live="polite"
             wire:loading.attr="aria-busy" wire:target="select">

        {{-- Un filet de progression pendant le changement de section. Purement
             decoratif : l'etat reel est porte par `aria-busy` ci-dessus, que
             les lecteurs d'ecran annoncent, et par le repere qui s'est deja
             deplace dans la barre. --}}
        <div class="workspace__progress" aria-hidden="true"
             wire:loading.delay.shortest wire:target="select"></div>
        {{-- Chaque section porte deja son titre de carte : ce titre-ci sert la
             structure du document et les lecteurs d'ecran, sans doubler le
             libelle a l'ecran. L'onglet actif indique visuellement ou l'on est. --}}
        <h1 class="page-title sr-only">{{ $this->activeLabel() }}</h1>

        @if ($view = $this->activeView())
            @include($view, $this->activeContext())
        @else
            <p class="empty">Aucune section a afficher.</p>
        @endif
    </section>
</div>
