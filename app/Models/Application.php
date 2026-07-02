<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'institution_id',
        'program_level_id',
        'field_id',
        'intake_id',
        'program_title',
        'status',
        'notes',
        'requirements_status',
        'current_stage',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function intake()
    {
        return $this->belongsTo(Intake::class);
    }
}
