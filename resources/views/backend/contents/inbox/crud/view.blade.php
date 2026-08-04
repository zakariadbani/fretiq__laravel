<x-default-layout>

@section('title')
    {{ $model->subject ?: '(sans objet)' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Boîte de réception', 'route' => 'admin.inbox.index'], ['label' => $model->subject ?: '(sans objet)']]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.inbox.index'])
@endsection

@php
    $recipient = $model->campaignRecipient;
    $campaign = $recipient?->run?->campaign;
    $statusConfig = config('global.data.inbox_statuses.' . $model->status, []);
@endphp

<x-crud.hero
    :model="$model"
    :title="$viewConfig['title']"
    :avatar="$viewConfig['avatar']"
    :badges="$viewConfig['badges']"
    :subtitle="$viewConfig['subtitle']"
    :tiles="$viewConfig['tiles']"
/>

<div class="row g-5 g-xl-8">
    <div class="col-xl-8">
        <div class="card mb-5">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0"><i class="bi bi-envelope-open text-primary fs-3 me-2"></i>Corps de l'email</h3>
            </div>
            <div class="card-body border-top overflow-auto">
                @if($model->body_html)
                    <div class="inbox-email-body text-gray-800">{!! $model->body_html !!}</div>
                @else
                    <pre class="mb-0 text-wrap text-gray-800">{{ $model->body_text ?: '(message vide)' }}</pre>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card mb-5">
            <div class="card-header border-0 pt-5"><h3 class="card-title fw-bolder m-0">Détails</h3></div>
            <div class="card-body border-top">
                @foreach([
                    'De' => trim(($model->from_name ? $model->from_name . ' — ' : '') . $model->from_email),
                    'À' => $model->to_email,
                    'Identité' => $model->senderIdentity?->name,
                    'Reçu le' => $model->received_at?->format('d/m/Y H:i'),
                    'Message-ID' => $model->message_id,
                    'In-Reply-To' => $model->in_reply_to,
                ] as $label => $value)
                    <div class="mb-5">
                        <span class="text-muted fs-7 d-block">{{ $label }}</span>
                        <span class="fw-semibold text-break">{{ $value ?: '—' }}</span>
                    </div>
                @endforeach

                <div class="mb-5">
                    <span class="text-muted fs-7 d-block">Statut</span>
                    <span class="badge badge-light-{{ $statusConfig['color'] ?? 'secondary' }}">{{ $statusConfig['label'] ?? $model->status }}</span>
                </div>

                @if($model->processed_at === null)
                <div class="d-flex flex-wrap gap-2 mb-5">
                    @can('edit inbox')
                        @foreach(config('global.data.inbox_statuses', []) as $value => $config)
                            <button type="button" class="btn btn-sm btn-light-{{ $config['color'] }} inbox-status"
                                    data-status="{{ $value }}" data-url="{{ route('admin.inbox.status', $model->id) }}">
                                {{ $config['label'] }}
                            </button>
                        @endforeach
                    @endcan
                </div>
                @endif

                @can('edit inbox')
                    <div class="border rounded p-4 mb-5" data-inbox-triage-panel>
                        <div class="fw-bold text-gray-800 mb-1">Qualifier la r&eacute;ponse</div>
                        @if($model->processed_at === null)
                            @if($model->contact)
                                <div class="text-muted fs-7 mb-3">Cette action classe le message et met &agrave; jour son contact.</div>
                            @else
                                <div class="text-muted fs-7 mb-3">Classez ce message, ou associez d&#039;abord un contact pour créer une demande.</div>
                            @endif
                        @else
                            <div class="text-muted fs-7 mb-3">Ce message est d&eacute;j&agrave; trait&eacute;. Les actions sont verrouill&eacute;es.</div>
                        @endif
                        @if($model->triage_action)
                            <div class="badge badge-light-info mb-3">Tri actuel : {{ str_replace('_', ' ', $model->triage_action) }}</div>
                        @endif
                        @if($model->processed_at === null)
                        <div class="d-grid gap-2">
                            @can('create demandes')
                                @if($model->contact)
                                    <form method="POST" action="{{ route('admin.inbox.triage', $model->id) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="interested">
                                        <button type="submit" class="btn btn-success w-100" data-inbox-triage="interested">
                                            <i class="bi bi-hand-thumbs-up me-2"></i>Int&eacute;ress&eacute;
                                        </button>
                                    </form>
                                @endif
                            @endcan
                            <form method="POST" action="{{ route('admin.inbox.triage', $model->id) }}">
                                @csrf
                                <input type="hidden" name="action" value="not_interested">
                                <button type="submit" class="btn btn-light-danger w-100" data-inbox-triage="not_interested">
                                    <i class="bi bi-hand-thumbs-down me-2"></i>Pas int&eacute;ress&eacute;
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.inbox.triage', $model->id) }}">
                                @csrf
                                <input type="hidden" name="action" value="automatic">
                                <button type="submit" class="btn btn-light-secondary w-100" data-inbox-triage="automatic">
                                    <i class="bi bi-robot me-2"></i>Message automatique
                                </button>
                            </form>
                        </div>
                        @endif
                    </div>
                @endcan

                <div class="d-grid gap-2">
                    @if($model->contact)
                        <a href="{{ route('admin.contacts.view', $model->contact->id) }}" class="btn btn-light-primary"><i class="bi bi-person me-2"></i>Voir le contact</a>
                    @endif
                    @if($model->contact?->company)
                        <a href="{{ route('admin.companies.view', $model->contact->company->id) }}" class="btn btn-light-primary"><i class="bi bi-building me-2"></i>Voir l'entreprise</a>
                    @endif
                    @if($campaign)
                        <a href="{{ route('admin.campaigns.view', $campaign->id) }}" class="btn btn-light-primary"><i class="bi bi-megaphone me-2"></i>Voir la campagne</a>
                    @endif
                </div>

                @if($campaign && $recipient && $recipient->status !== 'replied')
                    @can('create demandes')
                        <form method="POST" action="{{ route('admin.campaigns.markReplied', [$campaign->id, $recipient->id]) }}" class="mt-5">
                            @csrf
                            <button type="submit" class="btn btn-success w-100"><i class="bi bi-reply-fill me-2"></i>Marquer répondu</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.inbox-status').forEach(function (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            try {
                var response = await fetch(button.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ status: button.dataset.status })
                });
                var data = await response.json();
                if (!response.ok) throw new Error(data.message || 'Échec');
                window.location.reload();
            } catch (error) {
                button.disabled = false;
                await Swal.fire({ text: 'Impossible de mettre à jour le statut.', icon: 'error', confirmButtonText: 'Fermer' });
            }
        });
    });
}());
</script>
@endpush

</x-default-layout>
