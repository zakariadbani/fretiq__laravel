<x-default-layout>

@section('title')
    Modèle — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Modèles d\'email', 'route' => 'admin.campaign_templates.index'], ['label' => $model->name]]" />
@endsection

{{--
    CampaignTemplate view — hero + 2-tab UX (contract parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: template_apercu / template_general.
    Aperçu is native (default active on view); Général deep-links to edit page.

    Email preview: the iframe srcdoc uses {{ $model->html_content }} — Blade's single
    escape encodes the HTML into the srcdoc attribute (XSS-safe: quotes become &quot;
    so they can't break out). The browser decodes the attribute once and srcdoc parses
    it as a document, rendering the email. NOTE: do NOT wrap in e() — that double-escapes
    and the email renders as raw source text. Mirrors sender_identities/crud/view.blade.php.
    sandbox="allow-same-origin" (no allow-scripts) blocks any script in the email.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.campaign_templates.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="template_apercu" role="tabpanel">

        {{-- Generic apercu: details table (left) + stat cards + quick actions (right) --}}
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\CampaignTemplateViewConfig::make($model),
        ])

        {{-- Email preview — preserved from original view.blade.php --}}
        <div class="row g-6 g-xl-9 mt-2">
            <div class="col-12">
                <div class="card">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-eye text-success fs-3 me-2"></i>
                            Aperçu du contenu
                        </h3>
                        {{-- Variables hint --}}
                        <div class="card-toolbar">
                            <div class="d-flex gap-2 flex-wrap">
                                <span class="badge badge-light-primary">
                                    <code class="fs-8">@{{contact.name}}</code> — Prénom contact
                                </span>
                                <span class="badge badge-light-primary">
                                    <code class="fs-8">@{{company.name}}</code> — Société
                                </span>
                                <span class="badge badge-light-warning">
                                    <code class="fs-8">@{{unsubscribe_url}}</code> — Lien désabonnement
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body border-top p-0">
                        @if($model->html_content)
                            <iframe
                                srcdoc="{{ $model->html_content }}"
                                class="w-100 border-0 rounded-bottom"
                                style="min-height: 600px;"
                                sandbox="allow-same-origin"
                                title="Aperçu du modèle"></iframe>
                        @else
                            <div class="text-center py-8 text-muted">
                                <i class="bi bi-envelope-x fs-2x mb-3 d-block"></i>
                                Aucun contenu HTML défini pour ce modèle.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        {{-- end email preview --}}

    </div>
    {{-- end Aperçu --}}

    {{--
        Tab 2 (Général) is NOT a native pane here —
        it deep-links to the edit page via the tab nav. No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script>
        // Auto-resize iframe to content height once loaded
        document.addEventListener('DOMContentLoaded', function () {
            const iframe = document.querySelector('iframe[srcdoc]');
            if (iframe) {
                iframe.addEventListener('load', function () {
                    try {
                        const height = iframe.contentDocument.body.scrollHeight;
                        if (height > 0) {
                            iframe.style.height = (height + 40) + 'px';
                        }
                    } catch(e) {}
                });
            }
        });
    </script>
@endpush

</x-default-layout>
