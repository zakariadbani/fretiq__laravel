{{--
    _score-recap.blade.php — AI score recap card/button partial.

    Props:
        $model     (Company)  — the company model (must have ai_score, ai_explanation)
        $showCard  (bool)     — true = full Metronic card; false = button + script only (for form pane)
--}}
@php $showCard = $showCard ?? true; @endphp

@if($showCard)
    <div class="card mb-5 mb-xl-10">
        <div class="card-header border-0 pt-5">
            <h3 class="card-title align-items-start flex-column">
                <span class="card-label fw-bold fs-3 mb-1">Score IA &amp; justification</span>
            </h3>
        </div>
        <div class="card-body border-top p-9">

            @if($model->ai_score === null)
                <span class="text-muted">Aucun score IA à expliquer.</span>
            @else
                {{-- Score badge --}}
                <div class="mb-5">
                    <x-crud.score :value="$model->ai_score" />
                </div>

                {{-- Explanation text --}}
                <div class="mb-5">
                    @if(!empty($model->ai_explanation))
                        <p class="text-gray-700 fs-6">{{ $model->ai_explanation }}</p>
                    @else
                        <p class="text-muted fs-6 fst-italic">Pas encore de justification générée.</p>
                    @endif
                </div>

                {{-- Action button --}}
                @can('edit companies')
                    <button type="button"
                            class="btn btn-sm btn-light-primary"
                            id="explain_score_btn"
                            onclick="explainCompanyScore({{ (int) $model->id }}, '{{ csrf_token() }}')">
                        <i class="bi bi-stars me-1"></i>Générer / Régénérer le récapitulatif
                    </button>
                @endcan
            @endif

        </div>
    </div>
@else
    {{-- showCard=false: button only (used inside an existing form card pane) --}}
    @if($model->ai_score !== null)
        @can('edit companies')
            <div class="mb-5">
                <button type="button"
                        class="btn btn-sm btn-light-primary"
                        id="explain_score_btn"
                        onclick="explainCompanyScore({{ (int) $model->id }}, '{{ csrf_token() }}')">
                    <i class="bi bi-stars me-1"></i>Générer / Régénérer le récapitulatif
                </button>
            </div>
        @endcan
    @endif
@endif

@push('scripts')
    <script>
        /**
         * explainCompanyScore — generate/refresh the AI score recap for a company.
         * No confirmation dialog. Fetch POST → toastr → reload on success.
         * Mirrors enrichCompany (contacts-tab) but without the Swal confirm.
         */
        window.explainCompanyScore = function (id, csrfToken) {
            var btn = document.getElementById('explain_score_btn');
            if (btn) btn.disabled = true;

            fetch('/admin/companies/' + id + '/explain-score', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            })
            .then(function (response) {
                var httpStatus = response.status;
                return response.text().then(function (text) {
                    var data = {};
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        // Non-JSON response (e.g. 419 session expired)
                        if (btn) btn.disabled = false;
                        toastr.error('Une erreur est survenue — réessayez.', 'Erreur');
                        return;
                    }

                    if (httpStatus === 200) {
                        toastr.success(data.text || 'Récapitulatif généré.', 'Succès');
                        setTimeout(function () { window.location.reload(); }, 800);
                    } else if (httpStatus === 422) {
                        toastr.error(data.text || 'Aucun score IA à expliquer.', 'Erreur');
                        if (btn) btn.disabled = false;
                    } else {
                        toastr.error(data.text || 'Une erreur est survenue.', 'Erreur');
                        if (btn) btn.disabled = false;
                    }
                });
            })
            .catch(function () {
                if (btn) btn.disabled = false;
                toastr.error('Une erreur est survenue — réessayez.', 'Erreur');
            });
        };
    </script>
@endpush
