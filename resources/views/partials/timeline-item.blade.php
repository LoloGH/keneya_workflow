{{--
    Un evenement de la frise chronologique du dossier (v3.2, point 3).

    Les URL de telechargement et d'impression different d'un role a l'autre :
    elles sont donc passees en parametre, jamais codees en dur ici. Passer une
    route a null suffit a masquer l'action pour un role qui n'y a pas droit —
    y compris $attachmentRoute, auquel cas la piece est nommee sans etre
    telechargeable (lecture seule).

    Attend : $item, $attachmentRoute, $attachmentPrintRoute, $prescriptionPrintRoute
--}}
@php
    $attachmentRoute ??= null;
    $attachmentPrintRoute ??= null;
    $prescriptionPrintRoute ??= null;
@endphp

<li class="timeline__item timeline__item--{{ $item['type'] }}">
    <p class="timeline__head">
        <span class="timeline__type">{{ $item['label'] }}</span>
        <time>{{ $item['at']?->format('d/m/Y H:i') }}</time>
    </p>

    <p class="timeline__body">{{ $item['description'] }}</p>

    @if ($item['service'] || $item['doctor'])
        <p class="timeline__meta">
            {{ $item['service']?->name }}
            @if ($item['doctor']) — {{ $item['doctor']->name() }} @endif
        </p>
    @endif

    @if ($item['prescription'] && $prescriptionPrintRoute)
        <p class="timeline__actions">
            <a href="{{ route($prescriptionPrintRoute, $item['prescription']) }}"
               target="_blank" rel="noopener" class="btn btn--ghost btn--small">
                Imprimer l'ordonnance
            </a>
        </p>
    @endif

    @if ($item['attachments']->isNotEmpty())
        <ul class="attachments">
            @foreach ($item['attachments'] as $attachment)
                <li>
                    @if ($attachmentRoute)
                        <a href="{{ route($attachmentRoute, $attachment) }}" class="attachments__link">
                            {{ $attachment->original_name }}
                            <span class="attachments__size">{{ $attachment->humanSize() }}</span>
                        </a>
                    @else
                        <span class="attachments__link">
                            {{ $attachment->original_name }}
                            <span class="attachments__size">{{ $attachment->humanSize() }}</span>
                        </span>
                    @endif

                    @if ($attachmentPrintRoute)
                        <a href="{{ route($attachmentPrintRoute, $attachment) }}"
                           target="_blank" rel="noopener" class="btn btn--ghost btn--small">
                            Imprimer
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</li>
