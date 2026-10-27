<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Models;

use App\Domain\Analysis\Enums\FeedbackIssue;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $analysis_id
 * @property int $user_id
 * @property int $rating
 * @property FeedbackIssue|null $issue_type
 * @property string|null $comment
 */
#[Fillable(['analysis_id', 'user_id', 'rating', 'issue_type', 'comment'])]
class AnalysisFeedback extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'analysis_feedback';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'issue_type' => FeedbackIssue::class,
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
