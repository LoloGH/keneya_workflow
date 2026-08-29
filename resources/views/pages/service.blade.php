@php
    // Service courant du medecin connecte : son premier rattachement par defaut.
    $assignments = auth()->user()->doctors()->with('service')->orderBy('id')->get();
    $assignment = $assignments->first();
    $serviceId = $assignment?->service_id;

    // Arborescence propre a l'espace service. « Renvois » regroupe les deux
    // panneaux qui vont par paire — ce qu'on recoit et ce qu'on a envoye.
    $sections = [
        ['key' => 'file', 'label' => "File d'attente", 'view' => 'sections.service.queue'],
        ['key' => 'renvois', 'label' => 'Renvois', 'children' => [
            ['key' => 'renvois-entrants', 'label' => 'Renvois en attente', 'view' => 'sections.service.incoming'],
            ['key' => 'renvois-sortants', 'label' => 'Mes renvois', 'view' => 'sections.service.outgoing'],
        ]],
        ['key' => 'consultation', 'label' => 'Fin de consultation', 'view' => 'sections.service.consultation'],
        ['key' => 'hospitalisation', 'label' => 'Patients hospitalises', 'view' => 'sections.service.hospitalizations'],
        ['key' => 'mes-patients', 'label' => 'Mes patients', 'view' => 'sections.service.my-patients'],
        ['key' => 'mes-rendez-vous', 'label' => 'Mes rendez-vous', 'view' => 'sections.service.my-appointments'],
        ['key' => 'planning', 'label' => 'Mon planning', 'view' => 'sections.service.schedule'],
    ];
@endphp

<x-layouts.app :title="'Service — '.config('keneya.name')">
    {{-- A droite de la barre : le service du medecin plutot que son role. --}}
    <x-slot:context>{{ $assignment?->service?->name ?? \App\Support\Roles::label(auth()->user()->scopedRole()) }}</x-slot:context>

    @if (! $serviceId)
        <div class="alert alert--error" role="alert">
            Votre compte n'est rattache a aucun service. Contactez l'administrateur.
        </div>
    @else
        @if (session('service.status'))
            <div class="alert alert--success" role="status">{{ session('service.status') }}</div>
        @endif
        @if (session('service.error'))
            <div class="alert alert--error" role="alert">{{ session('service.error') }}</div>
        @endif

        {{-- Le selecteur reste hors des onglets : il change le contexte de
             toutes les sections a la fois. Il n'apparait que pour un medecin
             rattache a plusieurs services — pour les autres, la barre affiche
             deja le nom du service. --}}
        @if ($assignments->count() > 1)
            <div class="service-head">
                @livewire('service.service-selector', ['serviceId' => $serviceId], key('service-selector'))
            </div>
        @endif

        <div class="grid grid--main">
            @livewire('shared.vertical-tab-nav', [
                'sections' => $sections,
                'context' => ['serviceId' => $serviceId],
            ], key('nav-service-'.$serviceId))

            {{-- Le dossier patient reste visible quelle que soit la section :
                 c'est le panneau qu'on consulte pendant qu'on travaille. --}}
            <div class="record-column">
                @livewire('service.patient-record-panel', [], key('service-record-panel'))
            </div>
        </div>
    @endif
</x-layouts.app>
