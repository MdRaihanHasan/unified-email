<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Models\MailAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MailAccount> */
class MailAccountFactory extends Factory
{
    protected $model = MailAccount::class;

    public function definition(): array
    {
        return [
            'label' => fake()->word(),
            'provider' => Provider::Graph,
            'email' => fake()->unique()->safeEmail(),
            'display_name' => fake()->name(),
            'credentials' => ['refresh_token' => 'test-token'],
            'status' => AccountStatus::Active,
            'backfill_done_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    public function gmailApi(): static
    {
        return $this->state(['provider' => Provider::GmailApi]);
    }

    public function imap(): static
    {
        return $this->state([
            'provider' => Provider::Imap,
            'credentials' => ['password' => 'app-password', 'imap_host' => 'imap.gmail.com'],
        ]);
    }
}
