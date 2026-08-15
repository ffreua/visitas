<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CID10 extends Model
{
    protected $table = 'cid10';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'description',
        'category',
        'chapter',
        'normalized_description',
    ];

    public function scopeSearch($query, string $term)
    {
        $normalized = Str::of($term)->ascii()->lower()->toString();

        return $query->where(function ($q) use ($term, $normalized) {
            $q->where('code', 'like', strtoupper($term).'%')
                ->orWhere('normalized_description', 'like', "%{$normalized}%");
        });
    }
}
