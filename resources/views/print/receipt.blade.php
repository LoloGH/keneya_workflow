{{-- Recu d'encaissement, format ticket. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reçu — {{ $hospitalName }}</title>
    <style>
        body { margin: 0; padding: 12px; font-family: "DejaVu Sans", system-ui, sans-serif; background: #f1f5f9;
               color: #1e293b; display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .ticket { width: 100%; max-width: 44ch; background: #fff; padding: 14px 16px;
                  border: 1px solid #cbd5e1; border-radius: 6px; }
        /* Le monogramme coiffe le ticket. Volontairement petit : sur un rouleau
           thermique il sort en niveaux de gris, il doit rester net. */
        .ticket__logo { display: block; width: 52px; height: auto; margin: 0 auto 6px; }
        .ticket__hospital { margin: 0; font-size: 1rem; font-weight: 700; text-align: center; }
        .ticket__product { margin: 2px 0 10px; font-size: .72rem; text-align: center; color: #64748b; }
        .ticket__rule { border: 0; border-top: 1px dashed #94a3b8; margin: 10px 0; }
        .ticket__kind { margin: 0 0 6px; text-align: center; font-size: .72rem;
                        text-transform: uppercase; letter-spacing: .12em; color: #64748b; }
        .ticket__amount { margin: 0; text-align: center; font-size: 2.2rem; font-weight: 800; }
        .rows { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: .84rem; }
        .rows th { text-align: left; font-weight: 400; color: #64748b; padding: 3px 0; }
        .rows td { text-align: right; font-weight: 700; padding: 3px 0; }
        .actions button { min-height: 44px; padding: .55rem 1.1rem; font: inherit; font-weight: 600;
                          border-radius: 8px; border: 1px solid #2563eb; background: #2563eb; color: #fff; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; display: block; }
            .actions { display: none !important; }
            .ticket { max-width: none; border: 0; border-radius: 0; padding: 0; }
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

        <p class="ticket__kind">Reçu de paiement</p>
        <p class="ticket__amount">{{ $payment->formattedAmount() }}</p>

        <table class="rows">
            <tr><th>Dossier</th><td>{{ $payment->patient->patient_code }}</td></tr>
            <tr><th>Nom</th><td>{{ $payment->patient->name }}</td></tr>
            {{-- L'acte facture, nomme (v3.2.8, point 3) : un recu qui ne dit
                 pas ce qui a ete paye fait perdre au catalogue une bonne part
                 de son interet. A defaut d'acte — encaissements anterieurs au
                 catalogue — on retombe sur le type d'encaissement. --}}
            <tr><th>Objet</th><td>{{ $payment->subjectLabel() }}</td></tr>
            @if ($payment->billableItem)
                <tr><th>Tarif</th><td>{{ $payment->billableItem->formattedPrice() }}</td></tr>
                @if ($payment->isOverridden())
                    <tr><th>Montant applique</th><td>{{ $payment->formattedAmount() }}</td></tr>
                @endif
            @endif
            @if ($payment->service)
                <tr><th>Service</th><td>{{ $payment->service->name }}</td></tr>
            @endif
            <tr><th>Encaisse par</th><td>{{ $payment->recordedBy?->name }}</td></tr>
            <tr><th>Date</th><td>{{ $payment->created_at->format('d/m/Y H:i') }}</td></tr>
        </table>
    </div>

    <div class="actions"><button type="button" onclick="window.print()">Imprimer</button></div>
    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
