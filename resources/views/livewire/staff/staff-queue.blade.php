<section class="card" wire:poll.10s>
    <div class="card__head">
        <h2 class="card__title">File d'attente — {{ $service->name }}</h2>
        @if ($type->can(\App\Models\StaffType::CAP_QUEUE))
            <button type="button" class="btn btn--primary" wire:click="callNext" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="callNext">Appeler le suivant</span>
                <span wire:loading wire:target="callNext">Appel…</span>
            </button>
        @endif
    </div>

    @forelse ($queue as $visit)
        <article class="queue__item queue__item--{{ $visit->status }}">
            <span class="queue__token mono">{{ $visit->token }}</span>

            <span class="queue__identity">
                <strong>{{ $visit->patient->name }}</strong>
                <span class="mono">{{ $visit->patient->patient_code }}</span>
                @if ($visit->pendingNextService)
                    <span class="hint">Puis : {{ $visit->pendingNextService->name }}</span>
                @endif
            </span>

            <span class="badge badge--{{ $visit->status }}">{{ $visit->statusLabel() }}</span>

            <div class="btn-row">
                @if ($type->can(\App\Models\StaffType::CAP_VIEW_DOSSIER))
                    <button type="button" class="btn btn--ghost"
                            wire:click="showRecord({{ $visit->patient_id }})">Dossier</button>
                @endif

                @if ($type->can(\App\Models\StaffType::CAP_SEND_REFERRAL))
                    <button type="button" class="btn btn--secondary"
                            wire:click="startReferral({{ $visit->id }})">Envoyer vers un service</button>
                @endif

                @if ($type->can(\App\Models\StaffType::CAP_CLOSE_VISIT))
                    <button type="button" class="btn btn--ghost"
                            wire:click="closeVisit({{ $visit->id }})"
                            wire:confirm="Cloturer ce dossier ?">Cloturer</button>
                @endif

                @if ($type->can(\App\Models\StaffType::CAP_PRINT_TICKET))
                    <a href="{{ route('staff.ticket', [$type->slug, $visit]) }}" target="_blank"
                       rel="noopener" class="btn btn--ghost">Imprimer le ticket</a>
                @endif
            </div>
        </article>

        @if ($referringVisitId === $visit->id)
            <form wire:submit="sendReferral" class="form my-patients__form">
                <div class="field">
                    <label for="staff-to-service">Service destinataire</label>
                    <select id="staff-to-service" wire:model="toServiceId">
                        <option value="">— Choisir —</option>
                        @foreach ($otherServices as $autre)
                            <option value="{{ $autre->id }}">{{ $autre->name }}</option>
                        @endforeach
                    </select>
                    @error('toServiceId') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="staff-instructions">Instructions</label>
                    <textarea id="staff-instructions" rows="3" wire:model="instructions"></textarea>
                    @error('instructions') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn--primary">Envoyer</button>
                    <button type="button" class="btn btn--ghost" wire:click="cancelReferral">Annuler</button>
                </div>
            </form>
        @endif
    @empty
        <p class="empty">Aucun patient dans cette file aujourd'hui.</p>
    @endforelse
</section>
