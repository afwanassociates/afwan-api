<?php

namespace Database\Seeders;

use App\Enums\PartyKind;
use App\Enums\PartyType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Seeder;

class PassportDataSeeder extends Seeder
{
    /**
     * Sample references (client parties): three people and two agencies.
     *
     * @var array<string, PartyKind>
     */
    protected array $references = [
        'Abdul Karim' => PartyKind::Person,
        'Rahima Begum' => PartyKind::Person,
        'Jamal Hossain' => PartyKind::Person,
        'Sea Star Overseas' => PartyKind::Agency,
        'Green Field Recruiting Agency' => PartyKind::Agency,
    ];

    /**
     * Sample companies.
     *
     * @var list<string>
     */
    protected array $companies = [
        'Desert Rose Construction',
        'Gulf Star Catering',
        'Pearl Coast Facilities',
        'Blue Dune Logistics',
        'Crescent Hospitality',
    ];

    /**
     * Development sample data: 5 references, 5 companies and 20 passport entries.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('PassportDataSeeder creates sample passport data and will not run in production.');

            return;
        }

        $creator = User::query()->where('role', UserRole::DataEntry)->first();

        $references = collect(array_keys($this->references))->map(function (string $name, int $index) use ($creator) {
            $reference = Party::query()->firstOrNew(['type' => PartyType::Client, 'name' => $name]);
            $reference->kind = $this->references[$name];
            $reference->mobile ??= '0171100000'.$index;
            $reference->rl_number ??= $reference->kind === PartyKind::Agency ? 'RL-'.(1001 + $index) : null;
            $reference->is_active = true;
            $reference->created_by ??= $creator?->id;
            // Seeders may run without model events: number a new party here.
            if (! $reference->exists) {
                $reference->assignCode();
            }
            $reference->save();

            return $reference;
        });

        $companies = collect($this->companies)->map(function (string $name) use ($creator) {
            $company = Company::withTrashed()->firstOrNew(['name' => $name]);
            $company->is_active = true;
            $company->country_code ??= app(Settings::class)->get(Settings::DEFAULT_COMPANY_COUNTRY, 'MY');
            $company->created_by ??= $creator?->id;
            $company->save();

            return $company;
        });

        // Only add entries once, so running the seeder again does not pile them up.
        if (PassportEntry::withTrashed()->exists()) {
            $this->command?->info('Passport entries already exist; skipped.');

            return;
        }

        // No scan files exist for sample data, so the sample passports have none.
        PassportEntry::factory()
            ->withoutScan()
            ->count(20)
            ->sequence(fn ($sequence) => [
                'reference_id' => $references[$sequence->index % $references->count()]->id,
                'company_id' => $companies[$sequence->index % $companies->count()]->id,
                'created_by' => $creator?->id,
                'updated_by' => $creator?->id,
            ])
            ->create();
    }
}
