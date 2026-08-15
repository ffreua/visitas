<?php

namespace App\Models;

use App\Exceptions\StaleAdmissionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Admission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'patient_id',
        'admission_at',
        'hospital_discharge_at',
        'neurology_followup_started_at',
        'neurology_followup_closed_at',
        'status',
        'care_type',
        'followup_mode',
        'payer_type',
        'health_plan_id',
        'health_plan_name_snapshot',
        'origin',
        'unit',
        'bed',
        'requesting_specialty_id',
        'consult_reason',
        'consult_priority',
        'consult_requested_at',
        'first_neurology_evaluation_at',
        'brief_history',
        'discharge_outcome',
        'followup_plan_documented',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'admission_at' => 'datetime',
            'hospital_discharge_at' => 'datetime',
            'neurology_followup_started_at' => 'datetime',
            'neurology_followup_closed_at' => 'datetime',
            'consult_requested_at' => 'datetime',
            'first_neurology_evaluation_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Admission $admission) {
            $admission->uuid ??= (string) Str::uuid();
            $admission->neurology_followup_started_at ??= $admission->admission_at ?? now();
            $admission->status ??= 'ACTIVE';
            $admission->version ??= 1;
        });

        static::updating(function (Admission $admission) {
            $admission->version = $admission->getOriginal('version') + 1;
        });
    }

    /**
     * Verifica o optimistic lock antes de persistir. O controller deve chamar
     * isto com a versão que o cliente enviou (lida antes da edição).
     */
    public function assertVersion(int $expectedVersion): void
    {
        if ($this->version !== $expectedVersion) {
            throw new StaleAdmissionException;
        }
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class);
    }

    public function requestingSpecialty(): BelongsTo
    {
        return $this->belongsTo(MedicalSpecialty::class, 'requesting_specialty_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(AdmissionDiagnosis::class);
    }

    public function pendingItems(): HasMany
    {
        return $this->hasMany(PendingItem::class);
    }

    public function dailyRounds(): HasMany
    {
        return $this->hasMany(DailyRound::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'CLOSED');
    }

    public function todaysRound()
    {
        return $this->dailyRounds()->whereDate('round_date', now()->toDateString())->first();
    }

    public function isSingleEvaluation(): bool
    {
        return $this->followup_mode === 'SINGLE_EVALUATION';
    }
}
