<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Jetstream;

class Box extends Model
{
    use HasFactory;
    use Uuids;
    protected $fillable = ['name', 'team_id'];

    public function team()
    {
        return $this->belongsTo(Jetstream::teamModel());
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function items() {
        return $this->hasMany(Item::class);
    }
}
