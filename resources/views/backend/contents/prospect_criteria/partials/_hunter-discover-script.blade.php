<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('hunter-discover-form');
    if (!form || form.dataset.hunterDiscoverBound === '1') return;
    form.dataset.hunterDiscoverBound = '1';

    const previewButton = form.querySelector('[data-hunter-preview]');
    const continueButton = document.querySelector('[data-hunter-import]');
    const results = document.querySelector('[data-hunter-results]');
    const prompt = document.querySelector('[data-hunter-prompt]');
    const callCount = document.querySelector('[data-hunter-call-count]');
    const creditCount = document.querySelector('[data-hunter-credit-count]');
    const error = form.querySelector('[data-hunter-error]');
    let preparedDraft = null;

    const message = value => { error.textContent = value || ''; };
    const csrf = () => form.querySelector('[name=_token]').value;
    const normalizedPayload = () => {
        const data = new FormData(form);

        return {
            target: String(data.get('target') || '').trim(),
            exclude: String(data.get('exclude') || '').trim(),
            quality_preset: 'balanced',
        };
    };

    form.querySelectorAll('textarea').forEach(field => field.addEventListener('input', () => {
        preparedDraft = null;
        continueButton.disabled = true;
        results.classList.add('d-none');
        message('');
    }));

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (previewButton.disabled) return;

        previewButton.disabled = true;
        previewButton.setAttribute('data-kt-indicator', 'on');
        continueButton.disabled = true;
        preparedDraft = null;
        message('');
        results.classList.add('d-none');

        try {
            const response = await fetch(form.dataset.previewUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()},
                body: new FormData(form),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'La vérification a échoué.');

            preparedDraft = data.draft;
            prompt.textContent = data.prompt || '';
            callCount.textContent = String(data.estimate?.calls?.hunter_discover ?? 0);
            creditCount.textContent = String(data.estimate?.reserved_units?.hunter ?? 0);
            results.classList.remove('d-none');
            continueButton.disabled = false;
        } catch (exception) {
            message(exception.message);
        } finally {
            previewButton.disabled = false;
            previewButton.removeAttribute('data-kt-indicator');
        }
    });

    continueButton.addEventListener('click', async () => {
        if (!preparedDraft || continueButton.disabled) return;

        continueButton.disabled = true;
        message('');

        try {
            const response = await fetch(form.dataset.importUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({...normalizedPayload(), ...preparedDraft}),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Le lot n’a pas pu être créé.');

            if (window.toastr) toastr.success('Lot créé. Vérifiez le coût avant de lancer le traitement.');
            window.location.assign(data.redirect_url);
        } catch (exception) {
            message(exception.message);
            continueButton.disabled = false;
        }
    });
});
</script>
