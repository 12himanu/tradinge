<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Analysis\Enums\FeedbackIssue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AnalysisFeedbackRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'in:-1,1'],
            'issue_type' => ['nullable', Rule::enum(FeedbackIssue::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedAttributes(): array
    {
        return [
            'rating' => $this->integer('rating'),
            'issue_type' => $this->string('issue_type')->value() ?: null,
            'comment' => $this->string('comment')->trim()->value() ?: null,
        ];
    }
}
