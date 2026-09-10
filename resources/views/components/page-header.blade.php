{{-- En-tete de page : fil d'ariane, titre, sous-titre.

     Le fil ne quitte jamais l'espace courant : une interface ne mene jamais
     vers une autre — un role, une interface. Mais a l'interieur d'un espace,
     ses maillons menent quelque part, et ils sont cliquables :

       - le premier porte le nom de l'espace (« Accueil », « Service »,
         « Caisse », « Poste ») et ramene a sa premiere section ;
       - un maillon intermediaire porte un intitule de famille de la barre
         laterale (« Etablissement », « Gestion », « Systeme ») et mene a la
         premiere section de cette famille — ce que ferait la barre ;
       - le dernier est la page courante et n'est donc pas une cible.

     Ce sont des boutons et non des liens : une section n'a pas d'adresse
     propre, c'est un etat de la barre laterale. Le changement se fait donc
     sans rechargement, exactement comme un clic dans la barre.

     Les vues de section etant incluses par le composant de navigation, ces
     `wire:click` s'adressent directement a lui. Un intitule intermediaire qui
     ne correspond a aucune famille ne fait rien plutot que d'echouer.

     Usage :
         <x-page-header
             :fil="['Accueil', 'Etablissement']"
             titre="Informations de l'etablissement"
             sous-titre="Ces informations apparaitront sur toutes les ordonnances et tickets imprimes." /> --}}
@props([
    'fil' => [],
    'titre',
    'sousTitre' => null,
])

<header {{ $attributes->merge(['class' => 'page-header']) }}>
    @if ($fil !== [])
        <nav class="fil" aria-label="Vous etes ici">
            <ol class="fil__liste">
                @foreach ($fil as $etape)
                    <li class="fil__etape @if ($loop->last) fil__etape--courante @endif"
                        @if ($loop->last) aria-current="page" @endif>
                        @unless ($loop->first)
                            <x-icon name="chevron" size="14" class="fil__separateur" />
                        @endunless

                        @if ($loop->last)
                            <span>{{ $etape }}</span>
                        @else
                            <button type="button" class="fil__lien"
                                    wire:click="{{ $loop->first ? 'selectPremier' : "selectFamille('".addslashes($etape)."')" }}">
                                {{ $etape }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <h1 class="page-header__titre">{{ $titre }}</h1>

    @if ($sousTitre)
        <p class="page-header__sous-titre">{{ $sousTitre }}</p>
    @endif
</header>
