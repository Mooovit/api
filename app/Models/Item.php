<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = ['name', 'team_id', 'location_id', 'status_id', 'parent_id'];

    public function childrens() {
        return $this->hasMany(Item::class, "parent_id");
    }

    public function parent() {
        return $this->belongsTo(Item::class);
    }
}
