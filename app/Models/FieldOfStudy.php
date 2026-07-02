<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FieldOfStudy extends Model
{
    use HasFactory;

    protected $table = 'fields_of_study';

    protected $fillable = ['name'];

    public function programLevels()
    {
        return $this->belongsToMany(ProgramLevel::class, 'program_level_fields', 'field_id', 'program_level_id');
    }
}
