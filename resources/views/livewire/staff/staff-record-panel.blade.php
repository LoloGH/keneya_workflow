<aside class="card record">
    @if (! $patient)
        <h2 class="card__title">Dossier patient</h2>
        <p class="empty">Selectionnez un patient dans la file pour ouvrir son dossier.</p>
    @else
        <div class="card__head">
            <h2 class="card__title">Dossier {{ $patient->patient_code }}</h2>
            <button type="button" class="btn btn--ghost" wire:click="close">Fermer</button>
        </div>

        <dl class="record__identity">
            <div><dt>Nom</dt><dd>{{ $patient->name }}</dd></div>
            <div><dt>Age</dt><dd>{{ $patient->age }} ans</dd></div>
            <div><dt>Sexe</dt><dd>{{ $patient->gender }}</dd></div>
            <div><dt>Telephone</dt><dd>{{ $patient->mobile }}</dd></div>
        </dl>

        <h3 class="card__subtitle">Parcours par episode</h3>

        @forelse ($episodes as $episode)
            <article class="episode">
                <header class="episode__head">
                    <span class="episode__service">{{ $episode['visit']->service->name }}</span>
                    <span class="badge badge--{{ $episode['visit']->status }}">
                        {{ $episode['visit']->statusLabel() }}
                    </span>
                    <time>{{ $episode['visit']->opened_at?->format('d/m/Y') }}</time>
                </header>

                <ol class="timeline">
                    @forelse ($episode['entries'] as $item)
                        {{-- Lecture seule : ni impression, ni telechargement.
                             Ce type consulte le parcours, il ne diffuse pas les
                             pieces du dossier. --}}
                        @include('partials.timeline-item', [
                            'item' => $item,
                            'attachmentRoute' => null,
                            'attachmentPrintRoute' => null,
                            'prescriptionPrintRoute' => null,
                        ])
                    @empty
                        <li class="empty">Aucun evenement enregistre pour ce passage.</li>
                    @endforelse
                </ol>
            </article>
        @empty
            <p class="empty">Aucun passage enregistre.</p>
        @endforelse
    @endif
</aside>
