<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionDiagnosis extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admission_id',
        'phase',
        'cid_code',
        'description_snapshot',
        'is_primary',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AdmissionDiagnosis $diagnosis) {
            $diagnosis->created_at ??= now();
        });
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function cid(): BelongsTo
    {
        return $this->belongsTo(CID10::class, 'cid_code', 'code');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
