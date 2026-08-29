<div class="stack">
    <section class="card">
        <h2 class="card__title">Hospitaliser un patient</h2>

        @if ($callable->isEmpty())
            <p class="empty">Aucun patient appele en ce moment.</p>
        @else
            @foreach ($callable as $visit)
                <article class="queue__item">
                    <span class="queue__token mono">{{ $visit->token }}</span>
                    <span class="queue__identity">
                        <strong>{{ $visit->patient->name }}</strong>
                        <span class="mono">{{ $visit->patient->patient_code }}</span>
                    </span>
                    <button type="button" class="btn btn--secondary"
                            wire:click="startAdmission({{ $visit->id }})">Hospitaliser</button>
                </article>

                @if ($admittingVisitId === $visit->id)
                    <form wire:submit="admit" class="form my-patients__form">
                        <div class="field">
                            <label for="admit-room">Salle <span class="field__hint">(facultatif)</span></label>
                            <select id="admit-room" wire:model="roomId">
                                <option value="">— Aucune salle —</option>
                                @foreach ($rooms as $room)
                                    <option value="{{ $room->id }}">
                                        {{ $room->name }} — {{ $room->occupancy_count }}/{{ $room->capacity }} lits occupes
                                    </option>
                                @endforeach
                            </select>
                            @error('roomId') <p class="field__error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Une salle pleine n'interdit pas l'admission : une
                             urgence depasse parfois la capacite nominale, et un
                             blocage strict serait dangereux. --}}
                        <div class="field field--check">
                            <label for="admit-over">
                                <input id="admit-over" type="checkbox" wire:model="overCapacityConfirmed">
                                Admettre meme si la salle est pleine
                            </label>
                        </div>

                        <div class="btn-row">
                            <button type="submit" class="btn btn--primary">Confirmer l'admission</button>
                            <button type="button" class="btn btn--ghost" wire:click="cancelAdmission">Annuler</button>
                        </div>
                    </form>
                @endif
            @endforeach
        @endif
    </section>

    <section class="card">
        <h2 class="card__title">Patients hospitalises ({{ $hospitalizations->count() }})</h2>

        @forelse ($hospitalizations as $sejour)
            <article class="episode">
                <header class="episode__head">
                    <span class="episode__service">{{ $sejour->patient->name }}</span>
                    <span class="mono">{{ $sejour->patient->patient_code }}</span>
                    <span class="badge badge--waiting">{{ $sejour->room?->name ?? 'Sans salle' }}</span>
                    <time>Depuis le {{ $sejour->admitted_at->format('d/m/Y') }}</time>
                </header>

                <p class="timeline__meta">
                    Admis par {{ $sejour->admittedByDoctor?->name() }} —
                    {{ $sejour->pending_care_tasks_count }} soin(s) en attente
                </p>

                <div class="btn-row">
                    <button type="button" class="btn btn--secondary"
                            wire:click="startPrescription({{ $sejour->id }})">Prescrire des soins</button>
                    <button type="button" class="btn btn--ghost"
                            wire:click="discharge({{ $sejour->id }})"
                            wire:confirm="Cloturer cette hospitalisation ?">Cloturer l'hospitalisation</button>
                </div>

                @if ($prescribingId === $sejour->id)
                    <form wire:submit="prescribe" class="form my-patients__form">
                        <div class="form form--inline-wrap">
                            <div class="field">
                                <label for="care-type-{{ $sejour->id }}">Type de soin</label>
                                <select id="care-type-{{ $sejour->id }}" wire:model="careTaskTypeId">
                                    <option value="">— Choisir —</option>
                                    @foreach ($careTaskTypes as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                                    @endforeach
                                </select>
                                @error('careTaskTypeId') <p class="field__error">{{ $message }}</p> @enderror
                            </div>

                            <div class="field">
                                <label for="care-start-{{ $sejour->id }}">Premiere administration</label>
                                <input id="care-start-{{ $sejour->id }}" type="datetime-local" wire:model="careStartsAt">
                                @error('careStartsAt') <p class="field__error">{{ $message }}</p> @enderror
                            </div>

                            <div class="field">
                                <label for="care-interval-{{ $sejour->id }}">Toutes les (heures)</label>
                                <input id="care-interval-{{ $sejour->id }}" type="number" min="1" max="168"
                                       wire:model="careIntervalHours">
                                @error('careIntervalHours') <p class="field__error">{{ $message }}</p> @enderror
                            </div>

                            <div class="field">
                                <label for="care-duration-{{ $sejour->id }}">Pendant (jours)</label>
                                <input id="care-duration-{{ $sejour->id }}" type="number" min="1" max="60"
                                       wire:model="careDurationDays">
                                @error('careDurationDays') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="field">
                            <label for="care-assign-{{ $sejour->id }}">
                                Confier a <span class="field__hint">facultatif — sinon ouvert au personnel de garde</span>
                            </label>
                            <select id="care-assign-{{ $sejour->id }}" wire:model="careAssignedToUserId">
                                <option value="">— Personnel de garde —</option>
                                @foreach ($carers as $carer)
                                    <option value="{{ $carer->id }}">{{ $carer->name }}</option>
                                @endforeach
                            </select>
                            @error('careAssignedToUserId') <p class="field__error">{{ $message }}</p> @enderror
                        </div>

                        <div class="field">
                            <label for="care-instructions-{{ $sejour->id }}">
                                Instructions <span class="field__hint">dosage, produit precis…</span>
                            </label>
                            <textarea id="care-instructions-{{ $sejour->id }}" rows="2"
                                      wire:model="careInstructions"></textarea>
                            @error('careInstructions') <p class="field__error">{{ $message }}</p> @enderror
                        </div>

                        <div class="btn-row">
                            <button type="submit" class="btn btn--primary">Programmer</button>
                            <button type="button" class="btn btn--ghost" wire:click="cancelPrescription">Annuler</button>
                        </div>
                    </form>
                @endif
            </article>
        @empty
            <p class="empty">Aucun patient hospitalise dans ce service.</p>
        @endforelse
    </section>
</div>
