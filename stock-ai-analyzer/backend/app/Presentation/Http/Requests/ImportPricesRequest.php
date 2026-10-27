<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Shared\Enums\Timeframe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

final class ImportPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt', 'extensions:csv,txt'],
            'timeframe' => ['required', Rule::enum(Timeframe::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload a CSV file (comma, semicolon or tab separated).',
            'file.extensions' => 'Upload a .csv or .txt file.',
            'file.max' => 'The CSV must be 10 MB or smaller.',
        ];
    }

    public function timeframe(): Timeframe
    {
        return $this->enum('timeframe', Timeframe::class)
            ?? throw new LogicException('A validated import request has no timeframe.');
    }

    public function csvPath(): string
    {
        $file = $this->file('file');
        $path = $file instanceof UploadedFile ? $file->getRealPath() : false;

        return $path !== false ? $path : throw new LogicException('A validated import request has no readable file.');
    }
}
