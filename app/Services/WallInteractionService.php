<?php

namespace App\Services;

use App\Enums\FeedEventType;
use App\Exceptions\WallItemNotAvailableException;
use App\Models\Job;
use App\Models\Portfolio;
use App\Models\User;
use App\Support\InteractionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Me gusta y guardados del Muro. Operaciones idempotentes: el contador
 * `likes_count` solo cambia cuando la fila de interacción realmente cambió.
 */
class WallInteractionService
{
    /**
     * Tablas y columnas de cada sujeto de Me gusta y Guardar.
     *
     * @var array<string, array{model: class-string<Model>, fk: string, likes: string, saves: string}>
     */
    private const SUBJECTS = [
        'portfolio' => [
            'model' => Portfolio::class,
            'fk' => 'portfolio_id',
            'likes' => 'portfolio_likes',
            'saves' => 'portfolio_saves',
        ],
        'job' => [
            'model' => Job::class,
            'fk' => 'job_id',
            'likes' => 'job_likes',
            'saves' => 'job_saves',
        ],
    ];

    public function __construct(
        private readonly WallPortfolioService $wallPortfolioService,
        private readonly WallJobService $wallJobService,
        private readonly FeedEventRecorder $feedEventRecorder,
    ) {}

    /**
     * @throws WallItemNotAvailableException
     */
    public function likePortfolio(User $user, int $portfolioId): InteractionState
    {
        return $this->mutate('portfolio', $user, $portfolioId, 'like');
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function unlikePortfolio(User $user, int $portfolioId): InteractionState
    {
        return $this->mutate('portfolio', $user, $portfolioId, 'unlike');
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function savePortfolio(User $user, int $portfolioId): InteractionState
    {
        return $this->mutate('portfolio', $user, $portfolioId, 'save');
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function unsavePortfolio(User $user, int $portfolioId): InteractionState
    {
        return $this->mutate('portfolio', $user, $portfolioId, 'unsave');
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function likeJob(User $user, int $jobId): InteractionState
    {
        return $this->mutate('job', $user, $jobId, 'like');
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function unlikeJob(User $user, int $jobId): InteractionState
    {
        return $this->mutate('job', $user, $jobId, 'unlike');
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function saveJob(User $user, int $jobId): InteractionState
    {
        return $this->mutate('job', $user, $jobId, 'save');
    }

    /**
     * @throws WallItemNotAvailableException
     */
    public function unsaveJob(User $user, int $jobId): InteractionState
    {
        return $this->mutate('job', $user, $jobId, 'unsave');
    }

    /**
     * Núcleo común: comprueba elegibilidad dentro de la transacción, cambia la fila
     * de interacción y solo si cambió ajusta el contador y registra el evento.
     *
     * @param  'portfolio'|'job'  $subject
     * @param  'like'|'unlike'|'save'|'unsave'  $action
     *
     * @throws WallItemNotAvailableException
     */
    private function mutate(string $subject, User $user, int $subjectId, string $action): InteractionState
    {
        $config = self::SUBJECTS[$subject];

        /** @var Portfolio|Job $model */
        $model = DB::transaction(function () use ($subject, $config, $user, $subjectId, $action): Model {
            $model = $subject === 'portfolio'
                ? $this->wallPortfolioService->findVisible($subjectId)
                : $this->wallJobService->findVisible($subjectId);

            $isLike = in_array($action, ['like', 'unlike'], true);
            $table = $isLike ? $config['likes'] : $config['saves'];
            $keys = ['user_id' => $user->id, $config['fk'] => $model->getKey()];

            if (in_array($action, ['like', 'save'], true)) {
                $changed = DB::table($table)->insertOrIgnore($keys + ['created_at' => now()]) === 1;
            } else {
                $changed = DB::table($table)->where($keys)->delete() === 1;
            }

            if ($changed && $isLike) {
                $query = $config['model']::query()->whereKey($model->getKey());

                if ($action === 'like') {
                    $query->increment('likes_count');
                } else {
                    $query->where('likes_count', '>', 0)->decrement('likes_count');
                }
            }

            if ($changed) {
                $this->feedEventRecorder->record($user, $model, FeedEventType::from($action));
            }

            return $model;
        });

        return new InteractionState(
            subjectId: $subjectId,
            liked: DB::table($config['likes'])->where('user_id', $user->id)->where($config['fk'], $subjectId)->exists(),
            saved: DB::table($config['saves'])->where('user_id', $user->id)->where($config['fk'], $subjectId)->exists(),
            likesCount: (int) $config['model']::query()->whereKey($model->getKey())->value('likes_count'),
            subjectType: $subject,
        );
    }
}
