@php
    $cellStatus = match (true) {
        $recipient === null => 'not_planned',
        $recipient->status === 'unsubscribed' => 'unsubscribed',
        $recipient->status === 'bounced' => 'bounced',
        $recipient->status === 'skipped' => 'skipped',
        $recipient->replied_at !== null || $recipient->status === 'replied' => 'replied',
        $recipient->clicked_at !== null || $recipient->status === 'clicked' => 'clicked',
        $recipient->opened_at !== null || $recipient->status === 'opened' => 'opened',
        $recipient->sent_at !== null || in_array($recipient->status, ['sent', 'delivered'], true) => 'sent_not_opened',
        default => 'queued',
    };
    $badgeColor = match ($cellStatus) {
        'opened' => 'success',
        'clicked' => 'warning',
        'replied' => 'primary',
        'bounced', 'skipped' => 'danger',
        'sent_not_opened' => 'info',
        default => 'secondary',
    };
@endphp

<td class="min-w-225px" data-step="{{ $step->step_no }}" data-step-status="{{ $cellStatus }}">
    <span class="badge badge-light-{{ $badgeColor }} text-wrap text-start lh-base">
        @switch($cellStatus)
            @case('not_planned')
                Pas encore planifi&eacute;
                @break
            @case('queued')
                En attente
                @break
            @case('skipped')
                @if($recipient->skip_reason === 'zoho_unsent')
                    Non envoy&eacute; par Zoho &mdash; adresse invalide ou refus&eacute;e
                @else
                    Non envoy&eacute;
                @endif
                @break
            @case('sent_not_opened')
                Envoy&eacute; &mdash; non ouvert
                @break
            @case('opened')
                Ouvert
                @break
            @case('clicked')
                Cliqu&eacute;
                @break
            @case('replied')
                R&eacute;pondu
                @break
            @case('unsubscribed')
                D&eacute;sinscrit
                @break
            @case('bounced')
                Rebond d&eacute;tect&eacute;
                @break
        @endswitch
    </span>
    @if($recipient !== null)
        @php
            $eventAt = match ($cellStatus) {
                'clicked' => $recipient->clicked_at,
                'opened' => $recipient->opened_at,
                'replied' => $recipient->replied_at,
                'bounced' => $recipient->bounced_at,
                'sent_not_opened' => $recipient->sent_at,
                default => null,
            };
        @endphp
        @if($eventAt)
            <div class="text-muted fs-8 mt-1">{{ $eventAt->format('d/m/Y H:i') }}</div>
        @endif
    @endif
</td>
