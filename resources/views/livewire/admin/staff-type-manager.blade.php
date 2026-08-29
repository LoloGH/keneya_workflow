<section class="card">
    <h2 class="card__title">Types de personnel</h2>
    <p class="hint">
        Un type <strong>adosse a un role</strong> reutilise telle quelle une des quatre
        interfaces existantes. Un type <strong>sans role</strong> recoit sa propre interface
        sur <code>/staff/&lt;slug&gt;</code>, composee des seules fonctions cochees ci-dessous.
    </p>

    <form wire:submit="save" class="form">
        <div class="form form--inline-wrap">
            <div class="field">
                <label for="staff-type-name">Nom du type</label>
                <input id="staff-type-name" type="text" wire:model="name" placeholder="Infirmier, Sage-femme…">
                @error('name') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="staff-type-role">Interface</label>
                <select id="staff-type-role" wire:model.live="matched_role">
                    <option value="">Interface dediee (/staff/…)</option>
                    @foreach ($this->reusableRoles() as $role => $label)
                        <option value="{{ $role }}">Reutiliser l'interface « {{ $label }} »</option>
                    @endforeach
                </select>
                @error('matched_role') <p class="field__error">{{ $message }}</p> @enderror
            </div>
        </div>

        @if ($matched_role === '')
            <fieldset class="weekdays">
                <legend>Fonctions de ce type</legend>
                <div class="capabilities">
                    @foreach ($allCapabilities as $capability => $meta)
                        <label class="weekdays__day">
                            <input type="checkbox" value="{{ $capability }}" wire:model.live="capabilities">
                            <span>{{ $meta['label'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error('capabilities') <p class="field__error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- L'admin doit voir ce qu'il vient de creer avant qu'un membre du
                 personnel ne s'y connecte. --}}
            <div class="preview">
                <h3 class="card__subtitle">Ce que cette personne verra</h3>
                <ul class="preview__list">
                    @foreach ($this->previewSections() as $section)
                        <li>{{ $section }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <p class="hint hint--blocking">
                Ce type utilisera l'interface deja en place du role choisi. Aucune
                fonction n'est a cocher : son fonctionnement ne change pas.
            </p>
        @endif

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
                <tr><th>Type</th><th>Interface</th><th>Fonctions</th><th>Personnes</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($types as $type)
                    <tr>
                        <td>{{ $type->name }}</td>
                        <td>
                            @if ($type->usesFixedRole())
                                {{ \App\Support\Roles::label($type->matched_role) }}
                            @else
                                <code>/staff/{{ $type->slug }}</code>
                            @endif
                        </td>
                        <td>{{ $type->usesFixedRole() ? '—' : count($type->capabilities ?? []) }}</td>
                        <td>{{ $type->members_count }}</td>
                        <td>
                            <div class="btn-row">
                                <button type="button" class="btn btn--ghost"
                                        wire:click="edit({{ $type->id }})">Modifier</button>
                                @if ($type->members_count === 0)
                                    <button type="button" class="btn btn--ghost"
                                            wire:click="delete({{ $type->id }})"
                                            wire:confirm="Supprimer ce type de personnel ?">Supprimer</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
