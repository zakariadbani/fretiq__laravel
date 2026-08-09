<?php

namespace App\Services\Zoho\V2\Transport;

interface Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
