<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $attachment->original_name }} — {{ $hospitalName }}</title>
    <style>
        body { margin: 0; padding: 16px; font-family: system-ui, sans-serif; background: #f1f5f9; color: #1e293b; }
        .sheet { max-width: 900px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; }
        .sheet__head { display: flex; align-items: center; gap: 14px;
                       border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 16px; }
        .sheet__head__logo { flex: 0 0 auto; width: 44px; height: auto; }
        .sheet__head h1 { margin: 0; font-size: 1.1rem; color: #1e3a8a; }
        .sheet__head p { margin: 2px 0 0; font-size: .85rem; color: #64748b; }
        .sheet img { display: block; max-width: 100%; height: auto; margin: 0 auto; }
        .sheet embed, .sheet iframe { width: 100%; height: 78vh; border: 1px solid #cbd5e1; }
        .actions { max-width: 900px; margin: 14px auto 0; display: flex; gap: 8px; }
        .actions a, .actions button {
            min-height: 44px; padding: .55rem 1.1rem; font: inherit; font-weight: 600;
            border-radius: 8px; border: 1px solid #2563eb; cursor: pointer; text-decoration: none;
            background: #2563eb; color: #fff; display: inline-flex; align-items: center;
        }
        .actions .secondary { background: #fff; color: #1e3a8a; border-color: #cbd5e1; }

        @media print {
            body { background: #fff; padding: 0; }
            .actions { display: none !important; }
            .sheet { max-width: none; padding: 0; border-radius: 0; }
            .sheet embed, .sheet iframe { height: auto; min-height: 90vh; border: 0; }
            @page { margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="sheet__head">
            <img class="sheet__head__logo" src="{{ asset('images/keneya-icone-impression.png') }}"
                 alt="" width="200" height="158">
            <div>
            <h1>{{ $hospitalName }}</h1>
            <p>
                {{ $attachment->original_name }} —
                dossier {{ $attachment->patient->patient_code }} —
                {{ $attachment->created_at->format('d/m/Y H:i') }}
            </p>
            </div>
        </div>

        @if ($attachment->isImage())
            <img src="{{ $downloadUrl }}" alt="{{ $attachment->original_name }}">
        @else
            {{-- Un PDF s'imprime depuis le visualiseur du navigateur. --}}
            <embed src="{{ $downloadUrl }}" type="{{ $attachment->mime_type }}">
        @endif
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a class="secondary" href="{{ $downloadUrl }}">Telecharger</a>
    </div>
</body>
</html>
