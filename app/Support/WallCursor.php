<?php

namespace App\Support;

use App\Exceptions\InvalidWallCursorException;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * Cursor firmado para paginar el Muro y el historial de eventos.
 *
 * Formato: base64url(json) . '.' . base64url(hmac_sha256(base64url(json))).
 * Carga útil: {"v":1,"k":"events|jobs|portfolios|saved_jobs|saved_portfolios","t":"Y-m-d H:i:s","i":id}.
 */
final readonly class WallCursor
{
    public const KIND_EVENTS = 'events';

    public const KIND_JOBS = 'jobs';

    public const KIND_PORTFOLIOS = 'portfolios';

    public const KIND_SAVED_JOBS = 'saved_jobs';

    public const KIND_SAVED_PORTFOLIOS = 'saved_portfolios';

    private const VERSION = 1;

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        public string $kind,
        public CarbonImmutable $sortedAt,
        public int $id,
    ) {}

    public function encode(): string
    {
        $json = json_encode([
            'v' => self::VERSION,
            'k' => $this->kind,
            't' => $this->sortedAt->format(self::DATE_FORMAT),
            'i' => $this->id,
        ], JSON_THROW_ON_ERROR);

        $payload = self::base64UrlEncode($json);

        return $payload.'.'.self::base64UrlEncode(self::sign($payload));
    }

    /**
     * @throws InvalidWallCursorException
     */
    public static function decode(string $raw, string $expectedKind): self
    {
        $parts = explode('.', $raw);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidWallCursorException;
        }

        [$payload, $signature] = $parts;

        $json = self::base64UrlDecode($payload);
        $givenSignature = self::base64UrlDecode($signature);

        if ($json === null || $givenSignature === null) {
            throw new InvalidWallCursorException;
        }

        if (! hash_equals(self::sign($payload), $givenSignature)) {
            throw new InvalidWallCursorException;
        }

        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidWallCursorException;
        }

        if (! is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || ! is_string($data['k'] ?? null)
            || $data['k'] !== $expectedKind
            || ! is_string($data['t'] ?? null)
            || ! is_int($data['i'] ?? null)
            || $data['i'] < 0
        ) {
            throw new InvalidWallCursorException;
        }

        $sortedAt = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $data['t']);

        if ($sortedAt === false || $sortedAt->format(self::DATE_FORMAT) !== $data['t']) {
            throw new InvalidWallCursorException;
        }

        return new self($data['k'], $sortedAt, $data['i']);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'), true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
