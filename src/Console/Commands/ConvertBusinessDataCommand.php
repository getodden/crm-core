<?php

declare(strict_types=1);

namespace Odden\Core\Console\Commands;

use Illuminate\Console\Command;
use Odden\Core\Contracts\BusinessData;
use Odden\Core\Encryption\BusinessDataConverter;

class ConvertBusinessDataCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'odden:convert-business-data {--to= : "encrypted" or "plain" (default: what ODDEN_ENCRYPT_BUSINESS_DATA says)} {--check : Only count the figures that are not in that form}';

    /**
     * @var string
     */
    protected $description = 'Store deal amounts and sales targets encrypted or plain, to match the business-data setting (safe to run again)';

    public function handle(BusinessDataConverter $converter, BusinessData $setting): int
    {
        $to = $this->option('to');

        if ($to !== null && ! in_array($to, ['encrypted', 'plain'], true)) {
            $this->components->error('--to must be "encrypted" or "plain".');

            return self::INVALID;
        }

        $encrypted = $to === null ? $setting->encrypted() : $to === 'encrypted';

        if ($this->option('check')) {
            $left = $converter->pending($encrypted);

            foreach ($left as $field => $count) {
                $this->line(sprintf('%-40s %d to convert', $field, $count));
            }

            return array_sum($left) > 0 ? self::FAILURE : self::SUCCESS;
        }

        foreach ($converter->convert($encrypted) as $field => $count) {
            $this->line(sprintf('%-40s %d converted', $field, $count));
        }

        $this->components->info($encrypted ? 'Figures are stored encrypted.' : 'Figures are stored plain.');

        return self::SUCCESS;
    }
}
