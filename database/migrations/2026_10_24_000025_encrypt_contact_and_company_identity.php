<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ContactLookup;

return new class extends Migration
{
    private const CONTACT_COLUMNS = ['first_name', 'last_name', 'job_title', 'email', 'phone', 'linkedin_url', 'timezone'];

    private const COMPANY_COLUMNS = ['name', 'domain', 'phone'];

    /**
     * Run the migrations.
     *
     * Who a contact or company is (names, address, phone, job title, LinkedIn address, time zone; company name, domain, phone) is
     * stored encrypted. The columns become text, because the stored form is longer than the value, and their database indexes go:
     * records are found through the search index instead (see Odden\Core\Encryption\Index\SearchIndex). The rows already stored are
     * encrypted and indexed (see odden-core.encryption.convert_on_migrate); `odden:encrypt-existing` does the same later and can be
     * run again.
     */
    public function up(): void
    {
        $contacts = config('odden-core.tables.contacts', 'odden_contacts');
        $companies = config('odden-core.tables.companies', 'odden_companies');

        $this->dropIndexes($contacts, [['email'], ['first_name', 'last_name']]);
        $this->dropIndexes($companies, [['name'], ['domain']]);

        Schema::table($contacts, function (Blueprint $table): void {
            foreach (self::CONTACT_COLUMNS as $column) {
                $table->text($column)->nullable($column !== 'email')->change();
            }
        });

        Schema::table($companies, function (Blueprint $table): void {
            foreach (self::COMPANY_COLUMNS as $column) {
                $table->text($column)->nullable($column !== 'name')->change();
            }
        });

        if (! config('odden-core.encryption.convert_on_migrate', true)) {
            return;
        }

        $converter = app(ColumnConverter::class);

        foreach (self::CONTACT_COLUMNS as $column) {
            $converter->encrypt($contacts, $column, 'contacts.'.$column, $column === 'email' ? fn (string $value): string => ContactLookup::normalizeEmail($value) : null);
        }

        foreach (self::COMPANY_COLUMNS as $column) {
            $converter->encrypt($companies, $column, 'companies.'.$column);
        }

        $index = app(SearchIndex::class);
        $index->rebuild(Contact::class);
        $index->rebuild(Company::class);
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key first, and the search index is emptied.
     */
    public function down(): void
    {
        $contacts = config('odden-core.tables.contacts', 'odden_contacts');
        $companies = config('odden-core.tables.companies', 'odden_companies');
        $converter = app(ColumnConverter::class);

        foreach (self::CONTACT_COLUMNS as $column) {
            $converter->decrypt($contacts, $column, 'contacts.'.$column);
        }

        foreach (self::COMPANY_COLUMNS as $column) {
            $converter->decrypt($companies, $column, 'companies.'.$column);
        }

        if (Schema::hasTable($index = config('odden-core.tables.search_index', 'odden_search_index'))) {
            DB::table($index)->where('field', 'like', 'contacts.%')->orWhere('field', 'like', 'companies.%')->delete();
        }

        Schema::table($contacts, function (Blueprint $table): void {
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
            $table->string('job_title')->nullable()->change();
            $table->string('email')->change();
            $table->string('phone')->nullable()->change();
            $table->string('linkedin_url')->nullable()->change();
            $table->string('timezone', 50)->nullable()->change();
        });

        Schema::table($companies, function (Blueprint $table): void {
            $table->string('name')->change();
            $table->string('domain')->nullable()->change();
            $table->string('phone')->nullable()->change();
        });

        Schema::table($contacts, function (Blueprint $table): void {
            $table->index('email');
            $table->index(['first_name', 'last_name']);
        });

        Schema::table($companies, function (Blueprint $table): void {
            $table->index('name');
            $table->index('domain');
        });
    }

    /**
     * @param  list<list<string>>  $indexes
     */
    private function dropIndexes(string $table, array $indexes): void
    {
        foreach ($indexes as $columns) {
            if (Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($columns));
            }
        }
    }
};
