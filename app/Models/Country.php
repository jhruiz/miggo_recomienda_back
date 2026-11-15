<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'iso_code',
    ];

    /**
     * Departments belonging to this country.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
