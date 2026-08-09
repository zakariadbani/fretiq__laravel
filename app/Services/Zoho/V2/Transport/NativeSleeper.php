<?php

namespace App\Services\Zoho\V2\Transport;

final class NativeSleeper implements Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        usleep(max(0, $milliseconds) * 1000);
    }
}
