{{-- Ordonnance au format A4, rendue par dompdf.

     Rien n'y est laisse au navigateur : dompdf ne connait ni flexbox ni grid,
     la mise en page repose donc sur des tableaux et des marges.

     La refonte visuelle reprend la maquette de reference : filets sarcelle,
     en-tete complet a droite du monogramme, tableau des lignes a bandeau plein,
     zone de conseils, et devise de l'etablissement en pied. Le document reste
     ce qu'il etait — une ordonnance REMPLIE a partir du dossier, et non un
     formulaire vierge a completer a la main. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Ordonnance {{ $prescription->patient->patient_code }}</title>
    <style>
        @page { margin: 14mm 13mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            color: #0f172a;
            margin: 0;
        }

        /* --- En-tete ---------------------------------------------------
           A gauche le monogramme et la nature du document ; a droite le nom
           de l'etablissement et ses coordonnees. C'est le nom que le
           pharmacien cherche en premier, il occupe donc la place forte. */
        .head { width: 100%; margin-bottom: 10px; }
        .head td { vertical-align: top; }

        .head .marque { width: 46%; padding-right: 12px; }
        .head .logo img { width: 52px; height: 42px; }
        .head .nature {
            font-size: 17px; font-weight: bold; line-height: 1.15;
            color: #1e3a8a; letter-spacing: -.2px;
        }
        /* Le numero de dossier dans un cadre : c'est la seule donnee que
           quelqu'un recopie a la main, elle doit se trouver d'un coup d'oeil. */
        .head .dossier {
            display: inline-block; margin-top: 5px;
            padding: 2px 8px;
            border: 1px solid #1e3a8a; border-radius: 3px;
            font-size: 11px; font-weight: bold; letter-spacing: .5px;
        }

        .head .etab { border-left: 1px solid #e2e8f0; padding-left: 14px; }
        .head .etab h1 {
            margin: 0 0 4px; padding-bottom: 4px;
            font-size: 15px; color: #1e3a8a; letter-spacing: -.2px;
            border-bottom: 2px solid #059669;
        }
        .head .coord { margin: 0; font-size: 9px; color: #334155; }
        .head .coord td { padding: 1px 0; vertical-align: top; }
        .head .coord .cle {
            width: 52px; color: #059669; font-weight: bold;
            text-transform: uppercase; font-size: 7.5px; letter-spacing: .6px;
            padding-top: 2px;
        }

        /* --- Identite du patient --------------------------------------- */
        .meta { width: 100%; border-collapse: collapse; margin: 4px 0 12px; }
        .meta td { padding: 4px 12px 4px 0; vertical-align: top; }
        .meta .label {
            color: #64748b; font-size: 8px;
            text-transform: uppercase; letter-spacing: .8px;
        }

        /* --- Lignes de l'ordonnance ------------------------------------
           Bandeau plein plutot qu'un filet : sur une ordonnance photocopiee
           puis refaxee, un filet fin disparait, un aplat non. */
        .lignes { width: 100%; border-collapse: collapse; }
        .lignes thead th {
            background: #059669; color: #fff;
            font-size: 8.5px; text-transform: uppercase; letter-spacing: .9px;
            text-align: left; padding: 6px 8px;
        }
        .lignes td {
            padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top;
        }
        .lignes .rang { width: 26px; text-align: center; color: #059669; font-weight: bold; }
        .lignes .medicament { font-weight: bold; }
        .lignes .duree { width: 92px; white-space: nowrap; }
        .lignes .vide { color: #94a3b8; text-align: center; }

        /* --- Conseils ---------------------------------------------------
           Encadre meme vide : c'est la zone ou le medecin ecrit a la main ce
           que la saisie ne prevoit pas, et un cadre absent se remplit mal. */
        .conseils {
            margin-top: 12px; padding: 8px 10px;
            border: 1px solid #cbd5e1; border-radius: 4px;
        }
        .conseils .titre {
            font-size: 8.5px; font-weight: bold; color: #1e3a8a;
            text-transform: uppercase; letter-spacing: .9px;
        }
        .conseils .texte { margin-top: 4px; min-height: 26px; }

        /* --- Signature et tampons --------------------------------------
           Chaque element manquant laisse son espace vide : une ordonnance doit
           s'imprimer pour un medecin qui n'a rien depose, et pour un
           etablissement sans tampon. */
        .sign { width: 100%; margin-top: 16px; }
        .sign td { vertical-align: top; }
        .sign .titre {
            font-size: 8.5px; font-weight: bold; color: #1e3a8a;
            text-transform: uppercase; letter-spacing: .9px;
        }
        .sign .cachet { width: 42%; }
        .sign .cadre {
            margin-top: 5px; height: 74px;
            border: 1px dashed #cbd5e1; border-radius: 4px;
        }
        .sign .medecin { width: 42%; text-align: center; }
        .sign .paraphe { height: 56px; margin-top: 5px; }
        .sign .paraphe img { max-height: 52px; max-width: 150px; }
        .sign .trait { border-top: 1px solid #1e3a8a; padding-top: 4px; }
        .sign .trait strong { display: block; font-size: 11px; }
        .sign .trait span { font-size: 8.5px; color: #64748b; }

        /* --- Pied ------------------------------------------------------- */
        .devise {
            margin-top: 18px; padding-top: 8px;
            border-top: 1px solid #059669;
            text-align: center; font-style: italic;
            font-size: 11px; font-weight: bold; color: #059669;
        }
        .foot {
            margin-top: 6px; text-align: center;
            font-size: 7.5px; color: #94a3b8;
        }
    </style>
</head>
<body>

    <table class="head">
        <tr>
            <td class="marque">
                <table>
                    <tr>
                        <td class="logo"><img src="{{ public_path('images/keneya-icone-impression.png') }}" alt=""></td>
                        <td style="padding-left: 10px;">
                            <div class="nature">ORDONNANCE<br>MEDICALE</div>
                            <div class="dossier">{{ $prescription->patient->patient_code }}</div>
                        </td>
                    </tr>
                </table>
            </td>

            <td class="etab">
                <h1>{{ $hospitalName }}</h1>

                {{-- Chaque coordonnee absente disparait avec sa ligne : un
                     libelle suivi du vide se lit comme une donnee manquante. --}}
                <table class="coord">
                    @foreach ([
                        'Adresse' => $hospitalAddress,
                        'Tel.' => $hospitalPhone,
                        'Courriel' => $hospitalEmail,
                        'Site' => $hospitalWebsite,
                        'Horaires' => $hospitalHours,
                    ] as $cle => $valeur)
                        @if (filled($valeur))
                            <tr>
                                <td class="cle">{{ $cle }}</td>
                                <td>{{ $valeur }}</td>
                            </tr>
                        @endif
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">Patient</td>
            <td class="label">Age et sexe</td>
            <td class="label">Service</td>
            <td class="label">Date</td>
        </tr>
        <tr>
            <td><strong>{{ $prescription->patient->name }}</strong></td>
            <td>{{ $prescription->patient->age }} ans, {{ $prescription->patient->gender }}</td>
            <td>{{ $prescription->visit?->service?->name ?? 'Non precise' }}</td>
            <td>{{ $prescription->created_at->format('d/m/Y') }}</td>
        </tr>
    </table>

    @php $lignes = $prescription->lignes(); @endphp

    <table class="lignes">
        <thead>
            <tr>
                <th class="rang">N&deg;</th>
                <th>Medicaments</th>
                <th>Posologie</th>
                <th class="duree">Duree</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lignes as $rang => $ligne)
                <tr>
                    <td class="rang">{{ $rang + 1 }}</td>
                    <td class="medicament">{{ $ligne['medicament'] }}</td>
                    <td>{{ $ligne['posologie'] ?: '' }}</td>
                    <td class="duree">{{ $ligne['duree'] ?: '' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="vide">Aucune ligne.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="conseils">
        <div class="titre">Conseils / Observations</div>
        {{-- Volontairement vide : l'application ne saisit pas de conseils, et
             en ajouter un champ demanderait une migration. Le cadre existe
             pour que le medecin y ecrive a la main ce que la saisie ne prevoit
             pas — sans cadre, cette annotation se perd dans la marge. --}}
        <div class="texte"></div>
    </div>

    <table class="sign">
        <tr>
            <td class="cachet">
                <span class="titre">Cachet de l'etablissement</span>
                @if ($hospitalStamp)
                    <div class="paraphe"><img src="{{ $hospitalStamp }}" alt=""></div>
                @else
                    <div class="cadre"></div>
                @endif
            </td>
            <td></td>
            <td class="medecin">
                <span class="titre">Signature et cachet du medecin</span>
                <div class="paraphe">
                    @if ($doctorSignature)
                        <img src="{{ $doctorSignature }}" alt="">
                    @endif
                    @if ($doctorStamp)
                        <img src="{{ $doctorStamp }}" alt="">
                    @endif
                </div>
                <div class="trait">
                    <strong>{{ $prescription->doctor->name() }}</strong>
                    <span>{{ $prescription->visit?->service?->name ?? 'Medecin prescripteur' }}</span>
                </div>
            </td>
        </tr>
    </table>

    @if (filled($hospitalMotto))
        <div class="devise">{{ $hospitalMotto }}</div>
    @endif

    <div class="foot">
        {{ $productName }} &middot; Ordonnance {{ $prescription->patient->patient_code }}
        du {{ $prescription->created_at->format('d/m/Y') }} a {{ $prescription->created_at->format('H:i') }}
    </div>
</body>
</html>
