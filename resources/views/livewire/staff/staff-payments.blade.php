<section class="card">
    <div class="card__head">
        <h2 class="card__title">Encaissement</h2>
        <p class="hint">
            Encaisse aujourd'hui : <strong>{{ number_format($todayTotal, 0, ',', ' ') }} FCFA</strong>
        </p>
    </div>

    @forelse ($queue as $visit)
        <article class="queue__item">
            <span class="queue__token mono">{{ $visit->token }}</span>
            <span class="queue__identity">
                <strong>{{ $visit->patient->name }}</strong>
                <span class="mono">{{ $visit->patient->patient_code }}</span>
            </span>
            <button type="button" class="btn btn--secondary"
                    wire:click="startPayment({{ $visit->id }})">Encaisser</button>
        </article>

        @if ($payingVisitId === $visit->id)
            <form wire:submit="record" class="form my-patients__form">
                <div class="field">
                    <label for="staff-amount-{{ $visit->id }}">Montant <span class="field__hint">en FCFA</span></label>
                    <input id="staff-amount-{{ $visit->id }}" type="number" min="1" wire:model="amount">
                    @error('amount') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn--primary">Enregistrer</button>
                    <button type="button" class="btn btn--ghost" wire:click="cancel">Annuler</button>
                </div>
            </form>
        @endif
    @empty
        <p class="empty">Aucun patient a encaisser aujourd'hui.</p>
    @endforelse
</section>
