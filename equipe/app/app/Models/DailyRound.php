<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyRound extends Model
{
    protected $fillable = [
        'admission_id',
        'round_date',
        'assigned_physician_id',
        'assigned_by',
        'assigned_at',
        'completed_by',
        'completed_at',
        'daily_note',
    ];

    protected function casts(): array
    {
        return [
            'round_date' => 'date',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function assignedPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_physician_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
