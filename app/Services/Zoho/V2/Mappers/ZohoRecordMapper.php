<?php

namespace App\Services\Zoho\V2\Mappers;

interface ZohoRecordMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function map(array $payload, array $context = []): array;
}
