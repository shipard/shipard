<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess\Action;

use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderProfile;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Preprocess\ActionResult;
use Shipard\Module\Core\Mail\Preprocess\Http\HttpFetcher;
use Shipard\Module\Core\Mail\Preprocess\PreprocessAction;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureCode;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRuleMatcher;

/**
 * Akce `fetchLinkedDocument` (tasks/mail-preprocess.md §6): najde v těle
 * zprávy odkaz na doklad, stáhne ho a uloží jako obsahovou přílohu zprávy
 * s provenance metadaty.
 *
 * Parametry: `linkHrefRegex` (povinný — kandidátní odkaz musí matchnout
 * přímo nebo po URL-decode, tracking wrappery nesou cíl zakódovaný),
 * `allowedDomains` (povinný seznam), `renderIfHtml` (bool, default false,
 * D17 — finální `text/html` se místo selhání vyrenderuje do PDF profilem
 * Untrusted přes RenderClient; bez flagu je HTML selhání s poznámkou).
 * Render je až za kontrolami allowlist/regex/size cap; jeho selhání je
 * poznámka kandidáta a pokračuje se dalším (konzistentní se selháním fetch).
 *
 * Bezpečnost (D6): redirecty se procházejí ručně, **každý hop** projde
 * kontrolou schématu (http/https) a překladu hostu na veřejnou adresu
 * (privátní/loopback/link-local = blok, IP se pinuje do requestu — žádný
 * DNS rebinding). Tracking wrapper smí být průchozí 3xx z cizí domény;
 * **obsah** se přijme jen z finální URL, jejíž host je v `allowedDomains`
 * a která matchne `linkHrefRegex`. Globální stropy: timeout, velikost,
 * počet hopů, počet kandidátů; content-type whitelist v1 = PDF.
 *
 * Idempotence (D5): existuje-li nesmazaná příloha se shodným
 * `(ruleId, action, sourceUrl)`, akce se přeskočí jako úspěšná.
 * Selhání (expirovaný odkaz, timeout, cizí doména) = provozní stav,
 * zapisuje se do results, žádná výjimka ven. Každé selhání nese kód
 * {@see PreprocessFailureCode}; u více kandidátů vyhrává nejkonkrétnější
 * podle PreprocessFailureCode::PRIORITY (tasks/mail-preprocess-error-messages.md D1–D2).
 */
final class FetchLinkedDocumentAction implements PreprocessAction
{
    public const KEY = 'fetchLinkedDocument';

    public const TIMEOUT_SECONDS = 20;
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_REDIRECTS = 5;
    public const MAX_CANDIDATES = 5;
    public const ALLOWED_CONTENT_TYPES = ['application/pdf'];

    private readonly GeneratedAttachments $generated;

    /**
     * @param \Closure(string): list<string>|null $resolver host → IP adresy
     *        (test seam; default gethostbynamel).
     * @param RenderClient|null $render Pro `renderIfHtml`; null = HTML
     *        vždy selhání (starší wiring / testy bez renderu).
     */
    public function __construct(
        AttachmentService $attachments,
        private readonly HttpFetcher $http,
        private readonly ?\Closure $resolver = null,
        private readonly ?RenderClient $render = null,
    ) {
        $this->generated = new GeneratedAttachments($attachments);
    }

