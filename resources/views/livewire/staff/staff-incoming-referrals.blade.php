<section class="card" wire:poll.15s>
    <h2 class="card__title">Renvois recus ({{ $referrals->count() }})</h2>

    @forelse ($referrals as $referral)
        <article class="referrals__item">
            <p class="referrals__meta">
                <strong>{{ $referral->patient->name }}</strong>
                <span class="mono">{{ $referral->patient->patient_code }}</span>
                — envoye par {{ $referral->prescriberName() }} ({{ $referral->fromService->name }})
            </p>
            <p class="referrals__result">{{ $referral->instructions }}</p>

            @if ($answeringReferralId === $referral->id)
                <form wire:submit="submitResult" class="form my-patients__form">
                    <div class="field">
                        <label for="staff-result-{{ $referral->id }}">Resultat</label>
                        <textarea id="staff-result-{{ $referral->id }}" rows="4" wire:model="resultText"></textarea>
                        @error('resultText') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="btn-row">
                        <button type="submit" class="btn btn--primary">Enregistrer le resultat</button>
                        <button type="button" class="btn btn--ghost" wire:click="cancel">Annuler</button>
                    </div>
                </form>
            @else
                <button type="button" class="btn btn--secondary"
                        wire:click="startAnswer({{ $referral->id }})">Saisir le resultat</button>
            @endif
        </article>
    @empty
        <p class="empty">Aucun renvoi en attente.</p>
    @endforelse
</section>
