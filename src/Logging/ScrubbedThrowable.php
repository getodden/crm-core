<?php

declare(strict_types=1);

namespace Odden\Core\Logging;

use Exception;
use ReflectionProperty;
use Throwable;

/**
 * An exception as a log formatter sees it, with the messages scrubbed (see LogScrubber) and the arguments of the calls in its trace
 * left out. It keeps the original's class name in the message, its file, line, code, trace and (scrubbed) previous exceptions.
 */
final class ScrubbedThrowable extends Exception
{
    public function __construct(Throwable $original)
    {
        parent::__construct(
            $original::class.': '.LogScrubber::message($original),
            is_int($original->getCode()) ? $original->getCode() : 0,
            $original->getPrevious() === null ? null : new self($original->getPrevious()),
        );

        $this->file = $original->getFile();
        $this->line = $original->getLine();

        $trace = array_map(function (array $frame): array {
            unset($frame['args']);

            return $frame;
        }, $original->getTrace());

        (new ReflectionProperty(Exception::class, 'trace'))->setValue($this, $trace);
    }
}
