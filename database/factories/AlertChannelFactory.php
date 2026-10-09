<?php

namespace Database\Factories;

use App\Models\AlertChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertChannel>
 */
class AlertChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => AlertChannel::TYPE_TELEGRAM,
            'settings' => ['bot_token' => '123456:SECRET-TOKEN', 'chat_id' => '-100500'],
            'enabled' => true,
        ];
    }

    public function email(array $recipients = ['ops@example.com']): static
    {
        return $this->state(['type' => AlertChannel::TYPE_EMAIL, 'settings' => ['recipients' => $recipients]]);
    }

    public function ofType(string $type): static
    {
        return $this->state(['type' => $type, 'settings' => []]);
    }
}
