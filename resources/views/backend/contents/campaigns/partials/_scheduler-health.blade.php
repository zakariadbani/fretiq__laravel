@php($schedulerStatus = $schedulerHealth['status'] ?? 'missing')
@php($globalScheduler = $schedulerHealth['global'] ?? ['status' => 'missing', 'last_tick_at' => null])
@php($commandsStatus = $schedulerHealth['commands_status'] ?? 'missing')
@php($schedulerCommands = $schedulerHealth['commands'] ?? [])
@php($schedulerColor = $schedulerStatus === 'healthy' ? 'success' : ($schedulerStatus === 'missing' ? 'danger' : 'warning'))

<div class="alert alert-{{ $schedulerColor }} d-flex align-items-start py-4 mb-6"
     data-global-scheduler-status="{{ $globalScheduler['status'] }}"
     data-campaign-commands-status="{{ $commandsStatus }}">
    <i class="bi bi-{{ $schedulerStatus === 'healthy' ? 'check-circle-fill' : 'exclamation-triangle-fill' }} fs-4 me-3 mt-1"></i>
    <div>
        <div class="fw-semibold">
            {{ $schedulerStatus === 'healthy' ? 'Automatisation opérationnelle' : 'Automatisation à vérifier' }}
        </div>

        <div class="fs-7 mt-1">
            @if(($globalScheduler['status'] ?? 'missing') === 'healthy')
                Laravel exécute bien le planificateur — dernier passage le
                {{ $globalScheduler['last_tick_at']?->copy()->setTimezone('Europe/Paris')->format('d/m/Y H:i') }} (Europe/Paris).
            @elseif(($globalScheduler['status'] ?? 'missing') === 'stale')
                Le planificateur Laravel n’a pas signalé de passage depuis plus de 2 minutes.
            @else
                Aucun passage du planificateur Laravel n’a encore été signalé.
            @endif
        </div>

        <ul class="mb-0 mt-2 ps-4 fs-7">
            @foreach($schedulerCommands as $command)
                <li>
                    <span class="fw-semibold">{{ $command['label'] }}</span> :
                    @if(($command['status'] ?? 'missing') === 'healthy')
                        dernière réussite le {{ $command['last_success_at']?->copy()->setTimezone('Europe/Paris')->format('d/m/Y H:i') }}.
                    @elseif(($command['status'] ?? 'missing') === 'stale')
                        dernière réussite le {{ $command['last_success_at']?->copy()->setTimezone('Europe/Paris')->format('d/m/Y H:i') ?? 'inconnue' }}, soit depuis plus de 2 minutes alors qu’elle est attendue chaque minute.
                    @else
                        aucune exécution réussie signalée.
                    @endif
                </li>
            @endforeach
        </ul>

        @if(isset($model) && $model->effectiveScheduledAt())
            <div class="fs-7 mt-2">
                <span class="fw-semibold">Prochain envoi prévu :</span>
                {{ $model->effectiveScheduledAt()->copy()->setTimezone('Europe/Paris')->format('d/m/Y H:i') }} Europe/Paris.
            </div>
        @endif
    </div>
</div>
