<?php

namespace App\Models;

use App\Exceptions\ConfirmedMedicalRecordException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_record_number',
        'medical_record_confirmed_at',
        'full_name',
        'date_of_birth',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'medical_record_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Patient $patient) {
            $patient->uuid ??= (string) Str::uuid();
            $patient->medical_record_number = static::normalizeMedicalRecordNumber($patient->medical_record_number);

            if ($patient->medical_record_number !== null) {
                $patient->medical_record_confirmed_at ??= now();
            }
        });

        static::updating(function (Patient $patient) {
            if (! $patient->isDirty('medical_record_number')) {
                return;
            }

            // Prontuário gravado é imutável — é a chave de indexação dos
            // dados de gestão, e trocá-la faria episódios antigos migrarem
            // de paciente silenciosamente. A checagem olha o valor
            // ORIGINAL: só quem estava sem prontuário pode receber um.
            if ($patient->getOriginal('medical_record_number') !== null) {
                throw new ConfirmedMedicalRecordException;
            }

            $patient->medical_record_number = static::normalizeMedicalRecordNumber($patient->medical_record_number);

            if ($patient->medical_record_number !== null) {
                $patient->medical_record_confirmed_at = now();
            }
        });
    }

    public static function normalizeMedicalRecordNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::of($value)->trim()->upper()->replaceMatches('/\s+/', '')->toString();

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Prontuário gravado = confirmado = imutável. Enquanto for null, o
     * paciente foi cadastrado pelo número de atendimento e ainda espera o
     * prontuário — o único momento em que o campo aceita escrita.
     * (medical_record_confirmed_at guarda QUANDO isso aconteceu.)
     */
    public function hasConfirmedMedicalRecord(): bool
    {
        return $this->medical_record_number !== null;
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
