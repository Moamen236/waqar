<?php

namespace App\Models;

use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class ExpenseCategory extends Model
{
    use HasTranslations, SerializesTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
