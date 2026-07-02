<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramLevelCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function programLevels()
    {
        return $this->belongsToMany(ProgramLevel::class, 'program_level_categories_levels', 'category_id', 'program_level_id');
    }
}
