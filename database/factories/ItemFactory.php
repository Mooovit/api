<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Item::class;

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
     * Attach the item to an existing team.
     *
     * @param Team $team
     * @return ItemFactory
     */
    public function onTeam(Team $team): self
    {
        return $this->state(['team_id' => $team->id]);
    }

    /**
     * Give the item a location (defaults to one in the same team when omitted).
     *
     * @param Location|null $location
     * @return ItemFactory
     */
    public function inLocation(?Location $location = null): self
    {
        return $this->state(function (array $attrs) use ($location) {
            return ['location_id' => $location ? $location->id : Location::factory()->create(['team_id' => $attrs['team_id']])->id];
        });
    }

    /**
     * Give the item a status (defaults to one in the same team when omitted).
     *
     * @param Status|null $status
     * @return ItemFactory
     */
    public function withStatus(?Status $status = null): self
    {
        return $this->state(function (array $attrs) use ($status) {
            return ['status_id' => $status ? $status->id : Status::factory()->create(['team_id' => $attrs['team_id']])->id];
        });
    }

    /**
     * Nest the item under an existing parent box.
     *
     * @param Item $parent
     * @return ItemFactory
     */
    public function childOf(Item $parent): self
    {
        return $this->state(function (array $attrs) use ($parent) {
            return [
                'parent_id' => $parent->id,
                'team_id' => $attrs['team_id'] ?? $parent->team_id,
            ];
        });
    }
}
