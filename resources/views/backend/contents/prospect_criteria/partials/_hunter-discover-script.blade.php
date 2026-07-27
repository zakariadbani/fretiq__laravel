<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('hunter-discover-form'); if (!form) return;
    const previewButton = form.querySelector('[data-hunter-preview]'), importButton = document.querySelector('[data-hunter-import]'), results = document.querySelector('[data-hunter-results]'), rows = document.querySelector('[data-hunter-rows]'), empty = document.querySelector('[data-hunter-empty]'), error = form.querySelector('[data-hunter-error]');
    let previewId = null;
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const selected = () => [...rows.querySelectorAll('input[type=checkbox]:checked')].map(input => input.value);
    const syncImport = () => { importButton.disabled = selected().length === 0; };
    const message = value => { error.textContent = value || ''; };

    form.addEventListener('submit', async event => {
        event.preventDefault(); if (previewButton.disabled) return;
        previewButton.disabled = true; previewButton.setAttribute('data-kt-indicator', 'on'); message(''); results.classList.add('d-none');
        try {
            const response = await fetch(form.dataset.previewUrl, {method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]').value}, body:new FormData(form)});
            const data = await response.json(); if (!response.ok) throw new Error(data.message || 'La prévisualisation a échoué.');
            previewId = data.preview_id;
            rows.innerHTML = data.companies.map(company => `<tr><td><input class="form-check-input" type="checkbox" value="${escape(company.domain)}" ${company.eligible && company.checked ? 'checked' : ''} ${company.eligible ? '' : 'disabled'}></td><td class="fw-semibold">${escape(company.organization || company.domain)}</td><td>${escape(company.domain)}</td><td>${Number(company.emails_count?.total || 0)}</td><td><span class="badge ${company.status === 'new' ? 'badge-light-success' : company.status === 'rejected_current' ? 'badge-light-warning' : 'badge-light-secondary'}">${escape(company.status_label)}</span></td></tr>`).join('');
            rows.querySelectorAll('input').forEach(input => input.addEventListener('change', syncImport)); empty.classList.toggle('d-none', data.companies.length !== 0); results.classList.remove('d-none'); syncImport();
        } catch (exception) { message(exception.message); }
        finally { previewButton.disabled = false; previewButton.removeAttribute('data-kt-indicator'); }
    });

    importButton.addEventListener('click', async () => {
        const domains = selected(); if (!previewId || !domains.length || importButton.disabled) return;
        importButton.disabled = true; message('');
        try {
            const response = await fetch(form.dataset.importUrl, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]').value}, body:JSON.stringify({preview_id:previewId, domains})});
            const data = await response.json(); if (!response.ok) throw new Error(data.message || 'L’aperçu a expiré. Relancez la recherche.');
            if (window.toastr) toastr.success(`${data.imported} entreprise(s) importée(s), ${data.reactivated} réactivée(s).`);
            window.location.assign(data.redirect_url);
        } catch (exception) { message(exception.message); importButton.disabled = false; }
    });
});
</script>
