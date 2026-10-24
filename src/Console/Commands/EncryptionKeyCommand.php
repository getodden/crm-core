<?php

declare(strict_types=1);

namespace Odden\Core\Console\Commands;

use Illuminate\Console\Command;

class EncryptionKeyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'odden:encryption-key';

    /**
     * @var string
     */
    protected $description = 'Make a new key for encrypting personal data at rest (put it in ODDEN_ENCRYPTION_KEY)';

    public function handle(): int
    {
        $this->line('base64:'.base64_encode(random_bytes(32)));
        $this->components->info('Put this in your .env as ODDEN_ENCRYPTION_KEY and keep a copy somewhere safe: data encrypted with it cannot be read without it. To change the key later, move the old one to the previous keys in config/odden-core.php.');

        return self::SUCCESS;
    }
}
