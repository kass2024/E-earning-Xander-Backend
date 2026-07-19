<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
        'phone',
        'country',
        'primary_goal',
        'platform_institution_id',
        'document_path',
        'document_url',
    ];

    protected $appends = [
        'name',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        // add casts here if you later add extra JSON/date columns
    ];

    protected static function booted(): void
    {
        static::saving(function (Student $student) {
            // Keep legacy DB `name` column in sync (NOT NULL, no default).
            $full = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
            $student->attributes['name'] = $full !== ''
                ? $full
                : (string) ($student->email ?? $student->attributes['email'] ?? '');
        });
    }

    public function getNameAttribute(): string
    {
        $full = trim(($this->attributes['first_name'] ?? $this->first_name ?? '') . ' ' . ($this->attributes['last_name'] ?? $this->last_name ?? ''));

        if ($full !== '') {
            return $full;
        }

        if (!empty($this->attributes['name'])) {
            return (string) $this->attributes['name'];
        }

        return (string) ($this->attributes['email'] ?? $this->email ?? '');
    }

    /**
     * Automatically hash password when setting it.
     */
    public function setPasswordAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['password'] = null;
            return;
        }
        // Avoid double-hashing: if it already looks like a bcrypt hash, keep it.
        if (is_string($value) && strlen($value) === 60 && preg_match('/^\$2y\$|^\$2a\$|^\$2b\$/', $value)) {
            $this->attributes['password'] = $value;
        } else {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    public function platformInstitution()
    {
        return $this->belongsTo(PlatformInstitution::class, 'platform_institution_id');
    }
}
