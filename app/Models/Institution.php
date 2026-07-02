<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'destination_id',
        'city',
        'tuition',
        'application_fee',
        'duration',
        'can_take_loan',
        'tags',
        'success_chance',
        'success_details',
        'logo_path',
        'logo_url',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function destination()
    {
        return $this->belongsTo(Destination::class);
    }

    public function programLevels()
    {
        return $this->belongsToMany(ProgramLevel::class, 'institution_program_levels');
    }
}
