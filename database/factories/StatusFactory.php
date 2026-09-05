<?php

namespace Database\Factories;

use App\Models\Status;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class StatusFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Status::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->word(),
            'team_id' => Team::factory(),
        ];
    }

    /**
     * Attach the status to an existing team.
     *
     * @param Team $team
     * @return StatusFactory
     */
    public function onTeam(Team $team): self
    {
        return $this->state(['team_id' => $team->id]);
    }
}
