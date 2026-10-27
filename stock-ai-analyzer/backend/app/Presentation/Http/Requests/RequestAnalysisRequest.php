<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Application\DTOs\RequestAnalysisData;
use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestAnalysisRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timeframe' => ['sometimes', Rule::enum(Timeframe::class)],
            'trading_style' => ['sometimes', Rule::enum(TradingStyle::class)],
            'position_status' => ['sometimes', Rule::enum(PositionStatus::class)],
            // The schema requires both: a holding position without a size cannot be marked to market.
            'position_entry_price' => ['nullable', 'numeric', 'gt:0', 'required_if:position_status,holding'],
            'position_quantity' => ['nullable', 'numeric', 'gt:0', 'required_if:position_status,holding'],
            'user_thesis' => ['nullable', 'string', 'max:2000'],
            'force' => ['sometimes', 'boolean'],
            // Attaching a ticker to an upload that has none, so a vision-only run can be redone
            // properly. Same shape as the upload rule.
            'symbol' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9._&-]*$/'],
            'exchange' => ['nullable', 'string', 'max:16', Rule::exists('exchanges', 'code')],
            // "This chart really is this symbol" — overrides the engine's chart-identity check.
            'confirm_symbol' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(ChartUpload $upload): RequestAnalysisData
    {
        $defaults = RequestAnalysisData::fromUpload($upload);
        $status = PositionStatus::tryFrom($this->string('position_status')->toString()) ?? PositionStatus::None;

        return new RequestAnalysisData(
            timeframe: Timeframe::tryFrom($this->string('timeframe')->toString()) ?? $defaults->timeframe,
            tradingStyle: TradingStyle::tryFrom($this->string('trading_style')->toString()) ?? $defaults->tradingStyle,
            positionStatus: $status,
            positionEntryPrice: $status === PositionStatus::Holding ? $this->float('position_entry_price') : null,
            positionQuantity: $this->has('position_quantity') ? $this->float('position_quantity') : null,
            userThesis: $this->string('user_thesis')->trim()->value() ?: null,
            force: $this->boolean('force'),
            symbol: $this->string('symbol')->trim()->value() ?: null,
            exchange: $this->string('exchange')->trim()->value() ?: null,
            confirmSymbol: $this->boolean('confirm_symbol'),
        );
    }
}
