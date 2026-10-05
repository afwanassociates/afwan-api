<?php

namespace Database\Seeders;

use App\Enums\ReferenceType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Seeder;

class PassportDataSeeder extends Seeder
{
    /**
     * Sample references: three people and two agencies.
     *
     * @var array<string, ReferenceType>
     */
    protected array $references = [
        'Abdul Karim' => ReferenceType::Person,
        'Rahima Begum' => ReferenceType::Person,
        'Jamal Hossain' => ReferenceType::Person,
        'Sea Star Overseas' => ReferenceType::Agency,
        'Green Field Recruiting Agency' => ReferenceType::Agency,
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

        $references = collect($this->references)->map(function (ReferenceType $type, string $name) use ($creator) {
            $reference = Reference::withTrashed()->firstOrNew(['type' => $type, 'name' => $name]);
            $reference->is_active = true;
            $reference->created_by ??= $creator?->id;
            $reference->save();

            return $reference;
        })->values();

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

        PassportEntry::factory()
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
