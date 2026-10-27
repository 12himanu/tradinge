<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Merolagani;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use JsonException;

/**
 * Reads the two public JSON handlers behind merolagani's CompanyDetail page instead of scraping it.
 *
 * `CompanyDetail.aspx?symbol=X` renders its price-history table only after an ASP.NET `__VIEWSTATE`
 * postback, and then paginates it. The handler that feeds the site's own TradingView chart returns
 * the whole history as column arrays in one request, so that is what this client reads. Nothing
 * here needs a session, a cookie or a token.
 *
 * Every method returns the documented shape or throws MerolaganiUnavailable. No method interprets
 * the numbers: that is MerolaganiProvider's and CompanySnapshot's job.
 */
final class MerolaganiClient
{
    private const string CHART_HANDLER = 'handlers/technicalcharthandler.ashx';

    private const string WEB_HANDLER = 'handlers/webrequesthandler.ashx';

    /** UDF datafeed keys: t=time, o/h/l/c=prices (v=volume is optional, s=status). */
    private const array SERIES_KEYS = ['t', 'o', 'h', 'l', 'c'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $retries,
        private readonly int $retryDelayMs,
        private readonly string $userAgent,
    ) {}

    /**
     * Daily OHLCV for one symbol over a closed date range, as parallel arrays.
     *
     * `$adjusted` asks for the bonus- and rights-adjusted series. NEPSE companies issue bonus
     * shares often, and an unadjusted history shows each one as a large overnight gap that no
     * indicator or pattern detector can tell apart from a real move.
     *
     * @return array{t: list<int>, o: list<float>, h: list<float>, l: list<float>, c: list<float>, v: list<float|null>}
     *                                                                                                                  empty arrays when the symbol has no bars in the range
     */
    public function dailyBars(string $symbol, CarbonImmutable $from, CarbonImmutable $to, bool $adjusted): array
    {
        $payload = $this->json(self::CHART_HANDLER, 'price history', [
            'type' => 'get_advanced_chart',
            'symbol' => $symbol,
            'resolution' => '1D',
            'rangeStartDate' => $from->getTimestamp(),
            'rangeEndDate' => $to->getTimestamp(),
            'from' => '',
            'isAdjust' => $adjusted ? 1 : 0,
        ]);

        if (! is_array($payload)) {
            throw MerolaganiUnavailable::malformed('price history', 'expected an object, got '.get_debug_type($payload));
        }

        $status = is_string($payload['s'] ?? null) ? $payload['s'] : '';
        if ($status === 'no_data') {
            return ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
        }
        if ($status !== 'ok') {
            $message = is_string($payload['errmsg'] ?? null) ? $payload['errmsg'] : "status \"{$status}\"";
            throw MerolaganiUnavailable::upstream('price history', $message);
        }

        $series = [];
        foreach (self::SERIES_KEYS as $key) {
            if (! is_array($payload[$key] ?? null)) {
                throw MerolaganiUnavailable::malformed('price history', "missing series \"{$key}\"");
            }
            $series[$key] = array_values($payload[$key]);
        }
        $series['v'] = is_array($payload['v'] ?? null) ? array_values($payload['v']) : [];

        $length = count($series['t']);
        foreach (self::SERIES_KEYS as $key) {
            if (count($series[$key]) !== $length) {
                throw MerolaganiUnavailable::malformed(
                    'price history',
                    sprintf('series "%s" has %d values for %d timestamps', $key, count($series[$key]), $length),
                );
            }
        }

        /** @var array{t: list<int>, o: list<float>, h: list<float>, l: list<float>, c: list<float>, v: list<float|null>} $series */
        return $series;
    }

    /**
     * The figures behind the summary panel of CompanyDetail.aspx: last price, 52-week range, EPS,
     * P/E, market cap, latest dividends. Null when the symbol is unknown to merolagani.
     *
     * @return array<string, mixed>|null
     */
    public function companySummary(string $symbol): ?array
    {
        $payload = $this->json(self::WEB_HANDLER, 'company summary', [
            'type' => 'get_company_summary',
            'symbol' => $symbol,
        ]);

        if ($payload === null) {
            return null;
        }
        if (! is_array($payload)) {
            throw MerolaganiUnavailable::malformed('company summary', 'expected an object, got '.get_debug_type($payload));
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Every security merolagani charts: ordinary and promoter shares, debentures, mutual funds.
     * `description` is the company name; the handler carries no sector.
     *
     * Deduplicated by symbol, because the list is not unique: HURJA is currently listed twice, once
     * with its name and once with an empty one. An entry that carries a name wins over one that
     * does not, whichever came first.
     *
     * @return list<array{symbol: string, name: string}>
     */
    public function listedSymbols(): array
    {
        $payload = $this->json(self::CHART_HANDLER, 'symbol list', [
            'type' => 'get_symbols_by_search',
            'symbol' => '',
            'exchange' => '',
            'limit' => 5000,
            'query' => '',
        ]);

        if (! is_array($payload)) {
            throw MerolaganiUnavailable::malformed('symbol list', 'expected an array, got '.get_debug_type($payload));
        }

        /** @var array<string, array{symbol: string, name: string}> $symbols */
        $symbols = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $symbol = is_string($entry['symbol'] ?? null) ? trim($entry['symbol']) : '';
            if ($symbol === '') {
                continue;
            }

            $key = mb_strtoupper($symbol);
            $name = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';

            // Already seen with a name, and this one adds nothing: keep the one with the name.
            if (isset($symbols[$key]) && ($name === '' || $symbols[$key]['name'] !== $symbols[$key]['symbol'])) {
                continue;
            }

            $symbols[$key] = ['symbol' => $symbol, 'name' => $name !== '' ? $name : $symbol];
        }

        return array_values($symbols);
    }

    /**
     * The rendered CompanyDetail page, for the few fields no JSON handler exposes (sector, shares
     * outstanding, book value). Null when it cannot be fetched: a best-effort extra that must never
     * fail a price sync, so this one swallows transport errors instead of throwing.
     */
    public function companyPage(string $symbol): ?string
    {
        try {
            $response = $this->request()->get('CompanyDetail.aspx', ['symbol' => $symbol]);
        } catch (ConnectionException|RequestException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function json(string $path, string $endpoint, array $query): mixed
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            throw MerolaganiUnavailable::transport($endpoint, $e->getMessage());
        } catch (RequestException $e) {
            throw MerolaganiUnavailable::status($endpoint, $e->response->status());
        }

        if (! $response->successful()) {
            throw MerolaganiUnavailable::status($endpoint, $response->status());
        }

        try {
            return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw MerolaganiUnavailable::malformed($endpoint, $e->getMessage());
        }
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry($this->retries, $this->retryDelayMs, throw: false)
            ->withHeaders([
                // The handlers answer anonymous requests; these headers only identify the caller.
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/json, text/javascript, */*',
                'Referer' => rtrim($this->baseUrl, '/').'/CompanyDetail.aspx',
            ]);
    }
}
