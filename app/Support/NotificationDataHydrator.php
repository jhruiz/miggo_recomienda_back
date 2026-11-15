<?php

namespace App\Support;

use App\Models\Job;
use App\Models\Notification;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Completa en lectura `actor_name` y `quote_request_title` de las
 * notificaciones antiguas que solo guardaron ids.
 *
 * Solo rellena claves ausentes (nunca altera las existentes), es idempotente y
 * no escribe en la base: el resultado vive únicamente en la respuesta.
 * Usa consultas en lote (usuarios, trabajos, cotizaciones, solicitudes).
 */
class NotificationDataHydrator
{
    private const ACTOR_KEYS = ['provider_user_id', 'client_user_id', 'rater_user_id', 'sender_user_id'];

    /**
     * @param  Collection<int, Notification>  $notifications
     * @return array<int, array<string, mixed>> `data` hidratado indexado por id de notificación
     */
    public function hydrate(Collection $notifications, int $authUserId): array
    {
        $datas = [];
        $userIds = [];
        $jobIds = [];
        $quoteIds = [];
        $requestIds = [];

        foreach ($notifications as $notification) {
            $data = is_array($notification->data) ? $notification->data : [];
            $datas[$notification->id] = $data;

            if (! $this->has($data, 'actor_name')) {
                $actorId = $this->actorId($data, $authUserId);
                if ($actorId !== null) {
                    $userIds[$actorId] = true;
                }
            }

            if (! $this->has($data, 'quote_request_title')) {
                if ($this->id($data, 'quote_request_id') !== null) {
                    $requestIds[$this->id($data, 'quote_request_id')] = true;
                } elseif ($this->id($data, 'quote_id') !== null) {
                    $quoteIds[$this->id($data, 'quote_id')] = true;
                } elseif ($this->id($data, 'job_id') !== null) {
                    $jobIds[$this->id($data, 'job_id')] = true;
                }
            }
        }

        $names = $userIds === []
            ? collect()
            : User::whereIn('id', array_keys($userIds))->pluck('name', 'id');

        // job_id -> quote_id
        $jobQuote = $jobIds === []
            ? collect()
            : Job::whereIn('id', array_keys($jobIds))->pluck('quote_id', 'id');
        foreach ($jobQuote as $quoteId) {
            if ($quoteId !== null) {
                $quoteIds[(int) $quoteId] = true;
            }
        }

        // quote_id -> quote_request_id
        $quoteRequest = $quoteIds === []
            ? collect()
            : Quote::whereIn('id', array_keys($quoteIds))->pluck('quote_request_id', 'id');
        foreach ($quoteRequest as $requestId) {
            if ($requestId !== null) {
                $requestIds[(int) $requestId] = true;
            }
        }

        $titles = $requestIds === []
            ? collect()
            : QuoteRequest::whereIn('id', array_keys($requestIds))->pluck('title', 'id');

        foreach ($datas as $id => $data) {
            if (! $this->has($data, 'actor_name')) {
                $actorId = $this->actorId($data, $authUserId);
                if ($actorId !== null && isset($names[$actorId])) {
                    $data['actor_name'] = $names[$actorId];
                }
            }

            if (! $this->has($data, 'quote_request_title')) {
                $requestId = $this->id($data, 'quote_request_id');
                if ($requestId === null) {
                    $quoteId = $this->id($data, 'quote_id');
                    if ($quoteId === null) {
                        $jobQuoteId = $this->id($data, 'job_id');
                        $quoteId = $jobQuoteId !== null && isset($jobQuote[$jobQuoteId])
                            ? (int) $jobQuote[$jobQuoteId]
                            : null;
                    }
                    $requestId = $quoteId !== null && isset($quoteRequest[$quoteId])
                        ? (int) $quoteRequest[$quoteId]
                        : null;
                }
                if ($requestId !== null && isset($titles[$requestId])) {
                    $data['quote_request_title'] = $titles[$requestId];
                }
            }

            $datas[$id] = $data;
        }

        return $datas;
    }

    /** @param array<string, mixed> $data */
    private function has(array $data, string $key): bool
    {
        return isset($data[$key]) && $data[$key] !== '';
    }

    /** @param array<string, mixed> $data */
    private function id(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<string, mixed> $data */
    private function actorId(array $data, int $authUserId): ?int
    {
        foreach (self::ACTOR_KEYS as $key) {
            $id = $this->id($data, $key);
            if ($id !== null && $id !== $authUserId) {
                return $id;
            }
        }

        return null;
    }
}
