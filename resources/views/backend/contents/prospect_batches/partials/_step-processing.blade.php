<div data-kt-stepper-element="content" data-prospect-step="4">
    <div class="card border">
        <div class="card-body text-center py-10">
            <div class="spinner-border text-primary mb-5" data-processing-spinner role="status"><span class="visually-hidden">Traitement en cours</span></div>
            <i class="bi bi-check-circle-fill text-success fs-3x d-none mb-5" data-processing-success></i>
            <h2 class="fs-4 fw-bold" data-processing-title>Traitement en cours</h2>
            <p class="text-muted" data-processing-message>Vous pouvez quitter cette page; le lot continuera en arrière-plan.</p>
            <div class="progress h-8px mw-500px mx-auto my-6"><div class="progress-bar bg-primary" data-processing-bar style="width: 0%"></div></div>
            <div class="fw-semibold" aria-live="polite" data-processing-progress>0 %</div>
            <div class="alert alert-warning d-none mt-6 text-start" data-worker-waiting>
                Le lot attend un worker. Vérifiez que la file « prospecting » est démarrée.
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-3 mt-7">
                <a href="{{ $model->exists ? route('admin.prospect_batches.view', $model) : '#' }}" class="btn btn-light-primary" data-batch-view>Voir le lot</a>
                @if(Route::has('admin.prospect_review.index'))
                    <a href="{{ route('admin.prospect_review.index') }}" class="btn btn-warning d-none" data-review-link>À revoir</a>
                @endif
            </div>
        </div>
    </div>
</div>
