<?php

namespace App\Exceptions;

use RuntimeException;

final class ZohoInvalidRecipientException extends RuntimeException
{
    public readonly string $email;

    public function __construct(
        string $email,
        public readonly string $zohoCode,
    ) {
        $this->email = mb_strtolower(trim($email));

        parent::__construct("Zoho rejected invalid contact email {$this->email} (code {$zohoCode}).");
    }
}
