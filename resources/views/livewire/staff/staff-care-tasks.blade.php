<section class="card" wire:poll.30s>
    <div class="card__head">
        <h2 class="card__title">Soins programmes — {{ $service->name }}</h2>
        <label class="field--check">
            <input type="checkbox" wire:model.live="showDone">
            Afficher les soins deja traites
        </label>
    </div>

    @if (! $onDuty)
        {{-- Le filtrage de garde s'appuie sur le planning deja en place : hors
             de ses heures, on ne voit pas les soins du service. --}}
        <p class="empty">
            Vous n'etes pas de garde sur ce service en ce moment. Les soins
            apparaitront des qu'un creneau de planning couvrira l'heure courante.
        </p>
    @else
        @forelse ($tasks as $task)
            <article class="queue__item
                @if ($task->isLate()) care--late @endif
                @if ($task->assigned_to_user_id === $myUserId) care--assigned @endif">
                <span class="care__time mono">{{ $task->scheduled_at->format('d/m H:i') }}</span>

                <span class="queue__identity">
                    <strong>{{ $task->type->name }}</strong>
                    <span>
                        {{ $task->hospitalization->patient->name }}
                        <span class="mono">{{ $task->hospitalization->patient->patient_code }}</span>
                        @if ($task->hospitalization->room) — {{ $task->hospitalization->room->name }} @endif
                    </span>
                    @if ($task->instructions)
                        <span class="hint">{{ $task->instructions }}</span>
                    @endif
                    @if ($task->assigned_to_user_id)
                        <span class="hint">
                            Confie a {{ $task->assignedTo?->name }}
                            @if ($task->assigned_to_user_id !== $myUserId)
                                — vous pouvez le prendre en charge s'il est indisponible
                            @endif
                        </span>
                    @endif
                </span>

                <span class="badge badge--{{ $task->isDone() ? 'closed' : ($task->isLate() ? 'called' : 'waiting') }}">
                    {{ $task->statusLabel() }}
                </span>

                @if ($task->status === \App\Models\CareTask::STATUS_PENDING)
                    <div class="btn-row">
                        <button type="button" class="btn btn--primary"
                                wire:click="markDone({{ $task->id }})">Marquer comme fait</button>
                        <button type="button" class="btn btn--ghost"
                                wire:click="markMissed({{ $task->id }})"
                                wire:confirm="Marquer ce soin comme manque ?">Manque</button>
                    </div>
                @elseif ($task->completedBy)
                    <span class="hint">Par {{ $task->completedBy->name }}</span>
                @endif
            </article>
        @empty
            <p class="empty">Aucun soin programme pour ce service.</p>
        @endforelse
    @endif
</section>
