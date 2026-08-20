<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FilterHandler;
use Monolog\Level;

/**
 * Channel tap that caps an activity channel at info+warning, excluding
 * error+. Paired with a stack member on the `errors` channel (level
 * `error`, no tap), so info/warning land in the activity file and
 * error+ land in errors-*.log only. Invariant: every activity channel
 * using this tap MUST include the `errors` channel as a stack member,
 * or its errors vanish entirely.
 */
class ExcludeErrors
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        $monolog->setHandlers(array_map(
            fn ($handler) => new FilterHandler($handler, Level::Debug, Level::Warning),
            $monolog->getHandlers()
        ));
    }
}
