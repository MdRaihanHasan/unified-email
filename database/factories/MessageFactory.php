<?php

namespace Database\Factories;

use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'mail_account_id' => MailAccount::factory(),
            'provider_message_id' => fake()->unique()->uuid(),
            'rfc822_message_id' => '<'.fake()->unique()->uuid().'@example.com>',
            'references_ids' => [],
            'from_addr' => ['address' => fake()->safeEmail(), 'name' => fake()->name()],
            'to_addrs' => [],
            'subject' => fake()->sentence(),
            'received_at' => now(),
        ];
    }
}
