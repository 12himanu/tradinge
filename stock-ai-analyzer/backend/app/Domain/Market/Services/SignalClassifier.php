<?php

declare(strict_types=1);

namespace App\Domain\Market\Services;

use App\Domain\Market\ValueObjects\ScanSignal;

/**
 * Sorts the engine's structural findings into what helps and what hurts.
 *
 * The engine reports facts: a sweep happened, a gap is unfilled, price sits in the top third of its
 * range. It does not say whether a fact is good news, because that depends on which way you are
 * leaning — an unfilled bullish gap below price is somewhere to buy if you are long and somewhere
 * you will be dragged back to if you are short.
 *
 * So this reads each finding against the prevailing bias and labels it:
 *
 *  - **trap** — a reason the obvious read is the wrong one. Liquidity just taken, stops resting in
 *    plain sight, structure flipping underneath, or the plain fact of buying at the top of a range.
 *  - **opportunity** — an unfilled gap or an untested block in the direction of the bias, a break
 *    of structure confirming it, or price sitting in the cheap third of its range.
 *  - **context** — real, worth seeing, but neither.
 *
 * None of this invents a number. Every signal carries the engine's own confidence and the prices
 * that define the zone, so the label is a heading over evidence rather than a claim of its own.
 */
final class SignalClassifier
{
    public const string TRAP = 'trap';

    public const string OPPORTUNITY = 'opportunity';

    public const string CONTEXT = 'context';

    /**
     * Price this far into its range (0 = range low, 1 = range high) is dear enough that buying it
     * is itself a caution. Mirrored below for shorts. Matches the engine's own premium/discount
     * thresholds in config/patterns.yaml.
     */
    private const float PREMIUM_AT = 0.62;

    private const float DISCOUNT_AT = 0.38;

    /**
     * @param  list<array<string, mixed>>  $events  SMC events from the engine result
     * @param  list<array<string, mixed>>  $patterns  classic and candlestick patterns
     * @param  array<string, mixed>|null  $dealingRange
     * @return list<ScanSignal>
     */
    public function classify(
        array $events,
        array $patterns,
        ?array $dealingRange,
        ?string $bias,
        ?string $structure,
    ): array {
        $signals = [];

        foreach ([...$events, ...$patterns] as $finding) {
            $signal = $this->fromFinding($finding, $bias, $structure);
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }

        $signals = $this->latestPerStructureEvent($signals);

        $range = $this->fromRange($dealingRange, $bias);
        if ($range !== null) {
            $signals[] = $range;
        }

        // Traps first: the thing that can cost money should not be below the fold.
        usort($signals, static function (ScanSignal $a, ScanSignal $b): int {
            $order = [self::TRAP => 0, self::OPPORTUNITY => 1, self::CONTEXT => 2];

            return [$order[$a->kind], -$a->confidence] <=> [$order[$b->kind], -$b->confidence];
        });

        return $signals;
    }

