<section class="card">
    <h2 class="card__title">Services</h2>

    <form wire:submit="save" class="form form--inline-wrap">
        <div class="field">
            <label for="service-name">Nom du service</label>
            <input id="service-name" type="text" wire:model="name">
            @error('name') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="service-kind">Type</label>
            <select id="service-kind" wire:model="service_kind_id">
                <option value="">— Choisir un type —</option>
                @foreach ($kinds as $kind)
                    <option value="{{ $kind->id }}">
                        {{ $kind->name }}@if ($kind->requires_payment_gate) — paiement prealable @endif
                    </option>
                @endforeach
            </select>
            @error('service_kind_id') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn--primary">
                {{ $editingId ? 'Enregistrer' : 'Ajouter le service' }}
            </button>
            @if ($editingId)
                <button type="button" class="btn btn--ghost" wire:click="cancel">Annuler</button>
            @endif
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Type</th>
                    <th>Medecins</th>
                    <th>Passages</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($services as $service)
                    <tr>
                        <td>{{ $service->name }}</td>
                        <td>{{ $service->kindLabel() }}</td>
                        <td class="mono">{{ $service->doctors_count }}</td>
                        <td class="mono">{{ $service->visits_count }}</td>
                        <td>
                            <div class="btn-row">
                                <button type="button" class="btn btn--ghost" wire:click="edit({{ $service->id }})">
                                    Modifier
                                </button>
                                <button type="button" class="btn btn--ghost" wire:click="delete({{ $service->id }})"
                                        wire:confirm="Supprimer le service « {{ $service->name }} » ?">
                                    Supprimer
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">Aucun service.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
