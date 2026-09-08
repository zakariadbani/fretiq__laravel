@php
    $reasonLabels = ['company_not_prospect' => 'Entreprise ou contact inadmissible', 'verification_stale' => 'Vérification à actualiser', 'suppressed' => 'Opposition ou suppression', 'engaged' => 'Demande ou conversation existante', 'bounced' => 'Rebond enregistré', 'invalid_email' => 'Adresse invalide', 'already_enrolled' => 'Société déjà inscrite dans cette séquence', 'pending_work' => 'Autre envoi en attente'];
@endphp
@if(!empty($continuousProspecting))
<section class="alert alert-info mt-5" data-testid="continuous-prospecting-summary" aria-label="Prospection continue">
    <h4>Prospection continue — état enregistré</h4>
    <p>Réservoir : <strong>{{ $continuousProspecting['research_pool_count'] }}</strong> contacts.</p>
    <p>Lot dû maintenant : <strong>{{ $continuousProspecting['company_count'] }}</strong> sociétés / {{ $continuousProspecting['contact_count'] }} contacts.</p>
    <p>Prochain lot estimé (estimation hors verrou, {{ $continuousProspecting['scanned_companies'] }} sociétés examinées) : <strong>{{ $continuousProspecting['next_batch_company_count'] }}</strong> sociétés / {{ $continuousProspecting['next_batch_contact_count'] }} contacts, le <strong>{{ $continuousProspecting['next_processing_at']?->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y H:i') ?? 'premier lot à définir' }}</strong> ({{ $model->scheduleTimezone() }}).</p>
    @if(!$model->is_active)<p><strong>Campagne inactive : aucun envoi programmé par cette préparation.</strong></p>@endif
    @if($continuousProspecting['excluded_reasons'])
        <ul class="mb-3">@foreach($continuousProspecting['excluded_reasons'] as $reason => $count)<li>{{ $reasonLabels[$reason] ?? 'Autre exclusion' }} : {{ $count }} société(s)</li>@endforeach</ul>
    @endif
    <p class="mb-2">Les exclusions comptent une raison principale par société parmi celles examinées. Les données sont réévaluées à l'inscription et avant l'envoi.</p>
    <p class="mb-0">Arrêter l'inscription automatique bloque les nouvelles entrées. Pour arrêter les envois en attente, mettez la séquence liée en pause ; cela concerne toutes les campagnes qui l'utilisent. Une limite SMTP à 0 bloque les envois SMTP de cette campagne, mais pas les nouvelles inscriptions. Un arrêt complet demande les deux actions.
        @can('view sequences')
            @if($model->sequence_id)
                <a href="{{ route('admin.sequences.view', $model->sequence_id) }}">Voir la séquence liée</a>.
            @endif
        @endcan
        Enregistrez vos changements pour actualiser cet aperçu.</p>
</section>
@endif
