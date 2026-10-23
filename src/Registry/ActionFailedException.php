<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

use RuntimeException;

/**
 * An action could not do what was asked, for a reason that is safe to show the person or model that asked (a record that does not
 * exist, a stage from another pipeline). The message is returned as is; any other exception is logged and reported as a generic
 * failure.
 */
class ActionFailedException extends RuntimeException {}
