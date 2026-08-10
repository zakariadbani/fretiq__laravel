<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? "Modifier l'identité — " . e($model->name) : "Ajouter une identité d'expéditeur" }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Identités d\'expéditeur', 'route' => 'admin.sender_identities.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Ajouter']]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.sender_identities.index'])
@endsection

{{--
    SenderIdentity create/edit form — hero + tabbar + sticky contract.

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with tab strip.
        - Général (active) is the only native form pane INSIDE <form>.
        - Aperçu tab deep-links to view page.

    Create mode:
        - Simple header card + minimal nav (Général only).

    No select2 used — all inputs are plain text/checkbox. No hidden panes.
    Both form-actions calls preserved: toolbar variant above + sticky variant at bottom.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.sender_identities.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général only) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-person-badge text-primary fs-3 me-2"></i>
                    Ajouter une identité d'expéditeur
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#sender_general">
                    <i class="bi bi-person-badge me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    <div class="tab-content" id="sender_tab_content">

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        <div class="tab-pane fade show active" id="sender_general" role="tabpanel">

            {{-- Identity information card --}}
            <div class="card mb-5">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-person-badge text-primary fs-3 me-2"></i>
                        Informations de l'identité
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Nom --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom d'affichage</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : TCL France Prospection"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Email --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Adresse email</label>
                                <input type="email"
                                       name="email"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : contact@tcl-france.fr"
                                       value="{{ old('email', $model->email ?? '') }}"
                                       required />
                            </div>

                            {{-- Reply-To --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Répondre à <span class="text-muted fs-7">(optionnel)</span></label>
                                <input type="email"
                                       name="reply_to"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : reponses@tcl-france.fr"
                                       value="{{ old('reply_to', $model->reply_to ?? '') }}" />
                                <div class="form-text text-muted mt-1">
                                    Si vide, les réponses iront vers l'adresse email principale.
                                </div>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Par défaut --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-3">Par défaut</label>
                                <div class="form-check form-switch form-check-custom form-check-solid">
                                    <input type="hidden" name="is_default" value="0" />
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="is_default"
                                           id="is_default"
                                           value="1"
                                           {{ old('is_default', $model->is_default ?? false) ? 'checked' : '' }} />
                                    <label class="form-check-label fw-semibold text-gray-700 ms-3" for="is_default">
                                        Identité par défaut
                                    </label>
                                </div>
                                <div class="form-text text-muted mt-1">
                                    Une seule identité peut être marquée par défaut — la précédente sera automatiquement décochée.
                                </div>
                            </div>

                            {{-- Actif --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-3">Statut</label>
                                <div class="form-check form-switch form-check-custom form-check-solid">
                                    <input type="hidden" name="is_active" value="0" />
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="is_active"
                                           id="is_active"
                                           value="1"
                                           {{ old('is_active', $model->is_active ?? true) ? 'checked' : '' }} />
                                    <label class="form-check-label fw-semibold text-gray-700 ms-3" for="is_active">
                                        Identité active
                                    </label>
                                </div>
                            </div>

                        </div>
                    </div>

                    {{-- Signature HTML — Quill WYSIWYG with raw-HTML toggle --}}
                    <div class="row">
                        <div class="col-12">
                            <div class="fv-row mb-7"
                                 data-quill-html-field
                                 data-quill-placeholder="Cordialement, …">

                                {{-- Label row: label left, toggle button right --}}
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="fw-semibold fs-6 mb-0">Signature HTML <span class="text-muted fs-7">(optionnel)</span></label>
                                    <button type="button"
                                            class="btn btn-sm btn-light"
                                            data-quill-toggle
                                            title="Basculer HTML brut">
                                        <i class="bi bi-code-slash"></i> HTML
                                    </button>
                                </div>

                                {{-- Quill editor wrapper (toolbar + editor mount hidden/shown together) --}}
                                <div data-quill-editor-wrap>
                                    <div data-quill-editor class="min-h-150px"></div>
                                </div>

                                {{-- Original textarea — source of truth for FormData; hidden when in visual mode --}}
                                <textarea name="signature_html"
                                          class="form-control form-control-solid font-monospace d-none"
                                          rows="8"
                                          placeholder="<p>Cordialement,<br><strong>Votre nom</strong><br>TCL France</p>">{{ old('signature_html', $model->signature_html ?? '') }}</textarea>

                                <div class="form-text text-muted mt-1">
                                    Sera inséré en bas de chaque email.
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
        {{-- end Général --}}

        @if(isset($model) && $model->id)
        <div class="tab-pane fade" id="sender_imap" role="tabpanel">
            <div class="card mb-5">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-inbox-fill text-primary fs-3 me-2"></i>
                        Relève IMAP
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row g-7">
                        <div class="col-lg-8">
                            <div class="row g-5">
                                <div class="col-md-8">
                                    <label class="fw-semibold fs-6 mb-2">Hôte IMAP</label>
                                    <input type="text" name="imap_host" class="form-control form-control-solid"
                                           placeholder="imap.example.com"
                                           value="{{ old('imap_host', $model->imap_host ?? '') }}" />
                                </div>
                                <div class="col-md-4 fv-row">
                                    <label class="fw-semibold fs-6 mb-2">Port</label>
                                    <input type="number" min="1" max="65535" name="imap_port"
                                           class="form-control form-control-solid"
                                           value="{{ old('imap_port', $model->imap_port ?? 993) }}" />
                                </div>
                                <div class="col-md-6">
                                    <label class="fw-semibold fs-6 mb-2">Utilisateur</label>
                                    <input type="text" name="imap_username" autocomplete="username"
                                           class="form-control form-control-solid"
                                           value="{{ old('imap_username', $model->imap_username ?? '') }}" />
                                </div>
                                <div class="col-md-6">
                                    <label class="fw-semibold fs-6 mb-2">Mot de passe</label>
                                    <div class="input-group">
                                        <input type="password" id="imap_password" name="imap_password"
                                               autocomplete="new-password" class="form-control form-control-solid"
                                               value="" placeholder="Laisser vide pour conserver le mot de passe" />
                                        <button class="btn btn-light" type="button" id="toggle_imap_password" aria-label="Afficher le mot de passe">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="fw-semibold fs-6 mb-2">Chiffrement</label>
                                    <select name="imap_encryption" class="form-select form-select-solid">
                                        @foreach(['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'Aucun'] as $value => $label)
                                            <option value="{{ $value }}" @selected(old('imap_encryption', $model->imap_encryption ?? 'ssl') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex flex-column justify-content-end gap-4">
                                    <div class="form-check form-switch form-check-custom form-check-solid">
                                        <input type="hidden" name="imap_validate_cert" value="0" />
                                        <input class="form-check-input" type="checkbox" name="imap_validate_cert" id="imap_validate_cert" value="1"
                                               {{ old('imap_validate_cert', $model->imap_validate_cert ?? true) ? 'checked' : '' }} />
                                        <label class="form-check-label fw-semibold ms-3" for="imap_validate_cert">Valider le certificat</label>
                                    </div>
                                    <div class="form-check form-switch form-check-custom form-check-solid">
                                        <input type="hidden" name="imap_enabled" value="0" />
                                        <input class="form-check-input" type="checkbox" name="imap_enabled" id="imap_enabled" value="1"
                                               {{ old('imap_enabled', $model->imap_enabled ?? false) ? 'checked' : '' }} />
                                        <label class="form-check-label fw-semibold ms-3" for="imap_enabled">Activer la relève IMAP</label>
                                    </div>
                                </div>
                            </div>

                            <button type="button" id="test_imap_connection" class="btn btn-light-primary mt-7"
                                    data-url="{{ route('admin.sender_identities.testImap', $model->id) }}"
                                    data-kt-indicator="off">
                                <span class="indicator-label"><i class="bi bi-plug me-2"></i>Tester la connexion</span>
                                <span class="indicator-progress">Test en cours… <span class="spinner-border spinner-border-sm ms-2"></span></span>
                            </button>
                        </div>

                        <div class="col-lg-4">
                            <div class="border rounded p-6 bg-light h-100">
                                <h4 class="fs-6 fw-bold mb-5">État de la relève</h4>
                                <div class="mb-4">
                                    <span class="text-muted d-block fs-7">Dernière relève réussie</span>
                                    <span class="fw-semibold">{{ $model->last_polled_at?->format('d/m/Y H:i') ?? 'Jamais' }}</span>
                                </div>
                                <div class="mb-4">
                                    <span class="text-muted d-block fs-7">Échecs consécutifs</span>
                                    <span class="fw-semibold">{{ (int) ($model->consecutive_poll_failures ?? 0) }}</span>
                                </div>
                                @if($model->last_poll_error)
                                    <div class="alert alert-danger py-3 px-4 mb-0 text-break">{{ $model->last_poll_error }}</div>
                                @else
                                    <div class="text-muted fs-7">Aucune erreur enregistrée.</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="sender_smtp" role="tabpanel">
            <div class="card mb-5">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-send-fill text-primary fs-3 me-2"></i>
                        Envoi SMTP direct
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    @if((string) config('app.env') !== 'production' || config('prospecting.smtp.mode', 'mailpit') !== 'sender_identity')
                        <div class="alert alert-info d-flex align-items-center mb-7">
                            <i class="bi bi-inbox fs-2 text-info me-3"></i>
                            <strong>Mode local — email capturé par Mailpit.</strong>
                        </div>
                    @endif

                    <div class="form-check form-switch form-check-custom form-check-solid mb-7">
                        <input type="hidden" name="smtp_enabled" value="0">
                        <input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtp_enabled" value="1"
                               {{ old('smtp_enabled', $model->smtp_enabled ?? false) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold ms-3" for="smtp_enabled">Activer SMTP pour cette identité</label>
                    </div>

                    <div class="row g-5">
                        <div class="col-md-8 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Hôte SMTP</label>
                            <input type="text" name="smtp_host" class="form-control form-control-solid"
                                   placeholder="smtp.example.com" value="{{ old('smtp_host', $model->smtp_host ?? '') }}">
                        </div>
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Port</label>
                            <input type="number" min="1" max="65535" name="smtp_port" class="form-control form-control-solid"
                                   value="{{ old('smtp_port', $model->smtp_port ?? 587) }}">
                        </div>
                        <div class="col-md-6 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Utilisateur</label>
                            <input type="text" name="smtp_username" autocomplete="username" class="form-control form-control-solid"
                                   value="{{ old('smtp_username', $model->smtp_username ?? '') }}">
                        </div>
                        <div class="col-md-6 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Mot de passe</label>
                            <div class="input-group">
                                <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password"
                                       class="form-control form-control-solid" value="" placeholder="Laisser vide pour conserver le mot de passe">
                                <button class="btn btn-light" type="button" id="toggle_smtp_password" aria-label="Afficher le mot de passe">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Chiffrement</label>
                            <select name="smtp_encryption" class="form-select form-select-solid">
                                @foreach(['tls' => 'TLS (STARTTLS obligatoire)', 'ssl' => 'SSL/TLS implicite'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('smtp_encryption', $model->smtp_encryption ?? 'tls') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Plafond par heure</label>
                            <input type="number" min="1" max="100" name="smtp_hourly_limit" class="form-control form-control-solid"
                                   value="{{ old('smtp_hourly_limit', $model->smtp_hourly_limit ?? 10) }}">
                        </div>
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Plafond par jour</label>
                            <input type="number" min="1" max="500" name="smtp_daily_limit" class="form-control form-control-solid"
                                   value="{{ old('smtp_daily_limit', $model->smtp_daily_limit ?? 50) }}">
                        </div>
                    </div>

                    <div class="border-top mt-8 pt-7">
                        <label class="fw-semibold fs-6 mb-2" for="smtp_test_recipient">Destinataire du test</label>
                        <div class="input-group">
                            <input type="email" id="smtp_test_recipient" class="form-control form-control-solid"
                                   placeholder="vous@example.com" autocomplete="off">
                            <button type="button" id="test_smtp_connection" class="btn btn-light-primary"
                                    data-url="{{ route('admin.sender_identities.testSmtp', $model->id) }}" data-kt-indicator="off">
                                <span class="indicator-label"><i class="bi bi-send me-2"></i>Envoyer le test</span>
                                <span class="indicator-progress">Envoi… <span class="spinner-border spinner-border-sm ms-2"></span></span>
                            </button>
                        </div>
                        <div class="form-text">Un succès confirme l’acceptation par SMTP, pas le placement en boîte principale.</div>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.sender_identities.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/quill-html-field.js') }}"></script>
    <script>
        (function () {
            var password = document.getElementById('imap_password');
            var toggle = document.getElementById('toggle_imap_password');
            if (password && toggle) {
                toggle.addEventListener('click', function () {
                    password.type = password.type === 'password' ? 'text' : 'password';
                    toggle.querySelector('i').className = password.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
                });
            }

            var button = document.getElementById('test_imap_connection');
            if (!button) return;
            button.addEventListener('click', async function () {
                button.setAttribute('data-kt-indicator', 'on');
                button.disabled = true;
                try {
                    var payload = {
                        imap_host: document.querySelector('[name="imap_host"]').value,
                        imap_port: document.querySelector('[name="imap_port"]').value,
                        imap_username: document.querySelector('[name="imap_username"]').value,
                        imap_password: password.value,
                        imap_encryption: document.querySelector('[name="imap_encryption"]').value,
                        imap_validate_cert: document.getElementById('imap_validate_cert').checked ? 1 : 0
                    };
                    var response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify(payload)
                    });
                    var data = await response.json();
                    await Swal.fire({
                        text: data.message || (response.ok ? 'Connexion réussie.' : 'Connexion impossible.'),
                        icon: response.ok ? 'success' : 'error',
                        buttonsStyling: false,
                        confirmButtonText: 'Fermer',
                        customClass: { confirmButton: 'btn btn-primary' }
                    });
                } catch (error) {
                    await Swal.fire({ text: 'Connexion IMAP impossible.', icon: 'error', confirmButtonText: 'Fermer' });
                } finally {
                    button.setAttribute('data-kt-indicator', 'off');
                    button.disabled = false;
                }
            });
        }());

        (function () {
            var password = document.getElementById('smtp_password');
            var toggle = document.getElementById('toggle_smtp_password');
            if (password && toggle) {
                toggle.addEventListener('click', function () {
                    password.type = password.type === 'password' ? 'text' : 'password';
                    toggle.querySelector('i').className = password.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
                });
            }

            var button = document.getElementById('test_smtp_connection');
            if (!button) return;
            button.addEventListener('click', async function () {
                button.setAttribute('data-kt-indicator', 'on');
                button.disabled = true;
                try {
                    var payload = {
                        receiver_email: document.getElementById('smtp_test_recipient').value,
                        smtp_enabled: document.getElementById('smtp_enabled').checked ? 1 : 0,
                        smtp_host: document.querySelector('[name="smtp_host"]').value,
                        smtp_port: document.querySelector('[name="smtp_port"]').value,
                        smtp_username: document.querySelector('[name="smtp_username"]').value,
                        smtp_password: password.value,
                        smtp_encryption: document.querySelector('[name="smtp_encryption"]').value,
                        smtp_hourly_limit: document.querySelector('[name="smtp_hourly_limit"]').value,
                        smtp_daily_limit: document.querySelector('[name="smtp_daily_limit"]').value
                    };
                    var response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify(payload)
                    });
                    var data = await response.json();
                    await Swal.fire({
                        text: data.message || (response.ok ? 'Message accepté.' : 'Connexion impossible.'),
                        icon: response.ok ? 'success' : 'error',
                        buttonsStyling: false,
                        confirmButtonText: 'Fermer',
                        customClass: { confirmButton: 'btn btn-primary' }
                    });
                } catch (error) {
                    await Swal.fire({ text: 'Connexion SMTP impossible.', icon: 'error', confirmButtonText: 'Fermer' });
                } finally {
                    button.setAttribute('data-kt-indicator', 'off');
                    button.disabled = false;
                }
            });
        }());
    </script>
@endpush

</x-default-layout>
