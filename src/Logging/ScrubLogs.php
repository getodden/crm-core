<?php

declare(strict_types=1);

namespace Odden\Core\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;

/**
 * A channel `tap` (and Monolog processor) that runs every log record through LogScrubber. The service provider adds it to every
 * configured channel, unless `odden-core.logging.scrub` is turned off.
 */
final class ScrubLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor($this->process(...));
        }
    }

    public function process(LogRecord $record): LogRecord
    {
        return $record->with(
            message: LogScrubber::scrub($record->message),
            context: LogScrubber::context($record->context),
            extra: LogScrubber::context($record->extra),
        );
    }
}
