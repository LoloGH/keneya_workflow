<section class="card">
    <h2 class="card__title">Salles</h2>
    <p class="hint">
        L'occupation est comptee a la volee sur les hospitalisations en cours :
        aucun compteur a tenir a jour.
    </p>

    <form wire:submit="save" class="form form--inline-wrap">
        <div class="field">
            <label for="room-name">Nom de la salle</label>
            <input id="room-name" type="text" wire:model="name" placeholder="Salle 3">
            @error('name') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="room-service">Service responsable</label>
            <select id="room-service" wire:model="service_id">
                <option value="">— Choisir —</option>
                @foreach ($services as $service)
                    <option value="{{ $service->id }}">{{ $service->name }}</option>
                @endforeach
            </select>
            @error('service_id') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="room-capacity">Nombre de lits</label>
            <input id="room-capacity" type="number" min="1" wire:model="capacity">
            @error('capacity') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn--primary">
                {{ $editingId ? 'Enregistrer' : 'Ajouter la salle' }}
            </button>
            @if ($editingId)
                <button type="button" class="btn btn--ghost" wire:click="cancel">Annuler</button>
            @endif
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Salle</th><th>Service</th><th>Occupation</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($rooms as $room)
                    <tr>
                        <td>{{ $room->name }}</td>
                        <td>{{ $room->service->name }}</td>
                        <td>
                            <span class="badge badge--{{ $room->occupancy_count >= $room->capacity ? 'called' : 'waiting' }}">
                                {{ $room->occupancy_count }}/{{ $room->capacity }} lits occupes
                            </span>
                        </td>
                        <td>
                            <div class="btn-row">
                                <button type="button" class="btn btn--ghost"
                                        wire:click="edit({{ $room->id }})">Modifier</button>
                                @if ($room->occupancy_count === 0)
                                    <button type="button" class="btn btn--ghost"
                                            wire:click="delete({{ $room->id }})"
                                            wire:confirm="Supprimer cette salle ?">Supprimer</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">Aucune salle enregistree.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