    /**
     * Collapses repeated breaks and changes of structure to the most recent one per direction.
     *
     * A zone is a place — two unfilled gaps at different prices are two separate things to watch.
     * A break of structure is a moment, and the engine reports the last several of them. Only the
     * newest describes where the market stands now; the ones before it are history, and listing
     * them all makes a calm chart look like it is screaming.
     *
     * @param  list<ScanSignal>  $signals
     * @return list<ScanSignal>
     */
    private function latestPerStructureEvent(array $signals): array
    {
        $pointInTime = ['bos', 'choch'];
        $newest = [];
        $kept = [];

        foreach ($signals as $signal) {
            if (! in_array($signal->type, $pointInTime, true)) {
                $kept[] = $signal;

                continue;
            }

            $key = $signal->type.'|'.$signal->direction;
            $seen = $newest[$key] ?? null;
            // No timestamp sorts oldest, so a dated event always wins over an undated one.
            if ($seen === null || ($signal->at ?? '') >= ($seen->at ?? '')) {
                $newest[$key] = $signal;
            }
        }

        return [...$kept, ...array_values($newest)];
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function fromFinding(array $finding, ?string $bias, ?string $structure): ?ScanSignal
    {
        $type = is_string($finding['type'] ?? null) ? $finding['type'] : null;
        $direction = is_string($finding['direction'] ?? null) ? $finding['direction'] : 'neutral';
        $status = is_string($finding['status'] ?? null) ? $finding['status'] : '';
        if ($type === null) {
            return null;
        }

        $agrees = $bias !== null && $direction === $bias;
        $opposes = $bias !== null && $direction !== 'neutral' && $direction !== $bias;

        [$kind, $headline] = match ($type) {
            // Liquidity was taken and price reversed. Whoever bought the break was the liquidity.
            'liquidity_sweep' => [
                self::TRAP,
                $direction === 'bullish'
                    ? 'Sellers were flushed below support before price turned up'
                    : 'Buyers were flushed above resistance before price turned down',
            ],
            // Stops are stacked at an obvious level, which is exactly why price tends to go there.
            'equal_highs' => [self::TRAP, 'Matching highs leave stops resting above; an easy target'],
            'equal_lows' => [self::TRAP, 'Matching lows leave stops resting below; an easy target'],
            // A change of character against the bias is the market disagreeing with the read.
            'choch' => $opposes
                ? [self::TRAP, 'Structure just changed against the prevailing bias']
                : [self::CONTEXT, 'Structure changed in line with the bias'],
            'bos' => $agrees
                ? [self::OPPORTUNITY, 'Structure broke in the direction of the bias']
                : [self::CONTEXT, 'Structure broke against the bias'],
            // An unfilled gap is unfinished business; price returns to it more often than not.
            'fvg' => $status === 'unmitigated'
                ? [$agrees ? self::OPPORTUNITY : self::TRAP, $agrees
                    ? 'Unfilled gap in the direction of the bias; a place to buy the return'
                    : 'Unfilled gap against the bias; price may be pulled back into it']
                : [self::CONTEXT, 'Gap already filled'],
            'order_block' => $status === 'invalidated'
                ? [self::CONTEXT, 'Order block no longer holding']
                : [$agrees ? self::OPPORTUNITY : self::TRAP, $agrees
                    ? 'Untested block in the direction of the bias'
                    : 'Block overhead, where the move is likely to stall'],
            default => [$this->patternKind($direction, $bias, $structure), $this->patternHeadline($type, $direction)],
        };

        return new ScanSignal(
            kind: $kind,
            category: is_string($finding['category'] ?? null) ? $finding['category'] : 'smc',
            type: $type,
            direction: $direction,
            status: $status,
            headline: $headline,
            confidence: $this->confidence($finding),
            priceLow: $this->price($finding['price_low'] ?? null),
            priceHigh: $this->price($finding['price_high'] ?? null),
            at: is_string($finding['start_time'] ?? null) ? $finding['start_time'] : null,
        );
    }

    /**
     * A pattern pointing the same way as both bias and structure is a reason to act; one pointing
     * against them is a reason to doubt the read. Everything else is just context.
     */
    private function patternKind(string $direction, ?string $bias, ?string $structure): string
    {
        if ($direction === 'neutral' || $bias === null) {
            return self::CONTEXT;
        }
        if ($direction === $bias && $direction === $structure) {
            return self::OPPORTUNITY;
        }

        return $direction === $bias ? self::CONTEXT : self::TRAP;
    }

    private function patternHeadline(string $type, string $direction): string
    {
        $name = ucfirst(str_replace('_', ' ', $type));

        return match ($direction) {
            'bullish' => "{$name}, pointing up",
            'bearish' => "{$name}, pointing down",
            default => $name,
        };
    }

    /**
     * @param  array<string, mixed>|null  $dealingRange
     */
    private function fromRange(?array $dealingRange, ?string $bias): ?ScanSignal
    {
        $zone = is_string($dealingRange['zone'] ?? null) ? $dealingRange['zone'] : null;
        $position = $this->price($dealingRange['position'] ?? null);
        if ($zone === null || $position === null) {
            return null;
        }

        $low = $this->price($dealingRange['low'] ?? null);
        $high = $this->price($dealingRange['high'] ?? null);
        $pct = round($position * 100);

        // Buying near the high of the range, or selling near its low, is the most ordinary way to
        // be right about direction and still lose money on entry.
        [$kind, $headline] = match (true) {
            $zone === 'premium' && $bias === 'bullish' => [
                self::TRAP,
                "Price is {$pct}% up its range: bullish, but this is the expensive end",
            ],
            $zone === 'discount' && $bias === 'bearish' => [
                self::TRAP,
                "Price is {$pct}% up its range: bearish, but most of the fall has happened",
            ],
            $zone === 'discount' && $bias === 'bullish' => [
                self::OPPORTUNITY,
                "Price is {$pct}% up its range: the cheap end, with the bias up",
            ],
            $zone === 'premium' && $bias === 'bearish' => [
                self::OPPORTUNITY,
                "Price is {$pct}% up its range: the expensive end, with the bias down",
            ],
            default => [self::CONTEXT, "Price is {$pct}% up its range"],
        };

        return new ScanSignal(
            kind: $kind,
            category: 'range',
            type: 'dealing_range',
            direction: $bias ?? 'neutral',
            status: $zone,
            headline: $headline,
            confidence: $this->rangeConfidence($position),
            priceLow: $low,
            priceHigh: $high,
            at: null,
        );
    }

    /**
     * The further into an extreme, the more the location matters; mid-range says little.
     */
    private function rangeConfidence(float $position): float
    {
        $distance = match (true) {
            $position >= self::PREMIUM_AT => ($position - self::PREMIUM_AT) / max(1 - self::PREMIUM_AT, 0.01),
            $position <= self::DISCOUNT_AT => (self::DISCOUNT_AT - $position) / max(self::DISCOUNT_AT, 0.01),
            default => 0.0,
        };

        return round(min(1.0, max(0.0, $distance)), 3);
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function confidence(array $finding): float
    {
        $value = $finding['confidence'] ?? null;

        return is_int($value) || is_float($value) ? round(min(1.0, max(0.0, (float) $value)), 3) : 0.5;
    }

    private function price(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
