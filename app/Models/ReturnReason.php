<?php

namespace App\Models;

use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class ReturnReason extends Model
{
    use HasTranslations, SerializesTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
