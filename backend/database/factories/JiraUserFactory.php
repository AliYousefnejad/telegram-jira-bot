<?php

namespace Database\Factories;

use App\Models\JiraUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JiraUser> */
class JiraUserFactory extends Factory
{
    protected $model = JiraUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $username = fake()->unique()->userName();

        return [
            'display_name' => fake()->name(),
            'jira_account_id' => $username,
            'email' => $username.'@example.com',
            'telegram_admin_id' => fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'telegram_username' => $username,
        ];
    }

    public function withoutTelegramUsername(): static
    {
        return $this->state(['telegram_username' => null]);
    }

    public function withPat(string $token = 'user-pat-token'): static
    {
        return $this->state(['auth_method' => JiraUser::AUTH_PAT, 'jira_pat' => $token, 'pat_verified_at' => now()]);
    }

    public function withExpiredPat(): static
    {
        return $this->state(['auth_method' => JiraUser::AUTH_PAT, 'jira_pat' => null, 'pat_verified_at' => null]);
    }

    public function unlinked(): static
    {
        return $this->state(['telegram_admin_id' => null]);
    }
}
