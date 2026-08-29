<section class="card">
    <h2 class="card__title">Personnel a interface dediee</h2>
    <p class="hint">
        Les medecins, receptionnistes et caissiers restent geres dans leurs
        sections respectives. Cette section couvre les types crees par
        l'administrateur, qui disposent de leur propre interface.
    </p>

    @if ($types->isEmpty())
        <p class="empty">
            Creez d'abord un type de personnel a interface dediee dans « Types de personnel ».
        </p>
    @else
        <form wire:submit="save" class="form form--inline-wrap">
            <div class="field">
                <label for="staff-name">Nom</label>
                <input id="staff-name" type="text" wire:model="name">
                @error('name') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="staff-email">Adresse e-mail</label>
                <input id="staff-email" type="email" wire:model="email">
                @error('email') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="staff-password">
                    Mot de passe
                    @if ($editingId) <span class="field__hint">laisser vide pour ne pas changer</span> @endif
                </label>
                <input id="staff-password" type="password" wire:model="password" autocomplete="new-password">
                @error('password') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="staff-type">Type</label>
                <select id="staff-type" wire:model="staff_type_id">
                    <option value="">— Choisir —</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                @error('staff_type_id') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="staff-service">Service</label>
                <select id="staff-service" wire:model="service_id">
                    <option value="">— Choisir —</option>
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}">{{ $service->name }}</option>
                    @endforeach
                </select>
                @error('service_id') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="btn-row">
                <button type="submit" class="btn btn--primary">
                    {{ $editingId ? 'Enregistrer' : 'Ajouter' }}
                </button>
                @if ($editingId)
                    <button type="button" class="btn btn--ghost" wire:click="cancel">Annuler</button>
                @endif
            </div>
        </form>
    @endif

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Nom</th><th>Type</th><th>Service</th><th>Interface</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr>
                        <td>{{ $member->user->name }}</td>
                        <td>{{ $member->staffType->name }}</td>
                        <td>{{ $member->service?->name ?? '—' }}</td>
                        <td><code>/staff/{{ $member->staffType->slug }}</code></td>
                        <td>
                            <button type="button" class="btn btn--ghost"
                                    wire:click="edit({{ $member->id }})">Modifier</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">Aucun membre du personnel a interface dediee.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
