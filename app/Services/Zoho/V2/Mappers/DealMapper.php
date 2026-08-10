<?php

namespace App\Services\Zoho\V2\Mappers;

class DealMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'name' => $this->value($payload['Deal_Name'] ?? null), 'stage' => $this->value($payload['Stage'] ?? null),
            'amount' => $this->decimal($payload['Amount'] ?? null, true), 'currency_code' => $this->value($payload['Currency'] ?? null),
            'probability' => $this->decimal($payload['Probability'] ?? null, true), 'weighted_amount' => $this->weightedAmount($payload['Amount'] ?? null, $payload['Probability'] ?? null), 'closing_date' => $this->value($payload['Closing_Date'] ?? null),
            'lead_source' => $this->value($payload['Lead_Source'] ?? null), 'account_zoho_id' => $this->lookupId($payload['Account_Name'] ?? null),
            'contact_zoho_id' => $this->lookupId($payload['Contact_Name'] ?? null),
            'exchange_rate' => $this->decimal($payload['Exchange_Rate'] ?? null, true), 'pipeline' => $this->value($payload['Pipeline'] ?? null), 'stackability' => $this->value($payload['G_rbable'] ?? null), 'last_activity_at' => $this->timestamp($payload['Last_Activity_Time'] ?? null), 'stage_modified_at' => $this->timestamp($payload['Stage_Modified_Time'] ?? null), 'tags' => $this->stringList($payload['Tag'] ?? null),
            'origin' => $this->value($payload['Origine'] ?? null), 'destination' => $this->value($payload['Destination'] ?? null), 'incoterm' => $this->value($payload['Incoterm'] ?? null), 'cargo_description' => $this->value($payload['Marchandise'] ?? null), 'gross_weight' => $this->value($payload['P_Brut'] ?? null), 'volume' => $this->value($payload['Volume'] ?? null), 'quantity' => $this->integer($payload['Quantit'] ?? null), 'dimensions' => $this->value($payload['Dimensions_CM'] ?? null), 'departure_frequency' => $this->value($payload['Fr_quence_D_part'] ?? null), 'package_type' => $this->value($payload['Type_de_Colis'] ?? null), 'quote_type' => $this->value($payload['Type_de_Cotation'] ?? null), 'transport_type' => $this->value($payload['Type_de_Transpot'] ?? null), 'transit_time' => $this->value($payload['Transite_Time_J'] ?? null), 'expires_on' => $this->value($payload['Date_d_expiration'] ?? null), 'description' => $this->value($payload['Description'] ?? null),
        ]);
    }
}
