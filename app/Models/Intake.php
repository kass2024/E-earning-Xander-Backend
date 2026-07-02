<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Intake extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function programLevels()
    {
        return $this->belongsToMany(ProgramLevel::class, 'program_level_intakes', 'intake_id', 'program_level_id');
    }
}
