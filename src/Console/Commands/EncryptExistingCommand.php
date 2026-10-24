<?php

declare(strict_types=1);

namespace Odden\Core\Console\Commands;

use Illuminate\Console\Command;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\EncryptedColumns;

class EncryptExistingCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'odden:encrypt-existing';

    /**
     * @var string
     */
    protected $description = 'Encrypt the values stored in plain text in columns that are now encrypted (safe to run again)';

    public function handle(EncryptedColumns $columns, ColumnConverter $converter): int
    {
        foreach ($columns->convert($converter) as $field => $count) {
            $this->line(sprintf('%-40s %d', $field, $count));
        }

        $this->components->info('Done. Once every database has been converted, set ODDEN_ENCRYPTION_ALLOW_PLAINTEXT=false so a plain value in an encrypted column is an error.');

        return self::SUCCESS;
    }
}
