@php($schedulerStatus = $schedulerHealth['status'] ?? 'healthy')
@php($schedulerCommands = $schedulerHealth['commands'] ?? [])

@if($schedulerStatus === 'missing' || $schedulerStatus === 'stale')
    <div class="alert alert-{{ $schedulerStatus === 'missing' ? 'danger' : 'warning' }} d-flex align-items-center py-3 mb-6">
        <i class="bi bi-{{ $schedulerStatus === 'missing' ? 'x-circle-fill' : 'exclamation-triangle-fill' }} fs-4 me-3"></i>
        <div>
            <div class="fw-semibold">Le planificateur des campagnes n'est pas complet. Verifiez le cron Laravel.</div>
            <ul class="mb-0 ps-4">
                @foreach($schedulerCommands as $command)
                    @continue(($command['status'] ?? 'healthy') === 'healthy')
                    <li>
                        {{ $command['label'] ?? 'commande planifiee' }} :
                        @if(($command['status'] ?? 'missing') === 'missing')
                            aucune execution reussie signalee.
                        @else
                            derniere reussite le {{ $command['last_success_at']?->format('d/m/Y H:i') ?? 'inconnu' }}.
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
