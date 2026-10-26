<?php

namespace App\Support;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

class ConfigureSafeLogging
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new SafeLogProcessor);
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'setFormatter')) {
                $handler->setFormatter(new JsonFormatter);
            }
        }
    }
}
