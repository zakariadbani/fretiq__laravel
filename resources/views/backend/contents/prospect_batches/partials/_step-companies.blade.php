<div data-kt-stepper-element="content" data-prospect-step="1">
    @if($model->exists)
        <div class="alert alert-light-primary d-flex align-items-center" role="status">
            <i class="bi bi-check-circle-fill text-primary fs-2 me-3"></i>
            <div><strong>{{ $model->total_items }}</strong> entreprise(s) sont prêtes. Vous pouvez choisir la qualité.</div>
        </div>
        @if(data_get($model->source_options, 'import_errors'))
            <div class="alert alert-warning">Certaines lignes incomplètes ont été conservées pour revue ou ignorées en toute sécurité.</div>
        @endif
    @else
        <div class="mb-7">
            <label for="prospect_batch_name" class="form-label">Nom du lot <span class="text-muted">(facultatif)</span></label>
            <input id="prospect_batch_name" name="name" type="text" maxlength="255"
                   class="form-control form-control-solid" value="{{ old('name') }}"
                   placeholder="Ex. Transporteurs France — août">
        </div>
        <div class="row g-6">
            <div class="col-12 col-lg-6">
                <div class="card border h-100">
                    <div class="card-body">
                        <label for="companies_text" class="form-label fw-bold">Copier-coller</label>
                        <p class="text-muted fs-7">Une entreprise par ligne. Vous pouvez ajouter pays, ville et site séparés par une virgule, un point-virgule, une tabulation ou « | ».</p>
                        <textarea id="companies_text" name="companies_text" rows="9"
                                  class="form-control form-control-solid @error('companies_text') is-invalid @enderror"
                                  placeholder="ACME | FR | Paris | acme.fr">{{ old('companies_text') }}</textarea>
                        @error('companies_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card border h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <label for="companies_csv" class="form-label fw-bold">Ou importer un CSV</label>
                        <p class="text-muted fs-7">10 Mo et 10 000 lignes maximum. Colonnes reconnues : entreprise, pays, ville, site.</p>
                        <input id="companies_csv" name="companies_csv" type="file" accept=".csv,.txt,text/csv,text/plain"
                               class="form-control form-control-solid @error('companies_csv') is-invalid @enderror"
                               aria-describedby="companies_csv_help">
                        <div id="companies_csv_help" class="form-text">Le fichier reste local jusqu’à votre validation.</div>
                        @error('companies_csv')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>
        <div class="d-md-none mt-5" data-mobile-company-preview>
            <div class="card bg-light"><div class="card-body py-4">Le nombre d’entreprises sera vérifié avant tout appel fournisseur.</div></div>
        </div>
    @endif
</div>
