<section class="card">
    <h2 class="card__title">Types de service</h2>
    <p class="hint">
        Un type coche « paiement prealable » impose un passage par la
        <strong>{{ \App\Models\Service::CAISSE_SERVICES }}</strong> avant qu'un patient
        renvoye vers un service de ce type y soit pris en charge.
    </p>

    <form wire:submit="save" class="form form--inline-wrap">
        <div class="field">
            <label for="kind-name">Nom du type</label>
            <input id="kind-name" type="text" wire:model="name" placeholder="Imagerie, Pharmacie…">
            @error('name') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field field--check">
            <label for="kind-gate">
                <input id="kind-gate" type="checkbox" wire:model="requires_payment_gate">
                Paiement prealable a la prise en charge
            </label>
            @error('requires_payment_gate') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn--primary">
                {{ $editingId ? 'Enregistrer' : 'Ajouter le type' }}
            </button>
            @if ($editingId)
                <button type="button" class="btn btn--ghost" wire:click="cancel">Annuler</button>
            @endif
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Type</th><th>Paiement prealable</th><th>Services</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($kinds as $kind)
                    <tr>
                        <td>
                            {{ $kind->name }}
                            @if ($kind->isBuiltIn())
                                <span class="badge badge--neutral">d'origine</span>
                            @endif
                        </td>
                        <td>{{ $kind->requires_payment_gate ? 'Oui' : 'Non' }}</td>
                        <td>{{ $kind->services_count }}</td>
                        <td>
                            <div class="btn-row">
                                <button type="button" class="btn btn--ghost"
                                        wire:click="edit({{ $kind->id }})">Modifier</button>
                                {{-- Un type d'origine ou encore utilise n'est pas supprimable. --}}
                                @if (! $kind->isBuiltIn() && $kind->services_count === 0)
                                    <button type="button" class="btn btn--ghost"
                                            wire:click="delete({{ $kind->id }})"
                                            wire:confirm="Supprimer ce type de service ?">Supprimer</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
