<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramLevel extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function institutions()
    {
        return $this->belongsToMany(Institution::class, 'institution_program_levels');
    }

    public function fields()
    {
        return $this->belongsToMany(FieldOfStudy::class, 'program_level_fields', 'program_level_id', 'field_id');
    }

    public function categories()
    {
        return $this->belongsToMany(ProgramLevelCategory::class, 'program_level_categories_levels', 'program_level_id', 'category_id');
    }

    public function intakes()
    {
        return $this->belongsToMany(Intake::class, 'program_level_intakes', 'program_level_id', 'intake_id');
    }
}
