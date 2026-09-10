{{--
    Ticket d'accueil, patient ou visiteur (v3.2, point 9).

    Une page autonome : ni barre de navigation, ni navigation verticale, rien
    d'autre que le ticket. Le format s'adapte de l'imprimante thermique
    (58-80 mm) au A4 — aucune mesure ne suppose un format de papier precis.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket — {{ $hospitalName }}</title>
    <style>
        :root { --encre: #1e293b; --gris: #64748b; }

        * { box-sizing: border-box; }

        body {
            margin: 0; padding: 12px;
            font-family: "DejaVu Sans", system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            color: var(--encre); background: #f1f5f9;
            display: flex; flex-direction: column; align-items: center; gap: 14px;
        }

        /* Largeur en `ch` plutot qu'en millimetres : le ticket reste lisible
           sur un rouleau de 58 mm comme sur une feuille A4. */
        .ticket {
            width: 100%; max-width: 44ch;
            background: #fff; padding: 14px 16px;
            border: 1px solid #cbd5e1; border-radius: 6px;
        }

        /* Le monogramme coiffe le ticket. Volontairement petit : sur un rouleau
           thermique il sort en niveaux de gris, il doit rester net. */
        .ticket__logo { display: block; width: 52px; height: auto; margin: 0 auto 6px; }
        .ticket__hospital { margin: 0; font-size: 1rem; font-weight: 700; text-align: center; }
        .ticket__product { margin: 2px 0 10px; font-size: .72rem; text-align: center; color: var(--gris); }

        .ticket__rule { border: 0; border-top: 1px dashed #94a3b8; margin: 10px 0; }

        .ticket__kind {
            margin: 0 0 6px; text-align: center; font-size: .72rem;
            text-transform: uppercase; letter-spacing: .12em; color: var(--gris);
        }

        .ticket__token { margin: 0; text-align: center; font-size: 3.2rem; font-weight: 800; line-height: 1; }
        .ticket__service { margin: 4px 0 0; text-align: center; font-size: 1.05rem; font-weight: 600; }

        .ticket__rows { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: .84rem; }
        .ticket__rows th {
            text-align: left; font-weight: 400; color: var(--gris);
            padding: 3px 0; white-space: nowrap; vertical-align: top;
        }
        .ticket__rows td { text-align: right; font-weight: 700; padding: 3px 0; }

        .ticket__code {
            margin-top: 10px; padding: 8px; text-align: center;
            border: 2px dashed #1e293b; border-radius: 6px;
        }
        .ticket__code span { display: block; font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; color: var(--gris); }
        .ticket__code strong { font-size: 1.6rem; letter-spacing: .35em; }

        .ticket__foot { margin: 10px 0 0; text-align: center; font-size: .68rem; color: var(--gris); }

        .actions { display: flex; gap: 8px; }
        .actions button {
            min-height: 44px; padding: .55rem 1.1rem; font: inherit; font-weight: 600;
            border-radius: 8px; border: 1px solid #2563eb; cursor: pointer;
            background: #2563eb; color: #fff;
        }
        .actions button.secondary { background: #fff; color: #1e3a8a; border-color: #cbd5e1; }

        @media print {
            /* Seul le ticket sort de l'imprimante. */
            body { background: #fff; padding: 0; display: block; }
            .actions, .app-header, .tabnav, .workspace__toggle { display: none !important; }
            .ticket { max-width: none; width: auto; border: 0; border-radius: 0; padding: 0; }
            @page { margin: 6mm; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <img class="ticket__logo" src="{{ asset('images/keneya-icone-impression.png') }}"
             alt="" width="200" height="158">
        <p class="ticket__hospital">{{ $hospitalName }}</p>
        <p class="ticket__product">{{ config('keneya.name') }}</p>

        <hr class="ticket__rule">

        @if ($kind === 'patient')
            <p class="ticket__kind">Ticket patient</p>
            <p class="ticket__token">{{ $visit->token }}</p>
            <p class="ticket__service">{{ $visit->service->name }}</p>

            <table class="ticket__rows">
                <tr><th>Dossier</th><td>{{ $visit->patient->patient_code }}</td></tr>
                <tr><th>Nom</th><td>{{ $visit->patient->name }}</td></tr>
                @if ($visit->pendingNextService)
                    <tr><th>Puis</th><td>{{ $visit->pendingNextService->name }}</td></tr>
                @endif
                <tr><th>Date</th><td>{{ ($visit->opened_at ?? now())->format('d/m/Y H:i') }}</td></tr>
            </table>

            {{-- Le code d'acces figure sur le ticket : la receptionniste n'a pas
                 a le communiquer oralement en plus. --}}
            <div class="ticket__code">
                <span>Code personnel</span>
                <strong>{{ $visit->patient->access_code }}</strong>
            </div>
            <p class="ticket__foot">A conserver pour consulter vos documents en ligne.</p>
        @else
            <p class="ticket__kind">Ticket visiteur</p>
            <p class="ticket__token">{{ $visitor->token ?? '—' }}</p>
            <p class="ticket__service">{{ $visitor->service->name }}</p>

            <table class="ticket__rows">
                <tr><th>Fiche</th><td>{{ $visitor->visitor_code }}</td></tr>
                <tr><th>Visiteur</th><td>{{ $visitor->name }}</td></tr>
                @if ($visitor->patient)
                    <tr><th>Visite a</th><td>{{ $visitor->patient->name }}</td></tr>
                @endif
                <tr><th>Date</th><td>{{ $visitor->created_at->format('d/m/Y H:i') }}</td></tr>
            </table>
        @endif
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <button type="button" class="secondary" onclick="window.close()">Fermer</button>
    </div>

    <script>
        // Impression proposee d'emblee : la receptionniste enchaine les tickets.
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
