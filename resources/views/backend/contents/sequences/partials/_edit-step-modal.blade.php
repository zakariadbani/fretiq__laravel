@can('edit sequences')
<div class="modal fade"
     id="sequence_step_edit_modal"
     tabindex="-1"
     aria-labelledby="sequence_step_edit_modal_title"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="sequence_step_edit_form" method="POST" action="">
                @csrf
                @method('PUT')

                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="sequence_step_edit_modal_title">Modifier l’étape</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body">
                    <div id="sequence_step_edit_error"
                         class="alert alert-danger d-none"
                         role="alert"
                         aria-live="assertive"></div>

                    <div class="mb-5">
                        <label for="sequence_step_edit_delay_days" class="required form-label">Délai (jours)</label>
                        <input type="number"
                               id="sequence_step_edit_delay_days"
                               name="delay_days"
                               class="form-control form-control-solid"
                               aria-describedby="sequence_step_edit_delay_help sequence_step_edit_delay_error"
                               min="0"
                               required />
                        <div id="sequence_step_edit_delay_error" class="invalid-feedback" data-error-for="delay_days"></div>
                        <div id="sequence_step_edit_delay_help" class="form-text">0 = envoi immédiat après l’étape précédente.</div>
                    </div>

                    <div class="mb-5">
                        <label for="sequence_step_edit_template_id" class="required form-label">Modèle d’email</label>
                        <select id="sequence_step_edit_template_id"
                                name="template_id"
                                class="form-select form-select-solid"
                                aria-describedby="sequence_step_edit_template_error"
                                required>
                            <option value="">Sélectionner un modèle…</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                        <div id="sequence_step_edit_template_error" class="invalid-feedback" data-error-for="template_id"></div>
                    </div>

                    <div>
                        <label for="sequence_step_edit_subject" class="form-label">
                            Sujet <span class="text-muted">(optionnel)</span>
                        </label>
                        <input type="text"
                               id="sequence_step_edit_subject"
                               name="subject"
                               class="form-control form-control-solid"
                               aria-describedby="sequence_step_edit_subject_error"
                               maxlength="255"
                               placeholder="Laissez vide pour utiliser le sujet du modèle" />
                        <div id="sequence_step_edit_subject_error" class="invalid-feedback" data-error-for="subject"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" id="sequence_step_edit_submit">
                        <span data-submit-label>Enregistrer</span>
                        <span data-submit-loading class="d-none">
                            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                            Enregistrement…
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('sequence_step_edit_modal');
    const form = document.getElementById('sequence_step_edit_form');
    const submitButton = document.getElementById('sequence_step_edit_submit');
    const generalError = document.getElementById('sequence_step_edit_error');

    if (!modalElement || !form || !submitButton || !generalError) {
        return;
    }

    const fields = {
        delay_days: document.getElementById('sequence_step_edit_delay_days'),
        template_id: document.getElementById('sequence_step_edit_template_id'),
        subject: document.getElementById('sequence_step_edit_subject'),
    };
    let submitting = false;

    const clearErrors = function () {
        generalError.textContent = '';
        generalError.classList.add('d-none');

        Object.entries(fields).forEach(function ([name, field]) {
            field.classList.remove('is-invalid');
            const feedback = form.querySelector('[data-error-for="' + name + '"]');
            if (feedback) {
                feedback.textContent = '';
            }
        });
    };

    const setSubmitting = function (state) {
        submitting = state;
        submitButton.disabled = state;
        submitButton.querySelector('[data-submit-label]').classList.toggle('d-none', state);
        submitButton.querySelector('[data-submit-loading]').classList.toggle('d-none', !state);
    };

    document.querySelectorAll('[data-sequence-step-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            clearErrors();
            setSubmitting(false);
            form.action = button.dataset.updateUrl;
            fields.delay_days.value = button.dataset.delayDays || '0';
            fields.template_id.value = button.dataset.templateId || '';
            fields.subject.value = button.dataset.subject || '';
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        form.reset();
        form.removeAttribute('action');
        clearErrors();
        if (!submitting) {
            setSubmitting(false);
        }
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (submitting || !form.action) {
            return;
        }

        clearErrors();
        setSubmitting(true);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(function () { return {}; });

            if (response.status === 422) {
                let firstInvalidField = null;
                Object.entries(payload.errors || {}).forEach(function ([name, messages]) {
                    const field = fields[name];
                    const feedback = form.querySelector('[data-error-for="' + name + '"]');
                    if (field && feedback) {
                        field.classList.add('is-invalid');
                        feedback.textContent = Array.isArray(messages) ? messages[0] : messages;
                        firstInvalidField = firstInvalidField || field;
                    }
                });
                generalError.textContent = 'Veuillez corriger les champs indiqués.';
                generalError.classList.remove('d-none');
                if (firstInvalidField) {
                    firstInvalidField.focus();
                }
                return;
            }

            if (!response.ok) {
                throw new Error(payload.message || 'La modification de l’étape a échoué.');
            }

            window.history.replaceState(
                null,
                '',
                window.location.pathname + window.location.search + '#sequence_steps'
            );
            window.location.reload();
        } catch (error) {
            generalError.textContent = error.message || 'La modification de l’étape a échoué.';
            generalError.classList.remove('d-none');
        } finally {
            setSubmitting(false);
        }
    });
});
</script>
@endpush
@endcan
