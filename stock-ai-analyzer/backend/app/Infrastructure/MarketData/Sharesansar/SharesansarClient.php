<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Sharesansar;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use JsonException;

/**
 * Reads sharesansar's price history, which merolagani's chart handler cannot supply.
 *
 * Two things bring it here. It carries **turnover** — the traded amount, which the merolagani chart
 * handler has no field for — and it holds history for instruments merolagani does not chart at all:
 * debentures, government savings bonds and promoter shares, where `get_advanced_chart` answers
 * `no_data`.
 *
 * ## The session
 *
 * This is a Laravel site, so the history endpoint is a DataTables server-side POST guarded by CSRF.
 * A caller needs two things from an ordinary page load, and both are taken here in `session()`:
 * the `_token` meta tag and the session cookie that token is bound to. Without them the POST is
 * answered 419. One token works for every company, so a run pays for the session once.
 *
 * ## The paging
 *
 * `length` is capped at 50 server-side; anything larger comes back HTTP 202 with an empty payload
 * rather than an error, so asking for a whole history in one call silently yields nothing. Pages
 * are therefore walked 50 rows at a time, newest first, which is why a full backfill of a
 * long-listed stock is dozens of requests and a daily top-up is one.
 */
final class SharesansarClient
{
    private const string HISTORY_PATH = 'company-price-history';

    /** Server-side cap. Larger values return 202 with no rows. */
    public const int PAGE_SIZE = 50;

    private ?string $token = null;

    private ?CookieJar $cookies = null;

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
     * One page of daily history for a company, newest row first.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function priceHistory(int $companyId, int $start = 0, int $length = self::PAGE_SIZE): array
    {
        $this->session();

        try {
            $response = $this->request()
                ->asForm()
                ->withHeaders([
                    'X-CSRF-Token' => (string) $this->token,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => rtrim($this->baseUrl, '/').'/company/nabil',
                ])
                ->post(self::HISTORY_PATH, [
                    'company' => $companyId,
                    'draw' => 1,
                    'start' => $start,
                    'length' => min($length, self::PAGE_SIZE),
                ]);
        } catch (ConnectionException $e) {
            throw SharesansarUnavailable::transport('price history', $e->getMessage());
        } catch (RequestException $e) {
            throw SharesansarUnavailable::status('price history', $e->response->status());
        }

        if (! $response->successful()) {
            throw SharesansarUnavailable::status('price history', $response->status());
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SharesansarUnavailable::malformed('price history', $e->getMessage());
        }

        if (! is_array($payload)) {
            throw SharesansarUnavailable::malformed('price history', 'expected an object, got '.get_debug_type($payload));
        }

        $rows = is_array($payload['data'] ?? null) ? array_values($payload['data']) : [];

        return [
            'total' => is_int($payload['recordsTotal'] ?? null) ? $payload['recordsTotal'] : 0,
            'rows' => array_values(array_filter($rows, is_array(...))),
        ];
    }

    /**
     * The numeric id sharesansar keys a company by, read from its page.
     *
     * The history endpoint takes this id and not the symbol — passing `NABIL` returns zero rows
     * rather than an error — so it has to be looked up once per stock and is worth caching.
     * Null when the company has no page, which is how a symbol sharesansar does not carry presents.
     */
    public function companyId(string $symbol): ?int
    {
        $slug = mb_strtolower(trim($symbol));

        try {
            $response = $this->request()->get("company/{$slug}");
        } catch (ConnectionException|RequestException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        if (preg_match('#id="companyid"[^>]*>\s*(\d+)\s*<#i', $response->body(), $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * Fetches the CSRF token and the cookie it is bound to, once per client instance.
     */
    private function session(): void
    {
        if ($this->token !== null) {
            return;
        }

        $this->cookies = new CookieJar;

        try {
            $response = $this->request()->get('company/nabil');
        } catch (ConnectionException $e) {
            throw SharesansarUnavailable::noSession($e->getMessage());
        } catch (RequestException $e) {
            throw SharesansarUnavailable::noSession('HTTP '.$e->response->status());
        }

        if (! $response->successful()) {
            throw SharesansarUnavailable::noSession('HTTP '.$response->status());
        }

        if (preg_match('#<meta name="_token" content="([^"]+)"#i', $response->body(), $match) !== 1) {
            throw SharesansarUnavailable::noSession('no _token meta tag on the page');
        }

        $this->token = $match[1];
    }

    private function request(): PendingRequest
    {
        $this->cookies ??= new CookieJar;

        return $this->http
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry($this->retries, $this->retryDelayMs, throw: false)
            // The session cookie has to survive between the page load and the POST, or the token
            // it is bound to is meaningless and the endpoint answers 419.
            ->withOptions(['cookies' => $this->cookies])
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/json, text/javascript, text/html, */*',
            ]);
    }
}
