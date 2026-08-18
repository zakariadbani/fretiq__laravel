{{--
    Launch + poll JS for the batch hero actions (_header-actions.blade.php
    and ProspectBatchViewConfig's quick_actions mirror the same data-* and
    onclick contract). Cloned from prospect_criteria's _discovery-script.blade.php
    launchMissingContactEnrichment flow, minus the unsaved-form / min-score
    guards that only apply to the criteria edit form. Also hosts
    enrichCompanyRow(), a per-row action for the Résultats tab's Actions
    column (_results-tab.blade.php) — unlike the batch-wide actions above it
    targets one company directly via admin.companies.enrich, no preview step.
--}}
<script>
(function () {
    'use strict';

    if (window.launchBatchContactEnrichment) return;

    window.launchBatchContactEnrichment = function (button) {
        if (!button || button.disabled) return;
        button.disabled = true;

        fetch(button.dataset.previewUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) throw new Error(data.text || 'Prévisualisation impossible.');
                    return data;
                });
            })
            .then(function (data) {
                var callable = Number(data.callable_count || 0);
                var eligible = Number(data.eligible_count || 0);
                var successTarget = Number(data.success_target || 0);
                var attemptLimit = Number(data.attempt_limit || 0);
                var threshold = Number(data.effective_min_score || 0);
                var summary = 'Seuil : ' + threshold + '/100. ' +
                    eligible + ' entreprise(s) éligible(s). ' +
                    'Objectif : ' + successTarget + ' enrichissement(s) réussi(s), avec au plus ' + attemptLimit + ' tentative(s). ' +
                    String(data.limit_note || '');

                if (callable === 0) {
                    return Swal.fire({
                        title: 'Aucune entreprise à interroger',
                        text: summary,
                        icon: 'info',
                        confirmButtonText: 'Fermer',
                    }).then(function () { button.disabled = false; });
                }

                return Swal.fire({
                    title: 'Chercher les contacts manquants ?',
                    text: summary,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Lancer ' + attemptLimit + ' tentative(s) max.',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#009ef7',
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        button.disabled = false;
                        return;
                    }

                    return fetch(button.dataset.dispatchUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': button.dataset.csrfToken,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ approval_token: data.approval_token }),
                    }).then(function (response) {
                        return response.json().then(function (payload) {
                            return { status: response.status, data: payload };
                        });
                    }).then(function (response) {
                        if (response.status === 202) {
                            toastr.success(response.data.text, 'Recherche lancée');
                            button.disabled = false;
                            return;
                        }
                        if (response.status === 409) {
                            toastr.info(response.data.text, 'En cours');
                        } else {
                            toastr.error(response.data.text || 'La recherche n’a pas pu être lancée.', 'Erreur');
                        }
                        button.disabled = false;
                    }).catch(function () {
                        toastr.error('La recherche n’a pas pu être lancée.', 'Erreur');
                        button.disabled = false;
                    });
                });
            })
            .catch(function (error) {
                button.disabled = false;
                Swal.fire({
                    title: 'Erreur',
                    text: error.message || 'Prévisualisation impossible.',
                    icon: 'error',
                });
            });
    };

    // Per-row "Récupérer les contacts" on a promoted company (Résultats tab).
    // No already-enriched guard server-side (CompanyController::enrich) — the
    // confirm says so plainly. Distinct from launchBatchContactEnrichment above:
    // this hits admin.companies.enrich directly, one company at a time, no
    // preview/approval-token round trip.
    window.enrichCompanyRow = function (button) {
        if (!button || button.disabled) return;

        var companyName = button.dataset.companyName || 'cette entreprise';

        Swal.fire({
            title: 'Récupérer les contacts de ' + companyName + ' ?',
            text: '1 crédit contact sera consommé, même si cette entreprise a déjà été enrichie.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Récupérer',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#009ef7',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            button.disabled = true;

            fetch(button.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': button.dataset.csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            }).then(function (response) {
                return response.json().then(function (payload) {
                    return { status: response.status, data: payload };
                });
            }).then(function (response) {
                if (response.status === 200) {
                    toastr.success(response.data.text || 'Recherche de contacts terminée.', 'Succès');
                    window.location.reload();
                    return;
                }
                toastr.error(response.data.text || 'La recherche de contacts n’a pas pu être lancée.', 'Erreur');
                button.disabled = false;
            }).catch(function () {
                toastr.error('La recherche de contacts n’a pas pu être lancée.', 'Erreur');
                button.disabled = false;
            });
        });
    };

    window.launchBatchRescore = function (button) {
        if (!button || button.disabled) return;

        var companyCount = Number(button.dataset.companyCount || 0);

        Swal.fire({
            title: 'Relancer le scoring IA ?',
            text: 'Recalcule le score IA de ' + companyCount + ' entreprise(s) de ce lot. ' +
                'Les scores sont recalculés — certaines entreprises peuvent sortir de l’éligibilité à l’enrichissement.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Relancer',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#ffc700',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            button.disabled = true;
            fetch(button.dataset.dispatchUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': button.dataset.csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            }).then(function (response) {
                return response.json().then(function (payload) {
                    return { status: response.status, data: payload };
                });
            }).then(function (response) {
                if (response.status !== 202) {
                    if (response.status === 409) {
                        toastr.info(response.data.text, 'En cours');
                    } else {
                        toastr.error(response.data.text || 'Le rescoring n’a pas pu être lancé.', 'Erreur');
                    }
                    button.disabled = false;
                    return;
                }

                var pollStartedAt = Date.now();
                var pollTimeoutMs = 120000;

                var poll = function () {
                    fetch(response.data.status_url, { headers: { 'Accept': 'application/json' } })
                        .then(function (statusResponse) { return statusResponse.json(); })
                        .then(function (payload) {
                            if (!payload.terminal) {
                                if (Date.now() - pollStartedAt >= pollTimeoutMs) {
                                    button.disabled = false;
                                    toastr.info('Le traitement continue en arrière-plan…', 'Toujours en cours');
                                    return;
                                }
                                window.setTimeout(poll, 3000);
                                return;
                            }
                            button.disabled = false;
                            if (payload.error) {
                                toastr.error('Le rescoring a échoué. Réessayez.', 'Erreur');
                                return;
                            }
                            toastr.success(
                                (payload.rescored || 0) + ' entreprise(s) rescorée(s), ' + (payload.excluded || 0) + ' exclue(s).',
                                'Rescoring terminé'
                            );
                        })
                        .catch(function () {
                            button.disabled = false;
                            toastr.error('Suivi du rescoring interrompu.', 'Erreur');
                        });
                };
                window.setTimeout(poll, 3000);
            }).catch(function () {
                button.disabled = false;
                toastr.error('Le rescoring n’a pas pu être lancé.', 'Erreur');
            });
        });
    };

    // Skip-reason codes are the exact taxonomy the backend reports for both
    // "À relancer" drains — keep in sync with DrainRetryableProspectItemsJob /
    // ProspectBatchService::preflightRetrySkipReason(). Hoisted here rather
    // than duplicated: _workspace.blade.php (the other place this map lived)
    // is never rendered when the review pane is empty, which is exactly the
    // case the "Reprendre les lignes en attente" drain below exists for.
    var drainSkipLabels = {
        retry_window_open: 'fenêtre de relance encore fermée',
        budget_exhausted: 'budget de relance épuisé',
        provider_outcome_uncertain: 'résultat fournisseur à confirmer individuellement',
        criterion_inactive: 'critère inactif',
    };
    var drainSkipLabel = function (code) { return drainSkipLabels[code] || code; };
    var pluralizeDrain = function (count, singular, plural) { return count + ' ' + (count === 1 ? singular : plural); };

    window.launchStalledDrain = function (button) {
        if (!button || button.disabled) return;

        Swal.fire({
            title: 'Reprendre les lignes en attente ?',
            text: 'Relance le traitement des entreprises restées bloquées après une relance manuelle sans worker actif pour la traiter.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Reprendre',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#ffc700',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            button.disabled = true;
            fetch(button.dataset.dispatchUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': button.dataset.csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            }).then(function (response) {
                return response.json().then(function (payload) {
                    return { status: response.status, data: payload };
                });
            }).then(function (response) {
                if (response.status !== 200) {
                    if (response.status === 422 && response.data.code === 'prospect_retry_drain_empty') {
                        toastr.info('Plus aucune ligne en attente à reprendre.', 'Rien à faire');
                    } else {
                        toastr.error('La reprise n’a pas pu être lancée.', 'Erreur');
                    }
                    button.disabled = false;
                    return;
                }

                var pollStartedAt = Date.now();
                var pollTimeoutMs = 120000;

                var poll = function () {
                    fetch(response.data.status_url, { headers: { 'Accept': 'application/json' } })
                        .then(function (statusResponse) { return statusResponse.json(); })
                        .then(function (payload) {
                            if (!payload.terminal) {
                                if (Date.now() - pollStartedAt >= pollTimeoutMs) {
                                    button.disabled = false;
                                    toastr.info('Le traitement continue en arrière-plan…', 'Toujours en cours');
                                    return;
                                }
                                window.setTimeout(poll, 3000);
                                return;
                            }
                            button.disabled = false;
                            if (payload.error) {
                                toastr.error('La reprise a échoué. Réessayez.', 'Erreur');
                                return;
                            }
                            var parts = [pluralizeDrain(payload.retried || 0, 'entreprise relancée', 'entreprises relancées')];
                            Object.entries(payload.skipped || {}).forEach(function (entry) {
                                if (entry[1] > 0) {
                                    parts.push(pluralizeDrain(entry[1], 'ignorée', 'ignorées') + ' (' + drainSkipLabel(entry[0]) + ')');
                                }
                            });
                            toastr.success(parts.join(' · ') + '.', 'Reprise terminée');
                        })
                        .catch(function () {
                            button.disabled = false;
                            toastr.error('Suivi de la reprise interrompu.', 'Erreur');
                        });
                };
                window.setTimeout(poll, 3000);
            }).catch(function () {
                button.disabled = false;
                toastr.error('La reprise n’a pas pu être lancée.', 'Erreur');
            });
        });
    };
})();
</script>
