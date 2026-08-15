<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MedicalSpecialty extends Model
{
    protected $fillable = [
        'name',
        'normalized_name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MedicalSpecialty $specialty) {
            $specialty->normalized_name = Str::of($specialty->name)->ascii()->lower()->toString();
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
