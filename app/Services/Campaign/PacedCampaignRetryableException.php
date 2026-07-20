<?php

namespace App\Services\Campaign;

use RuntimeException;

/** Safe queue retry signal for a paced run that still owns queued recipients. */
class PacedCampaignRetryableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Envoi progressif incomplet : certains destinataires seront réessayés.');
    }
}
