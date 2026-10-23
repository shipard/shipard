<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

use Shipard\Core\Config\ServerConfig;

/**
 * HTTP client for the Shipard persons registry — a combined ARES + RPO
 * + VAT-registry service exposed at `data.shipard.org/persons` (default,
 * overridable via `server.json` key `registry.persons.baseUrl`).
 *
 * Two endpoints, both via GET:
 *
 *   ─ Search: `{baseUrl}?q={query}&showAs=json&formatMode=ns`
 *     Returns a list of summary rows for picker UI. See
 *     {@see SearchResultRow}.
 *
 *   ─ Fetch:  `{baseUrl}/{country}/{companyId}/json?formatMode=ns`
 *     Returns one canonical `shpd.persons.person.v1` payload, ready to
 *     hand to `PersonApplier::apply()`. The registry-side `formatMode=ns`
 *     parameter is what triggers the conversion to canonical (legacy
 *     mode without the parameter returns the old export shape).
 *
 * The client is intentionally thin — no caching, no retry, no async.
 * Add those when a concrete need shows up.
 *
 * Error mapping:
 *
 *   | HTTP / body                              | Exception                          |
 *   |------------------------------------------|------------------------------------|
 *   | Network failure / timeout / cURL error   | RegistryUnavailableException       |
 *   | HTTP 5xx                                 | RegistryUnavailableException       |
 *   | HTTP 404                                 | RegistryNotFoundException          |
 *   | HTTP 200 + body `{"status": 0, ...}`     | RegistryNotFoundException (fetch)  |
 *   | HTTP 200 + `{"status": 0, "errors": []}` | empty result (search — not found)  |
 *   | HTTP 200 + `{"status": 0, "errors": […]}`| RegistryInvalidResponseException (search — rejected query) |
 *   | HTTP 200 + body is non-JSON              | RegistryInvalidResponseException   |
 *   | HTTP 200 + body missing required keys    | RegistryInvalidResponseException   |
 *   | HTTP 4xx (other)                         | RegistryInvalidResponseException   |
 *
 * Testing: subclasses can override {@see performHttpGet()} to provide a
 * stub HTTP transport without making real network calls.
 */
class PersonsRegistryClient
{
    /** Default; overridable via `server.json: registry.persons.baseUrl`. */
    public const DEFAULT_BASE_URL = 'https://data.shipard.org/persons';