    public function execute(array $message, string $ruleId, array $params): ActionResult
    {
        $regex = trim((string) ($params['linkHrefRegex'] ?? ''));
        if ($regex === '') {
            return ActionResult::failure('linkHrefRegex is required', PreprocessFailureCode::RULE_CONFIG);
        }
        $regexError = PreprocessRuleMatcher::compileError($regex);
        if ($regexError !== null) {
            return ActionResult::failure("linkHrefRegex is not a valid regex: {$regexError}", PreprocessFailureCode::RULE_CONFIG);
        }
        $domains = self::normalizeDomains($params['allowedDomains'] ?? null);
        if ($domains === []) {
            return ActionResult::failure('allowedDomains is required', PreprocessFailureCode::RULE_CONFIG);
        }
        $renderIfHtml = ($params['renderIfHtml'] ?? false) === true;

        $candidates = self::extractCandidateUrls(
            (string) ($message['body_html'] ?? ''),
            (string) ($message['body_plain'] ?? ''),
            $regex,
        );
        if ($candidates === []) {
            return ActionResult::failure('no link matching linkHrefRegex found in the message body', PreprocessFailureCode::LINK_NOT_FOUND);
        }

        $messageId = (int) ($message['id'] ?? 0);
        $notes = [];
        $codes = [];

        foreach (array_slice($candidates, 0, self::MAX_CANDIDATES) as $sourceUrl) {
            $existing = $this->generated->findExisting($messageId, $ruleId, self::KEY, $sourceUrl);
            if ($existing !== null) {
                return ActionResult::success("already present (attachment {$existing})", [$existing]);
            }

            $fetched = $this->fetch($sourceUrl, $regex, $domains, $renderIfHtml);
            if (!$fetched['ok']) {
                $notes[] = $sourceUrl . ': ' . $fetched['note'];
                $codes[] = $fetched['code'] ?? '';
                continue;
            }

            $stored = $this->store($messageId, $ruleId, $sourceUrl, $fetched);
            if ($stored['ok']) {
                return ActionResult::success(
                    "fetched {$fetched['finalUrl']} → attachment {$stored['id']}",
                    [$stored['id']],
                );
            }
            $notes[] = $sourceUrl . ': ' . $stored['note'];
            $codes[] = $stored['code'] ?? '';
        }

        return ActionResult::failure(
            implode('; ', $notes),
            PreprocessFailureCode::mostSpecific($codes) ?? PreprocessFailureCode::INTERNAL,
        );
    }

