<?php

namespace App\Http\Controllers;

use App\Enums\FeedEventType;
use App\Enums\FeedSubjectType;
use App\Http\Requests\StoreWallEventsRequest;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\HasApiResponses;
use App\Models\Job;
use App\Models\Portfolio;
use App\Services\FeedEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Eventos de comportamiento del Muro (POST /api/v1/wall/events). Ruta bajo `auth:sanctum`.
 *
 * Convención de `data.accepted`: número de eventos del lote que superan la validación
 * (tipo permitido y sujeto visible en el Muro), con independencia de la preferencia de
 * registro. Con el registro desactivado o sin responder el recorder los descarta sin
 * error, de modo que la respuesta no revela el estado de la preferencia.
 *
 * La elegibilidad se comprueba con una consulta por tipo de sujeto presente en el lote.
 */
class WallEventController extends Controller
{
    use HasApiResponses;

    public function __construct(private readonly FeedEventRecorder $recorder) {}

    public function store(StoreWallEventsRequest $request): JsonResponse
    {
        /** @var list<array{subject_type:string,subject_id:int|string,type:string,occurred_at?:string|null}> $events */
        $events = array_values($request->validated('events'));

        $idsBySubjectType = [];

        foreach ($events as $event) {
            $idsBySubjectType[$event['subject_type']][(int) $event['subject_id']] = true;
        }

        $eligible = [];

        foreach ($idsBySubjectType as $subjectType => $ids) {
            $eligible[$subjectType] = $this->eligibleIds(FeedSubjectType::from($subjectType), array_keys($ids));
        }

        $valid = [];
        $errors = [];

        foreach ($events as $index => $event) {
            $subjectId = (int) $event['subject_id'];

            if (! isset($eligible[$event['subject_type']][$subjectId])) {
                $errors["events.{$index}.subject_id"] = ['El elemento no existe o no está disponible.'];

                continue;
            }

            $valid[] = [
                'subject_type' => $event['subject_type'],
                'subject_id' => $subjectId,
                'type' => FeedEventType::from($event['type']),
                'occurred_at' => empty($event['occurred_at']) ? now() : Carbon::parse($event['occurred_at']),
            ];
        }

        $this->recorder->recordMany($request->user(), $valid);

        $accepted = count($valid);

        if ($errors !== []) {
            return ApiResponse::error(
                message: 'Algunos eventos no son válidos.',
                errors: $errors,
                status: 422,
                data: ['accepted' => $accepted],
            );
        }

        return ApiResponse::success(['accepted' => $accepted], 'Eventos recibidos.', 202);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function eligibleIds(FeedSubjectType $subjectType, array $ids): array
    {
        $query = match ($subjectType) {
            FeedSubjectType::Portfolio => Portfolio::query(),
            FeedSubjectType::Job => Job::query(),
        };

        $key = $query->getModel()->getQualifiedKeyName();

        return $query->visibleOnWall()
            ->whereIn($key, $ids)
            ->pluck($key)
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();
    }
}
