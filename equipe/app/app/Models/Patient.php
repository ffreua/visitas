<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_record_number',
        'full_name',
        'date_of_birth',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Patient $patient) {
            $patient->uuid ??= (string) Str::uuid();
            $patient->medical_record_number = static::normalizeMedicalRecordNumber($patient->medical_record_number);
        });

        static::updating(function (Patient $patient) {
            if ($patient->isDirty('medical_record_number')) {
                $patient->medical_record_number = static::normalizeMedicalRecordNumber($patient->medical_record_number);
            }
        });
    }

    public static function normalizeMedicalRecordNumber(string $value): string
    {
        return Str::of($value)->trim()->upper()->replaceMatches('/\s+/', '')->toString();
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function activeAdmission(): ?Admission
    {
        return $this->admissions()->where('status', 'ACTIVE')->first();
    }
}
