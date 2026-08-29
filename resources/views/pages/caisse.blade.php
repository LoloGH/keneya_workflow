@php
    use App\Models\Service;

    // Les deux caisses sont deux sections d'une meme interface : un seul role
    // les voit toutes les deux.
    $ticket = Service::caisseTicket();
    $services = Service::caisseServices();

    $sections = array_values(array_filter([
        $ticket ? ['key' => 'caisse-ticket', 'label' => 'Caisse Ticket', 'view' => 'sections.caisse.ticket'] : null,
        $services ? ['key' => 'caisse-services', 'label' => 'Caisse Services', 'view' => 'sections.caisse.services'] : null,
        ['key' => 'planning', 'label' => 'Mon planning', 'view' => 'sections.caisse.schedule'],
    ]));
@endphp

<x-layouts.app :title="'Caisse — '.config('keneya.name')">
    @if (session('caisse.status'))
        <div class="alert alert--success" role="status">{{ session('caisse.status') }}</div>
    @endif
    @if (session('caisse.error'))
        <div class="alert alert--error" role="alert">{{ session('caisse.error') }}</div>
    @endif

    @if (! $ticket && ! $services)
        <div class="alert alert--error" role="alert">
            Aucune caisse n'est configuree. Demandez a l'administrateur de creer
            les services « {{ Service::CAISSE_TICKET }} » et « {{ Service::CAISSE_SERVICES }} ».
        </div>
    @else
        @livewire('shared.vertical-tab-nav', [
            'sections' => $sections,
            'context' => [
                'caisseTicketId' => $ticket?->getKey(),
                'caisseServicesId' => $services?->getKey(),
            ],
        ], key('nav-caisse'))
    @endif
</x-layouts.app>
