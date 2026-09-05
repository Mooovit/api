<?php

namespace Database\Factories;

use App\Models\History;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HistoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = History::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'item_id' => Item::factory(),
            'user_id' => User::factory(),
            'field_name' => 'name',
            'old_value' => 'Old value',
            'new_value' => 'New value',
            'changed_at' => now(),
        ];
    }

    /**
     * Attach the history row to an existing item and actor.
     *
     * @param Item $item
     * @param User|null $user
     * @return HistoryFactory
     */
    public function forItem(Item $item, ?User $user = null): self
    {
        return $this->state([
            'item_id' => $item->id,
            'user_id' => $user ? $user->id : User::factory(),
        ]);
    }
}
