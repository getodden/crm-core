<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Odden\Core\Actions\EvaluateActiveListAction;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Enums\ListType;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;

/*
 * Times the decrypt-and-filter fallback (PLAN-encryption-at-rest.md, section 4: "measure before promising"). It does nothing unless
 * ODDEN_BENCH_CONTACTS is set, so the suite stays fast:
 *
 *   ODDEN_BENCH_CONTACTS=10000,50000 vendor/bin/pest tests/Benchmark
 *
 * Add DB_CONNECTION=pgsql / mariadb (and the DB_* variables, see scripts/ci.sh) to time another database. Each size reports, in
 * seconds, on plain rows converted by `odden:encrypt-existing`: the conversion itself, an index lookup, a "contains" rule in a list
 * (decrypt and filter), sorting by name, and reading one page of the sorted ids (the contacts table decrypts, sorts the ids here, then reads a page).
 */
$sizes = array_filter(array_map('intval', explode(',', (string) getenv('ODDEN_BENCH_CONTACTS'))));

foreach ($sizes as $size) {
    it("times the decrypt fallback on {$size} contacts", function () use ($size): void {
        config(['odden-core.encryption.fallback_limit' => $size + 1]);
        $titles = ['Head of Sales', 'Engineer', 'Designer', 'Account Executive', 'Founder', 'Operations Manager', 'Support Lead'];
        $now = now()->toDateTimeString();

        foreach (array_chunk(range(1, $size), 1000) as $chunk) {
            DB::table('odden_contacts')->insert(array_map(fn (int $n): array => [
                'email' => "person{$n}@company".($n % 500).'.example', 'first_name' => 'First'.($n % 977), 'last_name' => 'Last'.($n * 7 % 3119),
                'phone' => '555'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'job_title' => $titles[$n % count($titles)], 'timezone' => 'Europe/Paris',
                'lifecycle_stage' => 'lead', 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $time = function (callable $work) use (&$seconds): mixed {
            $start = hrtime(true);
            $result = $work();
            $seconds = (hrtime(true) - $start) / 1e9;

            return $result;
        };
        $report = [];

        // Sanity: the conversion finds every row and the lookups below can be trusted.

        $time(fn () => Artisan::call('odden:encrypt-existing'));
        $report['convert'] = $seconds;

        $found = $time(fn () => Contact::query()->whereEmail('person'.intdiv($size, 2).'@company'.(intdiv($size, 2) % 500).'.example')->first());
        $report['index lookup (email)'] = $seconds;
        expect($found)->not->toBeNull();

        $time(fn () => Contact::query()->whereNameStartsWith('first12 last')->limit(25)->get());
        $report['index search (name prefix)'] = $seconds;

        $list = CrmList::create(['name' => 'b', 'entity_type' => 'contact', 'type' => ListType::Active, 'criteria' => [['property' => 'job_title', 'operator' => 'contains', 'value' => 'sales']]]);
        $members = $time(fn () => app(EvaluateActiveListAction::class)->execute($list));
        $report["list rule: job title contains ({$members} match)"] = $seconds;

        $ids = $time(fn () => app(DecryptedFilter::class)->sortedIds(Contact::query(), ['last_name', 'first_name']));
        $report['sort ids by last, first name'] = $seconds;

        $time(fn () => Contact::query()->whereIn('id', array_slice($ids, 0, 25))->get());
        $report['read one page of the sorted ids'] = $seconds;

        $time(fn () => DecryptedFilter::orderByIds(Contact::query()->whereIn('id', array_slice($ids, 0, 500)), array_slice($ids, 0, 500))->get());
        $report['ORDER BY 500 ids (pickers, small sets)'] = $seconds;

        fwrite(STDERR, "\n".DB::getDriverName()." {$size} contacts\n");

        foreach ($report as $what => $took) {
            fwrite(STDERR, sprintf("  %-48s %7.3f s\n", $what, $took));
        }

        expect(true)->toBeTrue();
    });
}
