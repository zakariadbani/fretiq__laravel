<?php

namespace App\Services\Zoho\V2\Mappers;

class ContactMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'first_name' => $this->value($payload['First_Name'] ?? null), 'last_name' => $this->value($payload['Last_Name'] ?? null),
            'full_name' => $this->value($payload['Full_Name'] ?? trim(($payload['First_Name'] ?? '').' '.($payload['Last_Name'] ?? ''))),
            'email' => $this->value($payload['Email'] ?? null), 'normalized_email' => $this->normalizedEmail($payload['Email'] ?? null), 'phone' => $this->value($payload['Phone'] ?? null),
            'country' => $this->value($payload['Mailing_Country'] ?? $payload['Other_Country'] ?? null),
            'title' => $this->value($payload['Intitul_de_Poste'] ?? null), 'account_zoho_id' => $this->lookupId($payload['Account_Name'] ?? null), 'company_name' => $this->value($payload['Nom_du_Soci_t'] ?? null), 'address' => $this->value($payload['Adresse'] ?? null), 'city' => $this->value($payload['Mailing_City'] ?? null), 'postal_code' => $this->value($payload['Mailing_Zip'] ?? null), 'language' => $this->value($payload['Langue'] ?? null), 'lead_source' => $this->value($payload['Lead_Source'] ?? null), 'email_opt_out' => $this->nullableBoolean($payload['Email_Opt_Out'] ?? null), 'email_opened' => $this->nullableBoolean($payload['Email_Ouvert'] ?? null), 'link_clicked' => $this->nullableBoolean($payload['Lien_Cliqu'] ?? null), 'unsubscribed_mode' => $this->value($payload['Unsubscribed_Mode'] ?? null), 'unsubscribed_at' => $this->timestamp($payload['Unsubscribed_Time'] ?? null), 'last_activity_at' => $this->timestamp($payload['Last_Activity_Time'] ?? null), 'description' => $this->value($payload['Description'] ?? null), 'tags' => $this->stringList($payload['Tag'] ?? null), 'mobile' => $this->value($payload['Mobile'] ?? null), 'salutation' => $this->value($payload['Salutation'] ?? null),
        ]);
    }
}
