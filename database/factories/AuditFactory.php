<?php

namespace Database\Factories;

use App\Models\Audit;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Audit::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'team_id' => Location::factory(),
            'location_id' => Location::factory(),
            'user_id' => User::factory(),
            'found_count' => $this->faker->numberBetween(0, 50),
            'missing_count' => 0,
            'extra_count' => 0,
            'payload' => [
                'found_ids' => [],
                'extra' => [],
                'unknown_codes' => [],
            ],
        ];
    }

    /**
     * Attach the audit to an existing location (and its team) and actor.
     *
     * @param Location $location
     * @param User|null $user
     * @return AuditFactory
     */
    public function atLocation(Location $location, ?User $user = null): self
    {
        return $this->state([
            'team_id' => $location->team_id,
            'location_id' => $location->id,
            'user_id' => $user ? $user->id : User::factory(),
        ]);
    }
}
