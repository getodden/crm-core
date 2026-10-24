<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\ColumnConverter;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The log of what the assistant and people asked actions to do, the input of an action waiting for approval, the rules of a list
     * and the name and values of a custom object record all hold what people wrote. They become text columns for the longer stored
     * form. (A proposal's input was already encrypted, with the application key; it is read with that key and stored with the
     * workspace's.)
     */
    public function up(): void
    {
        foreach ($this->columns() as [$table, $column, $field]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->text($column)->nullable()->change();
            });
        }

        if (config('odden-core.encryption.convert_on_migrate', true)) {
            $converter = app(ColumnConverter::class);

            foreach ($this->columns() as [$table, $column, $field]) {
                $converter->encrypt($table, $column, $field, $field === 'proposed_actions.input' ? self::readApplicationKey(...) : null);
            }
        }
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key first.
     */
    public function down(): void
    {
        $converter = app(ColumnConverter::class);

        foreach ($this->columns() as [$table, $column, $field]) {
            $converter->decrypt($table, $column, $field);
        }
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function columns(): array
    {
        $log = config('odden-core.tables.action_log', 'odden_action_log');
        $proposals = config('odden-core.tables.proposed_actions', 'odden_proposed_actions');
        $objects = config('odden-core.tables.custom_object_records', 'odden_custom_object_records');

        return [
            [$log, 'input', 'action_log.input'],
            [$log, 'error', 'action_log.error'],
            [$proposals, 'input', 'proposed_actions.input'],
            [$proposals, 'error', 'proposed_actions.error'],
            [config('odden-core.tables.lists', 'odden_lists'), 'criteria', 'lists.criteria'],
            [$objects, 'name', 'custom_object_records.name'],
            [$objects, 'properties', 'custom_object_records.properties'],
        ];
    }

    private static function readApplicationKey(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
};
