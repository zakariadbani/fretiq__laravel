<?php

namespace App\Services\Zoho\V2\Mappers;

class AccountMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'name' => $this->value($payload['Account_Name'] ?? null), 'phone' => $this->value($payload['Phone'] ?? null),
            'country' => $this->value($payload['Billing_Country'] ?? $payload['Shipping_Country'] ?? null),
            'industry' => $this->value($payload['Secteur_Activit'] ?? null), 'website' => $this->value($payload['Website'] ?? null),
            'account_type' => $this->value($payload['Type_de_client'] ?? null), 'parent_account_zoho_id' => $this->lookupId($payload['Parent_Account'] ?? null),
            'active_status' => $this->value($payload['Actif'] ?? null), 'account_status' => $this->value($payload['Statut_du_Compte'] ?? null), 'address' => $this->value($payload['Adresse'] ?? null), 'city' => $this->value($payload['Billing_City'] ?? null), 'language' => $this->value($payload['Langue'] ?? null), 'commercial_name' => $this->value($payload['Commercial'] ?? null), 'assignment_type' => $this->value($payload['Type_Affectation_Commercial'] ?? null), 'accounting_number' => $this->value($payload['Num_ro_Comptable'] ?? null), 'ice' => $this->value($payload['ICE'] ?? null), 'tax_id' => $this->value($payload['I_F'] ?? null), 'trade_register' => $this->value($payload['R_C'] ?? null), 'cnss' => $this->value($payload['CNSS'] ?? null), 'business_tax_number' => $this->value($payload['Patente'] ?? null), 'trade_register_center' => $this->value($payload['Centre_R_C'] ?? null), 'payment_mode' => $this->value($payload['Mode_Paiement'] ?? null), 'transport_type' => $this->value($payload['Type_de_transport_utilis'] ?? null), 'volume' => $this->value($payload['Volume'] ?? null), 'competitor' => $this->value($payload['Prestataire_Concurrent'] ?? null), 'origin_destination' => $this->value($payload['Provenance_Destination'] ?? null), 'observation' => $this->value($payload['Observation'] ?? null), 'logistics_manager_zoho_id' => $this->lookupId($payload['Nom_de_Responsable_Logistique'] ?? null), 'last_activity_at' => $this->timestamp($payload['Last_Activity_Time'] ?? null), 'tags' => $this->stringList($payload['Tag'] ?? null),
        ]);
    }
}
