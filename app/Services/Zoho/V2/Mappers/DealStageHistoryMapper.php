<?php

namespace App\Services\Zoho\V2\Mappers;

class DealStageHistoryMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'deal_zoho_id' => $this->lookupId($payload['Potential_Name'] ?? $payload['Deal_Name'] ?? $payload['Parent_Id'] ?? null),
            'stage' => $this->value($payload['Stage'] ?? $payload['Value'] ?? null),
            'previous_stage' => $this->value($payload['Previous_Stage'] ?? null), 'occurred_at' => $this->timestamp($payload['Modified_Time'] ?? $payload['Created_Time'] ?? null),
            'amount' => $this->decimal($payload['Amount'] ?? null, true), 'probability' => $this->decimal($payload['Probability'] ?? null, true), 'expected_revenue' => $this->decimal($payload['Expected_Revenue'] ?? null, true), 'currency_code' => $this->value($payload['Currency'] ?? null),
            'closing_date' => $this->value($payload['Closing_Date'] ?? null),
        ]);
    }
}
