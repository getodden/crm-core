<?php

declare(strict_types=1);

namespace Odden\Core\Exceptions;

use RuntimeException;

/**
 * A change to a sensitive property's definition that would leave its encrypted values unreadable or unprotected.
 */
class SensitivePropertyInUse extends RuntimeException {}
