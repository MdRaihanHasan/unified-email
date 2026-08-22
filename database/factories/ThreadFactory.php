<?php

namespace Database\Factories;

use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Thread> */
class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    public function definition(): array
    {
        $subject = fake()->sentence();

        return [
            'subject' => $subject,
            'subject_normalized' => Thread::normalizeSubject($subject),
            'snippet' => fake()->sentence(),
            'participants' => [],
            'first_message_at' => now()->subDay(),
            'last_message_at' => now(),
        ];
    }
}
