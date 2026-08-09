<?php

namespace App\Services\Zoho\V2\Mappers;

class ActivityMapper extends AbstractZohoMapper
{
    public function __construct(private readonly string $defaultType = 'task') {}

    public function map(array $payload, array $context = []): array
    {
        $type = $this->type($context['activity_type'] ?? $this->defaultType);

        return array_merge($this->base($payload, $context), [
            'activity_type' => $type, 'subject' => $this->value($payload['Subject'] ?? $payload['Note_Title'] ?? null),
            'status' => $this->value($payload['Status'] ?? $payload['Call_Status'] ?? null), 'activity_at' => $this->timestamp($payload['Activity_DateTime'] ?? $payload['Created_Time'] ?? null),
            'due_at' => $this->timestamp($payload['Due_Date'] ?? null),
            'start_at' => $this->timestamp($payload['Start_DateTime'] ?? $payload['Call_Start_Time'] ?? null),
            'end_at' => $this->timestamp($payload['End_DateTime'] ?? null), 'parent_zoho_id' => $this->lookupId($payload['What_Id'] ?? $payload['Parent_Id'] ?? null),
            'contact_zoho_id' => $this->lookupId($payload['Who_Id'] ?? $payload['Contact_Name'] ?? null),
        ]);
    }

    private function type(mixed $type): string
    {
        return match (strtolower((string) $type)) {
            'events', 'event', 'meeting', 'meetings' => 'meeting',
            'calls', 'call' => 'call', 'notes', 'note' => 'note',
            default => 'task',
        };
    }
}
