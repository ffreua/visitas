<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HealthPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'normalized_name',
        'aliases',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (HealthPlan $plan) {
            $plan->normalized_name = Str::of($plan->name)->ascii()->lower()->toString();
        });
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeSearch($query, string $term)
    {
        $normalized = Str::of($term)->ascii()->lower()->toString();

        return $query->where('normalized_name', 'like', "%{$normalized}%");
    }
}