    /**
     * Kandidátní URL z těla: `href` atributy (HTML entity dekódované)
     * + holé URL v textu; dedup při zachování pořadí; jen ty, které
     * matchnou regex přímo nebo po rawurldecode (tracking wrapper).
     *
     * @return list<string>
     */
    public static function extractCandidateUrls(string $html, string $plain, string $regex): array
    {
        $urls = [];
        if ($html !== '' && preg_match_all('~href\s*=\s*(["\'])(.*?)\1~is', $html, $m)) {
            foreach ($m[2] as $raw) {
                $urls[] = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $text = $plain . "\n" . ($html !== '' ? strip_tags($html) : '');
        if (preg_match_all('~https?://[^\s<>"\'\)\]]+~i', $text, $m)) {
            foreach ($m[0] as $raw) {
                $urls[] = rtrim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '.,;');
            }
        }

        $out = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '' || !preg_match('~^https?://~i', $url) || in_array($url, $out, true)) {
                continue;
            }
            if (PreprocessRuleMatcher::regexMatches($regex, $url) === true
                || PreprocessRuleMatcher::regexMatches($regex, rawurldecode($url)) === true
            ) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function normalizeDomains(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $domain) {
            $d = strtolower(trim((string) $domain, " \t\n\r\0\x0B.@"));
            if ($d !== '' && !in_array($d, $out, true)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** @param list<string> $domains */
    public static function hostAllowed(string $host, array $domains): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Průchod redirect řetězcem s kontrolou per hop. Selhání nese `code`
     * (PreprocessFailureCode): HTTP 4xx = vypršelý odkaz, 408/429/5xx
     * a transport = dočasná nedostupnost, allowlist / regex finální URL =
     * chyba pravidla, zbytek = nepoužitelný obsah (vč. vadné URL hopu, U1).
     *
     * @param list<string> $domains
     * @return array{ok: bool, note: string, code?: string, body?: string, finalUrl?: string, fileName?: string, rendered?: bool}
     */
    private function fetch(string $startUrl, string $regex, array $domains, bool $renderIfHtml = false): array
    {
        $url = $startUrl;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($parts === false || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
                return ['ok' => false, 'note' => "invalid or non-http URL: {$url}", 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
            }

            $ip = $this->resolvePublicIp($host);
            if ($ip === null) {
                return ['ok' => false, 'note' => "host {$host} does not resolve to a public address (blocked)", 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
            }

            $response = $this->http->get($url, $ip, self::TIMEOUT_SECONDS, self::MAX_BYTES);

            if ($response->status === 0) {
                return ['ok' => false, 'note' => 'transport error: ' . ($response->error ?? 'unknown'), 'code' => PreprocessFailureCode::REMOTE_UNAVAILABLE];
            }
            if ($response->status >= 300 && $response->status < 400) {
                $location = trim((string) $response->header('location'));
                if ($location === '') {
                    return ['ok' => false, 'note' => "redirect without Location (HTTP {$response->status}) at {$url}", 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
                }
                $url = self::resolveRelative($url, $location);
                continue;
            }
            if ($response->status < 200 || $response->status >= 300) {
                return ['ok' => false, 'note' => "HTTP {$response->status} at {$url}", 'code' => self::codeForHttpStatus($response->status)];
            }

            // Finální URL: allowlist + regex + obsah.
            if (!self::hostAllowed($host, $domains)) {
                return ['ok' => false, 'note' => "final host {$host} is not in allowedDomains", 'code' => PreprocessFailureCode::RULE_CONFIG];
            }
            if (PreprocessRuleMatcher::regexMatches($regex, $url) !== true) {
                return ['ok' => false, 'note' => "final URL does not match linkHrefRegex: {$url}", 'code' => PreprocessFailureCode::RULE_CONFIG];
            }
            if ($response->truncated) {
                return ['ok' => false, 'note' => 'response exceeds the size cap (' . self::MAX_BYTES . ' B)', 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
            }

            $contentType = strtolower(trim(explode(';', (string) $response->header('content-type'))[0]));
            $isPdf = in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)
                || str_starts_with($response->body, '%PDF');
            if (!$isPdf) {
                if (str_starts_with($contentType, 'text/html')) {
                    return $this->renderHtmlDocument($response->body, $url, (string) $response->header('content-disposition'), $renderIfHtml);
                }
                return ['ok' => false, 'note' => "unsupported content-type '{$contentType}' at {$url}", 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
            }
            if ($response->body === '') {
                return ['ok' => false, 'note' => "empty body at {$url}", 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
            }

            return [
                'ok' => true,
                'note' => '',
                'body' => $response->body,
                'finalUrl' => $url,
                'fileName' => self::fileNameFor((string) $response->header('content-disposition'), $url),
            ];
        }

        return ['ok' => false, 'note' => 'too many redirects (> ' . self::MAX_REDIRECTS . ')', 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
    }

    /**
     * Kód selhání pro ne-2xx/3xx stav: 408 (timeout), 429 (rate limit)
     * a 5xx jsou dočasná nedostupnost serveru, ostatní 4xx = server
     * dokument nevydal (vypršelý odkaz, přihlášení, 404).
     */
    public static function codeForHttpStatus(int $status): string
    {
        if ($status === 408 || $status === 429 || $status >= 500) {
            return PreprocessFailureCode::REMOTE_UNAVAILABLE;
        }
        return PreprocessFailureCode::LINK_EXPIRED;
    }

    /**
     * Finální dokument je HTML (D17): s `renderIfHtml` render do PDF
     * profilem Untrusted, jinak selhání s poznámkou. Allowlist, regex
     * a size cap už prošly — render je až za nimi.
     *
     * @return array{ok: bool, note: string, code?: string, body?: string, finalUrl?: string, fileName?: string, rendered?: bool}
     */
    private function renderHtmlDocument(string $html, string $url, string $contentDisposition, bool $renderIfHtml): array
    {
        if (!$renderIfHtml) {
            return ['ok' => false, 'note' => "final document is HTML at {$url} — set renderIfHtml: true to render it to PDF", 'code' => PreprocessFailureCode::RULE_CONFIG];
        }
        if ($this->render === null) {
            return ['ok' => false, 'note' => "final document is HTML at {$url} but no render client is available", 'code' => PreprocessFailureCode::INTERNAL];
        }
        if (trim($html) === '') {
            return ['ok' => false, 'note' => "empty HTML body at {$url}", 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
        }
        if (strlen($html) > RenderBodyToPdfAction::HTML_MAX_BYTES) {
            return ['ok' => false, 'note' => 'HTML document exceeds the render size cap (' . RenderBodyToPdfAction::HTML_MAX_BYTES . " B) at {$url}", 'code' => PreprocessFailureCode::UNEXPECTED_CONTENT];
        }

        $rendered = $this->render->renderHtml(RenderBodyToPdfAction::ensureUtf8Document($html), [], RenderProfile::Untrusted);
        if (!$rendered->ok || $rendered->pdfContent === null) {
            $kind = $rendered->errorKind?->value ?? 'unknown';
            return ['ok' => false, 'note' => "render failed: {$kind}" . ($rendered->note !== null ? ": {$rendered->note}" : '') . " at {$url}", 'code' => PreprocessFailureCode::INTERNAL];
        }

        return [
            'ok' => true,
            'note' => '',
            'body' => $rendered->pdfContent,
            'finalUrl' => $url,
            'fileName' => self::fileNameFor($contentDisposition, $url),
            'rendered' => true,
        ];
    }

    /**
     * @param array{body?: string, finalUrl?: string, fileName?: string, rendered?: bool} $fetched
     * @return array{ok: bool, note: string, id?: int}
     */
    private function store(int $messageId, string $ruleId, string $sourceUrl, array $fetched): array
    {
        $extra = [
            'sourceUrl' => $sourceUrl,
            'finalUrl' => (string) $fetched['finalUrl'],
            'fetchedAt' => date('c'),
        ];
        if ($fetched['rendered'] ?? false) {
            $extra['rendered'] = true;
        }
        return $this->generated->store(
            $messageId,
            (string) $fetched['fileName'],
            (string) ($fetched['body'] ?? ''),
            $ruleId,
            self::KEY,
            $extra,
        );
    }

    /**
     * Překlad hostu na veřejnou IP; null = nepřeložitelný nebo některá
     * adresa spadá do privátního/rezervovaného rozsahu (konzervativně
     * blok celého hostu).
     */
    private function resolvePublicIp(string $host): ?string
    {
        $literal = trim($host, '[]'); // IPv6 literal přichází z parse_url v hranatých závorkách
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $ips = [$literal];
        } else {
            $ips = $this->resolver !== null
                ? ($this->resolver)($host)
                : (gethostbynamel($host) ?: []);
        }
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }
        return (string) $ips[0];
    }

    public static function resolveRelative(string $base, string $location): string
    {
        // Absolutní URL s libovolným schématem vracíme beze změny — cizí
        // schéma (file:, ftp:) pak odmítne kontrola hopu, ne slepení s base.
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if ($parts === false) {
            return $location;
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $origin . $dir . $location;
    }

    /** Název souboru z Content-Disposition, jinak z URL, jinak generický; vždy .pdf. */
    public static function fileNameFor(string $contentDisposition, string $url): string
    {
        $name = '';
        if (preg_match("~filename\*=(?:UTF-8|utf-8)''([^;]+)~", $contentDisposition, $m)) {
            $name = rawurldecode(trim($m[1], " \"'"));
        } elseif (preg_match('~filename=("([^"]*)"|([^;]+))~', $contentDisposition, $m)) {
            $name = trim($m[2] !== '' ? $m[2] : $m[3], " \"'");
        }
        if ($name === '') {
            // Poslední segment cesty (i bez přípony — download tokeny), ne query.
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $base = rawurldecode(basename($path));
            if ($base !== '' && $base !== '/' && mb_strlen($base) <= 60) {
                $name = $base;
            }
        }
        return GeneratedAttachments::sanitizePdfFileName($name, 'document.pdf');
    }
}
