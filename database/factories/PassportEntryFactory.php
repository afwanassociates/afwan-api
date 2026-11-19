<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Party;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\RecruitmentAgent;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<PassportEntry>
 */
class PassportEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'passport_name' => fake()->name(),
            'passport_number' => fake()->unique()->bothify('??#######'),
            'country_code' => 'BD',
            'date_of_birth' => fake()->dateTimeBetween('-50 years', '-18 years'),
            'reference_id' => Party::factory(),
            'company_id' => Company::factory(),
            'passport_received_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'passport_expiry_date' => fake()->dateTimeBetween('+1 year', '+10 years'),
            // Scan details only (no file on the disk): new passports always have a
            // scan, so editing them needs no upload. Use withoutScan() for old rows.
            'scan_path' => fn () => 'passport-scans/factory/'.Str::uuid().'.jpg',
            'scan_original_name' => 'passport.jpg',
            'scan_mime' => 'image/jpeg',
            'scan_size' => 102400,
            'scan_uploaded_at' => now(),
        ];
    }

    /**
     * Configure the model factory: like the app, set the stored workflow
     * position (stage_status_date) after creating.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PassportEntry $entry) {
            app(WorkflowService::class)->recompute($entry);
        });
    }

    /**
     * The approved Own agent first (see $approvedAgentByDefault), on this factory
     * instance: the configure() callback is bound to the factory before its states.
     *
     * @param  Collection<int, Model>  $instances
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null)
    {
        if ($this->withAgent ?? static::$approvedAgentByDefault) {
            $instances->each(fn (PassportEntry $entry) => static::giveApprovedOwnAgent($entry));
        }

        parent::callAfterCreating($instances, $parent);
    }

    /**
     * The tests turn this on (tests/Pest.php): every passport gets an approved
     * Own recruitment agent, since no step moves forward without one.
     */
    public static bool $approvedAgentByDefault = false;

    /**
     * Null: follow $approvedAgentByDefault.
     */
    protected ?bool $withAgent = null;

    /**
     * Indicate that the passport has no recruitment agent.
     */
    public function withoutAgent(): static
    {
        $factory = clone $this;
        $factory->withAgent = false;

        return $factory;
    }

    /**
     * Keep withoutAgent() through the other states.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $factory = parent::newInstance($arguments);
        $factory->withAgent = $this->withAgent;

        return $factory;
    }

    public static function giveApprovedOwnAgent(PassportEntry $entry): void
    {
        $agent = RecruitmentAgent::query()->where('is_own', true)->first();
        if (! $agent) {
            $agent = new RecruitmentAgent(['name' => 'Own', 'rl_number' => 'RL-OWN-TEST']);
            $agent->is_own = true;
            $agent->is_active = true;
            $agent->assignCode();
            $agent->save();
        }

        PassportAgentAssignment::query()->create([
            'passport_entry_id' => $entry->id,
            'recruitment_agent_id' => $agent->id,
            'assigned_date' => $entry->passport_received_date ?? '2026-01-01',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    /**
     * Indicate that no scan copy was uploaded (as on passports entered
     * before scans were required).
     */
    public function withoutScan(): static
    {
        return $this->state(fn (array $attributes) => [
            'scan_path' => null,
            'scan_original_name' => null,
            'scan_mime' => null,
            'scan_size' => null,
            'scan_uploaded_at' => null,
        ]);
    }

    /**
     * Indicate that the medical slip date (MYGRAM) is entered, so the medical
     * is pending until a result is recorded.
     */
    public function withMedicalSlip(string $date = '2026-01-20'): static
    {
        return $this->state(fn (array $attributes) => [
            'medical_slip_date' => $date,
        ]);
    }

    /**
     * Indicate that the entry was created (and last updated) by the given user.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
