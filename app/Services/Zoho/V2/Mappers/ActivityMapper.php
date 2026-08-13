<?php

namespace App\Services\Zoho\V2\Mappers;

class ActivityMapper extends AbstractZohoMapper
{
    public function __construct(private readonly string $defaultType = 'task') {}

    public function map(array $payload, array $context = []): array
    {
        $type = $this->type($context['activity_type'] ?? $this->defaultType);

        $subject = match ($type) {
            'meeting' => $payload['Event_Title'] ?? $payload['Subject'] ?? null, 'note' => $payload['Note_Title'] ?? $payload['Subject'] ?? null, default => $payload['Subject'] ?? null
        };
        $status = match ($type) {
            'meeting' => $payload['Status'] ?? $payload['Check_In_Status'] ?? $payload['Record_Status__s'] ?? null,
            'call' => $payload['Outgoing_Call_Status'] ?? $payload['Call_Status'] ?? $payload['Status'] ?? null,
            default => $payload['Status'] ?? null,
        };
        $activityAt = match ($type) {
            'meeting' => $payload['Start_DateTime'] ?? $payload['Activity_DateTime'] ?? $payload['Created_Time'] ?? null, 'call' => $payload['Call_Start_Time'] ?? $payload['Activity_DateTime'] ?? $payload['Created_Time'] ?? null, 'note' => $payload['Created_Time'] ?? null, default => $payload['Activity_DateTime'] ?? $payload['Due_Date'] ?? $payload['Created_Time'] ?? null
        };
        $startAt = match ($type) {
            'meeting' => $payload['Start_DateTime'] ?? null,
            'call' => $payload['Call_Start_Time'] ?? null,
            default => $payload['Start_DateTime'] ?? $payload['Call_Start_Time'] ?? null,
        };

        return array_merge($this->base($payload, $context), [
            'activity_type' => $type, 'subject' => $this->value($subject),
            'status' => $this->value($status), 'activity_at' => $this->timestamp($activityAt),
            'due_at' => $this->timestamp($payload['Due_Date'] ?? null),
            'start_at' => $this->timestamp($startAt),
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
