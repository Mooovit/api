<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Location::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->city(),
            'team_id' => Team::factory(),
        ];
    }

    /**
     * Attach the location to an existing team.
     *
     * @param Team $team
     * @return LocationFactory
     */
    public function onTeam(Team $team): self
    {
        return $this->state(['team_id' => $team->id]);
    }
}
