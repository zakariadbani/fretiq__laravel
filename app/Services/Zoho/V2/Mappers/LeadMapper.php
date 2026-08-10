<?php

namespace App\Services\Zoho\V2\Mappers;

class LeadMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'first_name' => $this->value($payload['First_Name'] ?? null), 'last_name' => $this->value($payload['Last_Name'] ?? null),
            'full_name' => $this->value($payload['Full_Name'] ?? trim(($payload['First_Name'] ?? '').' '.($payload['Last_Name'] ?? ''))),
            'company_name' => $this->value($payload['Company'] ?? null), 'email' => $this->value($payload['Email'] ?? null),
            'normalized_email' => $this->normalizedEmail($payload['Email'] ?? null), 'phone' => $this->value($payload['Phone'] ?? null), 'country' => $this->value($payload['Country'] ?? null),
            'industry' => $this->value($payload['Secteur_Activit'] ?? null), 'status' => $this->value($payload['Lead_Status'] ?? null), 'is_converted' => $this->nullableBoolean($payload['Converted__s'] ?? null),
            'account_zoho_id' => $this->lookupId($payload['Converted_Account'] ?? null), 'contact_zoho_id' => $this->lookupId($payload['Converted_Contact'] ?? null),
            'converted_deal_zoho_id' => $this->lookupId($payload['Converted_Deal'] ?? null), 'converted_at' => $this->timestamp($payload['Converted_Date_Time'] ?? null),
            'title' => $this->value($payload['Intitul_de_Poste'] ?? null), 'client_type' => $this->value($payload['Type_de_client'] ?? null), 'transport_type' => $this->value($payload['Type_de_transport_utilis'] ?? null), 'language' => $this->value($payload['Langue'] ?? null),
            'address' => $this->value($payload['Adresse'] ?? null), 'city' => $this->value($payload['City'] ?? null), 'website' => $this->value($payload['Website'] ?? null), 'incoterm' => $this->value($payload['Incoterm'] ?? null), 'destination' => $this->value($payload['Destination'] ?? null), 'volume' => $this->value($payload['Volume'] ?? null), 'competitor' => $this->value($payload['Prestataire_Concurrent'] ?? null), 'origin_destination' => $this->value($payload['Provenance_Destination'] ?? null), 'observation' => $this->value($payload['Observation'] ?? null), 'email_opt_out' => $this->nullableBoolean($payload['Email_Opt_Out'] ?? null), 'unsubscribed_mode' => $this->value($payload['Unsubscribed_Mode'] ?? null), 'unsubscribed_at' => $this->timestamp($payload['Unsubscribed_Time'] ?? null), 'last_activity_at' => $this->timestamp($payload['Last_Activity_Time'] ?? null), 'tags' => $this->stringList($payload['Tag'] ?? null), 'mobile' => $this->value($payload['Mobile'] ?? null), 'secondary_phone' => $this->value($payload['T_l_phone_2'] ?? null),
        ]);
    }
}
