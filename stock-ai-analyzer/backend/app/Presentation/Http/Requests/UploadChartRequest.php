<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Application\DTOs\UploadChartData;
use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Market\Services\StockResolver;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Multipart fields of POST /charts/upload (docs/05-api-spec.md §3.1). The image's size, type and
 * dimensions are checked afterwards by ChartStorage, on the actual bytes.
 */
final class UploadChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['symbol', 'exchange'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([$key => StockResolver::normalizeSymbol($value)]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file'],
            'symbol' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9._&-]*$/'],
            'exchange' => ['nullable', 'string', 'max:16', Rule::exists('exchanges', 'code')],
            'timeframe' => ['nullable', Rule::enum(Timeframe::class)],
            'trading_style' => ['required', Rule::enum(TradingStyle::class)],
            'position_status' => ['nullable', Rule::enum(PositionStatus::class)],
            'position_entry_price' => ['exclude_unless:position_status,holding', 'required', 'numeric', 'gt:0', 'max:9999999999'],
            'position_quantity' => ['exclude_unless:position_status,holding', 'required', 'numeric', 'gt:0', 'max:9999999999'],
            'thesis' => ['nullable', 'string', 'max:2000'],
            'auto_analyze' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.uploaded' => 'The image could not be uploaded. Screenshots must be 10 MB or smaller.',
            'symbol.regex' => 'Use the ticker symbol only, for example NABIL.',
            'exchange.exists' => 'Unknown exchange.',
            'position_entry_price.required' => 'Enter your entry price, since you are holding this stock.',
            'position_quantity.required' => 'Enter how many shares you hold.',
        ];
    }

    public function toData(): UploadChartData
    {
        $image = $this->file('image');

        if (! $image instanceof UploadedFile) {
            throw new LogicException('A validated upload request has no image file.');
        }

        $holding = $this->enum('position_status', PositionStatus::class) === PositionStatus::Holding;

        return new UploadChartData(
            image: $image,
            originalFilename: $this->originalFilename($image),
            tradingStyle: $this->enum('trading_style', TradingStyle::class)
                ?? throw new LogicException('A validated upload request has no trading style.'),
            symbol: $this->optionalString('symbol'),
            exchange: $this->optionalString('exchange'),
            timeframe: $this->enum('timeframe', Timeframe::class),
            positionStatus: $holding ? PositionStatus::Holding : PositionStatus::None,
            positionEntryPrice: $holding ? $this->optionalString('position_entry_price') : null,
            positionQuantity: $holding ? $this->optionalString('position_quantity') : null,
            thesis: $this->optionalString('thesis'),
            autoAnalyze: $this->boolean('auto_analyze'),
        );
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->string($key)->trim()->toString();

        return $value === '' ? null : $value;
    }

    private function originalFilename(UploadedFile $image): ?string
    {
        $name = mb_substr(basename($image->getClientOriginalName()), 0, 255);

        return $name === '' ? null : $name;
    }
}
