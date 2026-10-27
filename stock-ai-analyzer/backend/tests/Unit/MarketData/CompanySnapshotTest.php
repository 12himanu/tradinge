<?php

declare(strict_types=1);

use App\Infrastructure\MarketData\Merolagani\CompanySnapshot;
use Carbon\CarbonImmutable;

/**
 * @return array<string, string>
 */
function merolaganiSummaryPayload(): array
{
    // Recorded from GET /handlers/webrequesthandler.ashx?type=get_company_summary&symbol=PCBL.
    return [
        'symbol' => 'PCBL',
        'name' => 'Prime Commercial Bank Ltd.',
        'tradeDate' => '09/18/2026 02:59:48',
        'ltp' => '259',
        'percentChange' => '2.86',
        'fiftyTwoHigh' => '275.9',
        'fiftyTwoLow' => '225',
        'oneEightyAvg' => '241.35',
        'oneYearYield' => '10.68',
        'eps' => '19.63',
        'epsFY' => '082-083',
        'epsQuarter' => '4',
        'peRatio' => '13.19',
        'percentCash' => '0.421',
        'cashFY' => '081-082',
        'percentBonus' => '8',
        'bonusFY' => '081-082',
        'rightShare' => '',
        'rightFY' => '',
        'marketCap' => '54272884792.0700',
    ];
}

/**
 * The shape CompanyDetail.aspx renders its summary table in: the label is wrapped in a `<span>`,
 * and the row for a field that sits beside a nested table picks that table's text up in front of it.
 */
function merolaganiPageHtml(): string
{
    return <<<'HTML'
        <table class="table">
          <tr>
            <th><span title="Sector Name">Sector</span></th>
            <td>Commercial Banks</td>
          </tr>
          <tr>
            <th><span title="Listed Shares">Shares Outstanding</span></th>
            <td>209,547,817.73</td>
          </tr>
          <tr>
            <th>120 Day Average</th>
            <td>239.38</td>
          </tr>
          <tr>
            <th>Book Value</th>
            <td>175.80</td>
          </tr>
          <tr>
            <th>PBV</th>
            <td>1.47</td>
          </tr>
          <tr>
            <th>Paidup Value</th>
            <td>100.00</td>
          </tr>
          <tr>
            <th>
              <table><tr><th>#</th><td>Value</td></tr></table>
              30-Day Avg Volume
            </th>
            <td>66,834.00</td>
          </tr>
        </table>
        HTML;
}

it('normalizes the JSON summary', function () {
    $snapshot = CompanySnapshot::from(
        merolaganiSummaryPayload(),
        null,
        new CarbonImmutable('2026-09-19T11:30:00Z'),
    );

    expect($snapshot->name)->toBe('Prime Commercial Bank Ltd.')
        ->and($snapshot->sector)->toBeNull()
        ->and($snapshot->values)->toBe([
            'fetched_at' => '2026-09-19T11:30:00Z',
            'trade_date' => '2026-09-18',
            'last_price' => 259.0,
            'percent_change' => 2.86,
            'fifty_two_week_high' => 275.9,
            'fifty_two_week_low' => 225.0,
            'one_eighty_day_average' => 241.35,
            'one_year_yield_pct' => 10.68,
            'eps' => 19.63,
            'eps_fiscal_year' => '082-083',
            'eps_quarter' => '4',
            'pe_ratio' => 13.19,
            'market_cap' => 54272884792.07,
            'cash_dividend_pct' => 0.421,
            'bonus_dividend_pct' => 8.0,
            'dividend_fiscal_year' => '081-082',
        ]);
});

it('takes the date from tradeDate and drops the clock beside it', function () {
    // 02:59:48 is not when PCBL last traded; NEPSE closes at 15:00. Only the date is trustworthy.
    $snapshot = CompanySnapshot::from(
        ['tradeDate' => '09/18/2026 02:59:48'],
        null,
        new CarbonImmutable('2026-09-19T11:30:00Z'),
    );

    expect($snapshot->values['trade_date'])->toBe('2026-09-18');
});

it('reads sector and the figures only the rendered page carries', function () {
    $snapshot = CompanySnapshot::from(
        merolaganiSummaryPayload(),
        merolaganiPageHtml(),
        new CarbonImmutable('2026-09-19T11:30:00Z'),
    );

    expect($snapshot->sector)->toBe('Commercial Banks')
        ->and($snapshot->values)->toMatchArray([
            'shares_outstanding' => 209547817.73,
            'book_value' => 175.8,
            'pbv' => 1.47,
            'one_twenty_day_average' => 239.38,
            'paidup_value' => 100.0,
            // The label carried a nested table's text in front of it.
            'thirty_day_average_volume' => 66834.0,
        ]);
});

it('leaves out a field the vendor sent empty rather than storing a zero', function () {
    $snapshot = CompanySnapshot::from(merolaganiSummaryPayload(), null, CarbonImmutable::now());

    expect($snapshot->values)->not->toHaveKey('right_share')
        ->and($snapshot->values)->not->toHaveKey('right_share_fiscal_year');
});

it('survives a page it cannot read', function () {
    $snapshot = CompanySnapshot::from(
        merolaganiSummaryPayload(),
        '<html><body><p>Under maintenance</p></body></html>',
        new CarbonImmutable('2026-09-19T11:30:00Z'),
    );

    expect($snapshot->sector)->toBeNull()
        ->and($snapshot->values['last_price'])->toBe(259.0)
        ->and($snapshot->values)->not->toHaveKey('book_value');
});

it('builds nothing but the timestamp from an absent summary', function () {
    $snapshot = CompanySnapshot::from(null, null, new CarbonImmutable('2026-09-19T11:30:00Z'));

    expect($snapshot->values)->toBe(['fetched_at' => '2026-09-19T11:30:00Z'])
        ->and($snapshot->name)->toBeNull();
});

it('merges under its own key without disturbing the rest of the metadata', function () {
    $snapshot = CompanySnapshot::from(merolaganiSummaryPayload(), null, CarbonImmutable::now());

    $merged = $snapshot->mergeInto(['notes' => 'kept', 'merolagani' => ['last_price' => 1.0]]);

    expect($merged['notes'])->toBe('kept')
        ->and($merged['merolagani']['last_price'])->toBe(259.0);
});
