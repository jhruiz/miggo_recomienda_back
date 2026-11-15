<?php

namespace App\Rules;

use App\Models\Portfolio;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniquePortfolioName implements ValidationRule
{
    public function __construct(
        private readonly ?int $providerProfileId,
        private readonly ?int $ignoreId = null,
    ) {}

    /**
     * Falla si el Prestador ya tiene otro Portafolio con el mismo nombre
     * (comparación normalizada, sin distinguir mayúsculas ni espacios exteriores).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->providerProfileId === null) {
            return;
        }

        $exists = Portfolio::query()
            ->where('provider_profile_id', $this->providerProfileId)
            ->where('name_normalized', Portfolio::normalizeName($value))
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('Ya tienes un portafolio con ese nombre.');
        }
    }
}
