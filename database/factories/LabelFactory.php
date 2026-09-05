<?php

namespace Database\Factories;

use App\Models\Label;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Label::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->word(),
            'color' => '#FF0000',
            'team_id' => Team::factory(),
        ];
    }

    /**
     * Attach the label to an existing team.
     *
     * @param Team $team
     * @return LabelFactory
     */
    public function onTeam(Team $team): self
    {
        return $this->state(['team_id' => $team->id]);
    }
}
