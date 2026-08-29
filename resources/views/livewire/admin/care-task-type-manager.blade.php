<section class="card">
    <h2 class="card__title">Types de soins</h2>
    <p class="hint">Serum, injection, pansement… la liste s'enrichit depuis ici.</p>

    <form wire:submit="save" class="form form--inline-wrap">
        <div class="field">
            <label for="care-type-name">Nom du type de soin</label>
            <input id="care-type-name" type="text" wire:model="name" placeholder="Injection">
            @error('name') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn--primary">Ajouter</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Type de soin</th><th>Soins programmes</th><th></th></tr></thead>
            <tbody>
                @forelse ($types as $type)
                    <tr>
                        <td>{{ $type->name }}</td>
                        <td>{{ $type->tasks_count }}</td>
                        <td>
                            @if ($type->tasks_count === 0)
                                <button type="button" class="btn btn--ghost"
                                        wire:click="delete({{ $type->id }})"
                                        wire:confirm="Supprimer ce type de soin ?">Supprimer</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="empty">Aucun type de soin enregistre.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