    private const DEFAULT_TIMEOUT_SECONDS = 10;
    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;
    private const USER_AGENT = 'Shipard/registry-client';

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        if ($baseUrl === '' || !preg_match('#^https?://#', $baseUrl)) {
            throw new \InvalidArgumentException(
                "PersonsRegistryClient: baseUrl must be a non-empty http(s) URL, got '{$baseUrl}'",
            );
        }
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException(
                "PersonsRegistryClient: timeoutSeconds must be >= 1, got {$timeoutSeconds}",
            );
        }
    }

    public static function fromServerConfig(ServerConfig $config): self
    {
        return new self($config->getRegistryPersonsBaseUrl());
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Search the registry by free-text query. Matches against full name
     * and registered IDs; results are sorted by relevance + validity by
     * the registry. Capped at ~20 rows server-side.
     *
     * Empty query returns an empty array without an HTTP call.
     *
     * @return list<SearchResultRow>
     * @throws RegistryUnavailableException
     * @throws RegistryInvalidResponseException
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $url = $this->baseUrl . '?' . http_build_query([
            'q'          => $query,
            'showAs'     => 'json',
            'formatMode' => 'ns',
        ]);

        $data = $this->fetchJson($url, "search:{$query}");

        // "Not found" is `{"status": 0, "errors": []}` (HTTP 200) — verified
        // against data.shipard.org on 2026-10-23 for unknown IČO, text
        // without a match and IČO never assigned; that is an empty result,
        // not an error (#103 D100, walkthrough finding N3). `status: 0`
        // with a non-empty `errors` list is a rejected query and stays an
        // invalid response — the client must not silently produce empty
        // results when the registry signalled an error.
        if (($data['status'] ?? null) === 0) {
            $errors = $data['errors'] ?? null;
            if (!is_array($errors) || $errors === []) {
                return [];
            }
            throw new RegistryInvalidResponseException(
                "Registry search rejected query '{$query}': "
                . $this->summarizeErrors($data),
            );
        }

        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            throw new RegistryInvalidResponseException(
                "Registry search response missing 'results' array for query '{$query}'.",
            );
        }

        $out = [];
        foreach ($results as $row) {
            if (!is_array($row)) continue;
            $parsed = SearchResultRow::fromRegistryResponse($row);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }
        return $out;
    }

    /**
     * Fetch one person as a canonical `shpd.persons.person.v1` payload.
     * The result is ready to pass to
     * `PersonApplier::apply()` (modulo `applyOptions`, which the caller
     * sets according to its own merge policy).
     *
     * `country` is normalized to lowercase ISO 3166-1 alpha-2.
     *
     * @return array<string, mixed>  Canonical Person payload.
     * @throws RegistryUnavailableException     Network / 5xx.
     * @throws RegistryNotFoundException        Person not in registry.
     * @throws RegistryInvalidResponseException Malformed canonical.
     */
    public function fetchPerson(string $country, string $companyId): array
    {
        $country   = strtolower(trim($country));
        $companyId = trim($companyId);
        if ($country === '' || $companyId === '') {
            throw new \InvalidArgumentException(
                'PersonsRegistryClient::fetchPerson(): country and companyId are required.',
            );
        }
        if (!preg_match('/^[a-z]{2}$/', $country)) {
            throw new \InvalidArgumentException(
                "PersonsRegistryClient::fetchPerson(): country must be ISO 3166-1 alpha-2, got '{$country}'.",
            );
        }

        $url = sprintf(
            '%s/%s/%s/json?formatMode=ns',
            $this->baseUrl,
            rawurlencode($country),
            rawurlencode($companyId),
        );

        $data = $this->fetchJson($url, "fetch:{$country}/{$companyId}");

        // The not-found response is `{status: 0, errors: [...]}`; the
        // success canonical has no `status` field at top level (it is
        // not part of the canonical schema).
        if (($data['status'] ?? null) === 0) {
            throw new RegistryNotFoundException(
                "Registry has no record for {$country}/{$companyId}: "
                . $this->summarizeErrors($data),
            );
        }

        // Sanity check the canonical shape — at minimum the format
        // discriminator must be there. We do NOT re-validate the full
        // schema here; that is the applier's job.
        if (($data['format'] ?? null) !== 'shpd.persons.person') {
            throw new RegistryInvalidResponseException(
                "Registry response for {$country}/{$companyId} is not a "
                . "'shpd.persons.person' payload.",
            );
        }

        return $data;
    }

    // ── HTTP transport (seam for tests) ─────────────────────────────────────

    /**
     * Perform one HTTP GET and decode JSON. Maps HTTP status / network
     * errors to the three exception families.
     *
     * @return array<string, mixed>
     */
    private function fetchJson(string $url, string $context): array
    {
        $response = $this->performHttpGet($url);

        $error = $response['error'] ?? null;
        $statusCode = (int) ($response['statusCode'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($error !== null && $error !== '') {
            throw new RegistryUnavailableException(
                "Registry call failed ({$context}): {$error}",
            );
        }
        if ($statusCode === 0) {
            // cURL never reached the server (e.g. DNS, refused).
            throw new RegistryUnavailableException(
                "Registry call failed ({$context}): no HTTP response received.",
            );
        }
        if ($statusCode === 404) {
            throw new RegistryNotFoundException(
                "Registry returned 404 for {$context}.",
            );
        }
        if ($statusCode >= 500) {
            throw new RegistryUnavailableException(
                "Registry returned HTTP {$statusCode} for {$context}.",
            );
        }
        if ($statusCode >= 400) {
            throw new RegistryInvalidResponseException(
                "Registry returned HTTP {$statusCode} for {$context}.",
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $msg = json_last_error_msg();
            throw new RegistryInvalidResponseException(
                "Registry returned non-JSON body for {$context}: {$msg}",
            );
        }
        return $decoded;
    }

    /**
     * Execute one HTTP GET. Protected so tests can subclass and override
     * with an in-memory stub — there is no public HTTP-transport
     * abstraction to inject because the rest of the codebase does not
     * yet use one (the second consumer can promote this to an
     * interface).
     *
     * Returns a three-key array:
     *   - statusCode: int   HTTP status (0 if no response).
     *   - body:       string Raw response body (empty on error).
     *   - error:      ?string cURL error message, or null on success.
     *
     * @return array{statusCode: int, body: string, error: ?string}
     */
    protected function performHttpGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : null;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'statusCode' => $statusCode,
            'body'       => $body === false ? '' : (string) $body,
            'error'      => $error,
        ];
    }

    /**
     * Best-effort one-line summary of `errors[]` from a registry error
     * response. Falls back to a generic message when the shape is
     * unexpected — used only for exception messages, never for control
     * flow.
     *
     * @param array<string, mixed> $data
     */
    private function summarizeErrors(array $data): string
    {
        $errors = $data['errors'] ?? null;
        if (!is_array($errors) || $errors === []) {
            return 'no error details provided';
        }
        $msgs = [];
        foreach ($errors as $err) {
            if (is_array($err) && isset($err['msg']) && is_string($err['msg'])) {
                $msgs[] = $err['msg'];
            } elseif (is_string($err)) {
                $msgs[] = $err;
            }
        }
        return $msgs === [] ? 'no error details provided' : implode('; ', $msgs);
    }
}
