<?php

declare(strict_types=1);

namespace App\Services\Inbox;

/**
 * Strict parser for machine-readable DSN fields. It deliberately refuses
 * subject/body heuristics: only a Fretiq message id plus Action and Status is
 * enough to alter delivery state.
 */
class DeliveryStatusNotificationParser
{
    /**
     * @return array{target_type: 'campaign_recipient'|'sequence_send', target_id: int, outcome: 'hard_bounce'|'soft_bounce', detail: ?string}|null
     */
    public function parse(string $rawMessage): ?array
    {
        if ($rawMessage === '') {
            return null;
        }

        if (! $this->hasDeliveryStatusContentType($rawMessage)) {
            return null;
        }

        $messageId = $this->header($rawMessage, 'Original-Message-ID');
        $actionValue = $this->header($rawMessage, 'Action');
        $action = $actionValue === null ? null : strtolower($actionValue);
        $status = $this->header($rawMessage, 'Status');
        if ($messageId === null || $action === null || $status === null) {
            return null;
        }

        if (preg_match('/^<(campaign-recipient|sequence-send)-([1-9][0-9]*)@fretiq\\.local>$/i', trim($messageId), $target) !== 1) {
            return null;
        }
        if (preg_match('/^([245])\\.[0-9]{1,3}\\.[0-9]{1,3}$/', trim($status), $statusParts) !== 1) {
            return null;
        }

        $outcome = match (true) {
            $statusParts[1] === '5' && $action === 'failed' => 'hard_bounce',
            $statusParts[1] === '4' && in_array($action, ['delayed', 'failed'], true) => 'soft_bounce',
            default => null,
        };
        if ($outcome === null) {
            return null;
        }

        return [
            'target_type' => strtolower($target[1]) === 'campaign-recipient' ? 'campaign_recipient' : 'sequence_send',
            'target_id' => (int) $target[2],
            'outcome' => $outcome,
            'detail' => $this->diagnostic($this->header($rawMessage, 'Diagnostic-Code')),
        ];
    }

    private function hasDeliveryStatusContentType(string $rawMessage): bool
    {
        if (preg_match_all('/^Content-Type:[ \t]*([^\r\n]+(?:\r?\n[ \t]+[^\r\n]+)*)/im', $rawMessage, $matches) < 1) {
            return false;
        }

        foreach ($matches[1] as $value) {
            $value = strtolower(trim((string) preg_replace('/\r?\n[ \t]+/', ' ', $value)));
            if (str_starts_with($value, 'message/delivery-status')
                || (str_starts_with($value, 'multipart/report')
                    && preg_match('/report-type\s*=\s*"?delivery-status"?/i', $value) === 1)) {
                return true;
            }
        }

        return false;
    }

    private function header(string $rawMessage, string $name): ?string
    {
        $name = preg_quote($name, '/');
        if (preg_match('/^'.$name.':[ \\t]*([^\\r\\n]+(?:\\r?\\n[ \\t]+[^\\r\\n]+)*)/im', $rawMessage, $match) !== 1) {
            return null;
        }

        $value = trim((string) preg_replace('/\\r?\\n[ \\t]+/', ' ', $match[1]));

        return $value === '' ? null : $value;
    }

    private function limit(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function diagnostic(?string $value): ?string
    {
        $value = $this->limit($value, 500);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[redacted]', $value) ?? '';
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return $this->limit($value, 500);
    }
}
