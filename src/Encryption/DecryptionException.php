<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use RuntimeException;

/**
 * A stored value could not be read: it was changed, it belongs to another field or another tenant, or its key is unknown. The
 * message never contains the value or any key.
 */
final class DecryptionException extends RuntimeException {}
