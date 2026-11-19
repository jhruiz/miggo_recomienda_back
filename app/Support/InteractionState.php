<?php

namespace App\Support;

/**
 * Estado de interacción del usuario con un sujeto del Muro, leído tras la transacción.
 */
final readonly class InteractionState
{
    public function __construct(
        public int $subjectId,
        public bool $liked,
        public bool $saved,
        public int $likesCount,
        public string $subjectType,
    ) {}

    /**
     * @return array<string, int|bool|string>
     */
    public function toArray(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'liked' => $this->liked,
            'saved' => $this->saved,
            'likes_count' => $this->likesCount,
        ];
    }
}
