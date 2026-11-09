<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Support\DateRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Gives a company country the built-in date rules (Settings → Date rules) for every rule it
 * does not have yet, e.g. `php artisan app:copy-date-rules-to-country MY` sets Malaysia's
 * first rules (90 days medical validity, …). Rules the country already has are kept.
 */
#[Signature('app:copy-date-rules-to-country
    {country=MY : The company country code (default MY, Malaysia)}')]
#[Description('Give a company country the default date rules it does not have yet')]
class CopyDateRulesToCountry extends Command
{
    public function handle(DateRules $rules): int
    {
        $code = strtoupper((string) $this->argument('country'));
        $name = Country::query()->where('code', $code)->value('name');

        if ($name === null) {
            $this->error("There is no country {$code}.");

            return self::FAILURE;
        }

        $copied = $rules->copyToCountry($code);

        if ($copied === []) {
            $this->info("{$name} ({$code}) already has every date rule. Nothing changed.");

            return self::SUCCESS;
        }

        $this->table(['Rule', 'Value'], collect($copied)->map(fn (int $value, string $key) => [
            $rules->definitions()[$key]['label'].' ('.$rules->definitions()[$key]['group'].')',
            $value.' '.$rules->definitions()[$key]['unit'],
        ])->values()->all());
        $this->info(count($copied)." date rule(s) set for {$name} ({$code}).");

        return self::SUCCESS;
    }
}
