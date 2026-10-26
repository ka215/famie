<?php

namespace App\Support;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as Monolog;

class SafeLogManager extends LogManager
{
    protected function createEmergencyLogger(): Logger
    {
        $handler = new RotatingFileHandler($this->app->storagePath('logs/emergency.log'), maxFiles: 14, filePermission: 0600, useLocking: true);
        $handler->setFormatter(new JsonFormatter);

        return new Logger(new Monolog('famie', [$handler], [new SafeLogProcessor]), $this->app['events']);
    }
}
