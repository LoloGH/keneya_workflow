{{--
    La scene de l'ecran de connexion : le poste de travail du soignant.

    Tout est dessine en SVG inline et anime en CSS — aucune image lourde, aucune
    bibliotheque d'animation. Le serveur peut n'avoir aucune connectivite, et la
    scene reste nette sur un videoprojecteur comme sur une tablette.

    Le decoupage suit le recit : le medecin entre par la gauche avec sa mallette,
    la pose sur le bureau, l'ouvre, et les fonctions de KƐnƐya WorkFlow s'en
    echappent vers la carte de connexion.

    La scene est purement decorative : elle est masquee aux lecteurs d'ecran,
    qui ne rencontrent que le logo et le formulaire.
--}}
<div class="login-scene">
    {{-- Filet d'ECG sur le mur du fond. --}}
    <svg class="login-scene__ecg" viewBox="0 0 700 90" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 45 H120 l14 -30 l16 58 l14 -28 H300 l12 -22 l14 44 l12 -22 H520 l14 -34 l16 62 l12 -28 H700" />
    </svg>

    {{-- Poussiere lumineuse : quelques points, tres en retrait. --}}
    <div class="login-scene__dust" aria-hidden="true">
        <i style="left:12%;top:62%;animation-delay:0s"></i>
        <i style="left:26%;top:38%;animation-delay:1.6s"></i>
        <i style="left:44%;top:72%;animation-delay:3.1s"></i>
        <i style="left:62%;top:30%;animation-delay:4.4s"></i>
        <i style="left:78%;top:58%;animation-delay:2.2s"></i>
        <i style="left:88%;top:24%;animation-delay:5.8s"></i>
        <i style="left:34%;top:18%;animation-delay:6.9s"></i>
    </div>

    <div class="login-scene__vignette" aria-hidden="true"></div>

    <svg class="login-scene__art" viewBox="0 0 760 560" preserveAspectRatio="xMidYMax meet" aria-hidden="true">
        <defs>
            <linearGradient id="kw-desk" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#1c5c82" /><stop offset="1" stop-color="#12435f" />
            </linearGradient>
            <linearGradient id="kw-coat" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#ffffff" /><stop offset="1" stop-color="#dce9f1" />
            </linearGradient>
            <linearGradient id="kw-monitor" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#0d4a6e" /><stop offset="1" stop-color="#0a3350" />
            </linearGradient>
            <radialGradient id="kw-lamp" cx="0.5" cy="0" r="1">
                <stop offset="0" stop-color="#ffe6b0" stop-opacity=".30" />
                <stop offset="1" stop-color="#ffe6b0" stop-opacity="0" />
            </radialGradient>
            <radialGradient id="kw-halo" cx="0.5" cy="0.5" r="0.5">
                <stop offset="0" stop-color="#9ff0d3" stop-opacity=".75" />
                <stop offset="1" stop-color="#9ff0d3" stop-opacity="0" />
            </radialGradient>
        </defs>

        {{-- Le decor : il est en place avant tout le reste.

             L'ordre de trace porte la profondeur : le pied de la lampe passe
             derriere l'ecran, le cone de lumiere se pose au-dessus de tout. La
             moitie gauche du plateau reste libre — c'est la course du
             couvercle quand la mallette s'ouvre. --}}
        <g class="kw-set">
            <ellipse cx="470" cy="432" rx="300" ry="16" fill="#062535" opacity=".55" />
            <path d="M0 430 H760" stroke="#1a5578" stroke-width="1.5" opacity=".5" />

            {{-- Le bureau. --}}
            <rect x="296" y="330" width="450" height="17" rx="6" fill="url(#kw-desk)" />
            <rect x="296" y="330" width="450" height="4" rx="2" fill="#3d82ab" opacity=".7" />
            <rect x="322" y="347" width="12" height="84" rx="5" fill="#12435f" />
            <rect x="712" y="347" width="12" height="84" rx="5" fill="#12435f" />
            <rect x="540" y="347" width="180" height="84" rx="6" fill="#0f3b56" />
            <path d="M552 372 H708" stroke="#1d5a7d" stroke-width="3" stroke-linecap="round" />
            <path d="M552 404 H708" stroke="#1d5a7d" stroke-width="3" stroke-linecap="round" />

            {{-- Lampe en potence : le pied est plante au bord gauche du plateau,
                 le bras enjambe la course du couvercle bien au-dessus, et
                 l'abat-jour surplombe le milieu du bureau. --}}
            <ellipse cx="306" cy="331" rx="20" ry="6" fill="#5b8fae" />
            <path d="M306 330 V146" stroke="#5b8fae" stroke-width="7" stroke-linecap="round" />
            <path d="M306 146 Q 306 128 330 128 H 462 Q 478 128 478 148"
                  stroke="#5b8fae" stroke-width="7" fill="none" stroke-linecap="round" />
            <path d="M458 150 H498 L508 182 H448 Z" fill="#89b6ce" />
            <ellipse cx="478" cy="182" rx="17" ry="5" fill="#ffe6b0" opacity=".55" />

            {{-- L'ecran du poste affiche le monogramme, variante claire. --}}
            <rect x="596" y="196" width="140" height="92" rx="8" fill="url(#kw-monitor)" stroke="#2f7fa8" stroke-width="2" />
            <rect x="659" y="288" width="14" height="34" fill="#2a6d92" />
            <rect x="628" y="322" width="76" height="9" rx="4" fill="#2a6d92" />
            <x-brand-logo variant="light" x="614" y="216" width="104" height="52" />

            {{-- Dossiers medicaux. --}}
            <rect x="492" y="316" width="76" height="8" rx="3" fill="#e7eff4" />
            <rect x="488" y="308" width="76" height="8" rx="3" fill="#c9dde8" transform="rotate(-2 526 312)" />
            <rect x="494" y="300" width="76" height="8" rx="3" fill="#9fd6bf" transform="rotate(1.5 532 304)" />

            {{-- Stethoscope pose, qui retombe du plateau. --}}
            <path d="M470 330 C 462 366, 486 372, 480 398" stroke="#8fd8bd" stroke-width="4.5" fill="none" stroke-linecap="round" />
            <path d="M470 330 C 452 358, 430 360, 424 386" stroke="#8fd8bd" stroke-width="4.5" fill="none" stroke-linecap="round" />
            <circle cx="481" cy="404" r="9" fill="#b9ead8" />
            <circle cx="481" cy="404" r="4" fill="#0a3f61" />

            {{-- Le cone de lumiere, pose par-dessus le plan de travail : il tombe
                 sur la mallette et les dossiers, jamais sur l'ecran. --}}
            <path d="M478 184 L410 402 L556 402 Z" fill="url(#kw-lamp)" />
        </g>

        {{-- La mallette une fois posee, puis ouverte. --}}
        <g class="kw-case">
            <circle class="kw-case__halo" cx="400" cy="272" r="86" fill="url(#kw-halo)" />
            <g class="kw-case__lid">
                <rect x="346" y="252" width="108" height="32" rx="7" fill="#16506f" stroke="#2f7fa8" stroke-width="2" />
                <rect x="380" y="258" width="40" height="20" rx="4" fill="#5FD3AA" opacity=".9" />
                <rect x="393" y="262" width="14" height="12" fill="#0a3f61" />
                <rect x="387" y="266" width="26" height="4" fill="#0a3f61" />
            </g>
            <rect x="346" y="284" width="108" height="46" rx="7" fill="#1a6288" stroke="#3d92bb" stroke-width="2" />
            <rect x="384" y="278" width="32" height="9" rx="4" fill="#3d92bb" />
            <path d="M346 300 H454" stroke="#3d92bb" stroke-width="2" opacity=".6" />
        </g>

        {{-- Le medecin. --}}
        <g class="kw-doc">
            <g class="kw-doc__bob">
                <g class="kw-leg kw-leg--b"><rect x="219" y="348" width="15" height="84" rx="7" fill="#274b66" /></g>
                <g class="kw-leg kw-leg--a"><rect x="202" y="348" width="15" height="84" rx="7" fill="#31597a" /></g>

                {{-- Blouse blanche, col de tunique, badge professionnel. --}}
                <path d="M218 262 C 196 262, 186 276, 184 292 L180 358 C 180 364, 184 366, 190 366 L246 366 C 252 366, 256 364, 256 358 L252 292 C 250 276, 240 262, 218 262 Z" fill="url(#kw-coat)" />
                <path d="M206 264 L218 292 L230 264 L226 262 L218 278 L210 262 Z" fill="#2f7f9e" />
                <path d="M218 292 L218 366" stroke="#c6d8e3" stroke-width="2" />
                <rect x="228" y="306" width="17" height="12" rx="2.5" fill="#16A075" />
                <rect x="231" y="309" width="11" height="2.4" rx="1" fill="#eafaf3" />
                <rect x="231" y="313" width="7" height="2.4" rx="1" fill="#eafaf3" />

                {{-- Stethoscope autour du cou. --}}
                <path d="M206 266 C 202 292, 210 306, 213 314" stroke="#16A075" stroke-width="3.4" fill="none" stroke-linecap="round" />
                <path d="M230 266 C 234 290, 226 304, 222 312" stroke="#16A075" stroke-width="3.4" fill="none" stroke-linecap="round" />
                <circle cx="217" cy="318" r="5" fill="#16A075" />

                <path d="M190 276 L184 336" stroke="#e8f1f6" stroke-width="13" stroke-linecap="round" />
                <circle cx="184" cy="340" r="6.5" fill="#b0764f" />

                <rect x="212" y="248" width="13" height="16" rx="5" fill="#a06a45" />
                <circle cx="218" cy="236" r="21" fill="#b0764f" />
                <path d="M197 232 C 199 216, 210 210, 218 210 C 228 210, 239 216, 239 233 C 236 226, 230 222, 218 222 C 208 222, 200 226, 197 232 Z" fill="#2a2119" />

                {{-- Le bras qui porte la mallette, puis la depose. --}}
                <g class="kw-arm">
                    <path d="M247 276 L253 336" stroke="#e8f1f6" stroke-width="13" stroke-linecap="round" />
                    <circle cx="253" cy="340" r="6.5" fill="#b0764f" />
                    <g class="kw-case-held">
                        <rect x="248" y="344" width="11" height="9" rx="3" fill="none" stroke="#3d92bb" stroke-width="3" />
                        <rect x="228" y="352" width="52" height="38" rx="6" fill="#1a6288" stroke="#3d92bb" stroke-width="2" />
                        <path d="M228 368 H280" stroke="#3d92bb" stroke-width="2" opacity=".7" />
                        <rect x="245" y="360" width="18" height="13" rx="2" fill="#5FD3AA" />
                        <rect x="251" y="362" width="6" height="9" fill="#0a3f61" />
                        <rect x="248" y="365" width="12" height="3" fill="#0a3f61" />
                    </g>
                </g>
            </g>
        </g>
    </svg>

    {{-- Ce qui s'echappe de la mallette : les fonctions du produit, qui
         convergent vers la carte de connexion. --}}
    <div class="login-scene__orbs" aria-hidden="true">
        <span class="login-orb login-orb--1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-3-6.2" /><path d="M2 12h4l2-4 3 8 2.5-5 1.5 3h3" /></svg>
        </span>
        <span class="login-orb login-orb--2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.4-2.9 8.4-7 9.6C7.9 19.4 5 15.4 5 11V6z" /><path d="M9.5 12l1.8 1.8L15 10" /></svg>
        </span>
        <span class="login-orb login-orb--3">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6" /><path d="M4.5 20c.6-3.8 3.7-6 7.5-6s6.9 2.2 7.5 6" /></svg>
        </span>
        <span class="login-orb login-orb--4">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15" rx="2.5" /><path d="M3.5 10h17M8 3v4M16 3v4" /><path d="M8.5 14h3M10 12.5v3" /></svg>
        </span>
        <span class="login-orb login-orb--5">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v4h4" /><path d="M9 12h6M9 16h4" /></svg>
        </span>
    </div>

    <div class="login-scene__brand">
        <x-brand-logo variant="light" class="login-scene__mark" />
        <span>Espace professionnel</span>
    </div>

    <p class="login-scene__claim">
        <strong>Le poste de travail du soignant, pret avant la premiere consultation.</strong>
        <span>Accueil, file d'attente, caisse, dossier patient — au meme endroit.</span>
    </p>
</div>
