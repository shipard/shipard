<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Překlad technického selhání AI analýzy na lidskou hlášku
 * (tasks/mail-analysis-error-messages.md, D1–D5).
 *
 * Vstupem je `core_mail_message_analyses.error_message` ve tvaru
 * `[typ] zpráva`, jak ho skládá `AnalysisController::failed` z `error_type`
 * + `error_message` analyzeru. Kategorii odvozuje rozbor prefixu a u
 * `schema_error` tvar textu z `ai_analyzer/schema.py` (jsonschema):
 *
 *   [schema_error] output does not match schema: Additional properties are not allowed ('x' was unexpected) at ['document', 'extracted_json', 'customer']
 *   [schema_error] output does not match schema: 'abc' is too long at [...]
 *   [schema_error] output does not match schema: 'abc' is not one of ['a', 'b'] at [...]
 *   [schema_error] output does not match schema: <jiné> at [...]
 *   [schema_error] fenced JSON is invalid: … | output is not valid JSON | output JSON must be an object at the top level
 *   [ai_error] anthropic: output truncated at max_tokens=<n>
 *   [ai_error] anthropic permanent: … | anthropic sdk: … | anthropic error: … | unsupported provider: …
 *   [config_error] shpd rejected /result body (<status> <code>): … | shpd <status> <code>: …
 *
 * Rozbor textu z Pythonu je křehký — ai_analyzer#1 plánuje hlásit všechny
 * chyby najednou (`iter_errors`). Neznámý, vícenásobný nebo budoucí tvar
 * proto padá tiše do `schemaOther` / `unknown`, nikdy výjimkou.
 *
 * Texty čte z cfgItem `core.mail.analysisErrorKinds` (compiled config je
 * per jazyk, `name`/`description`/`detail` přijdou už lokalizované); bez
 * configu anglický fallback natvrdo — lokalizace degraduje, ne crash.
 *
 * Doporučení reanalýzy (D4): výchozí aktivní profil má novější
 * `prompt_version` než selhaný běh. Selhané běhy mají `profile` NULL,
 * proto se porovnává s výchozím profilem DS, ne s profilem běhu. Verze
 * profilu se čte jednou per instance (feed staví N karet jedním
 * presenterem).
 */
class AnalysisErrorPresenter
{
    public const CFG_ITEM = 'core.mail.analysisErrorKinds';

    public const KIND_SCHEMA_ADDITIONAL_PROPERTY = 'schemaAdditionalProperty';
    public const KIND_SCHEMA_TOO_LONG            = 'schemaTooLong';
    public const KIND_SCHEMA_ENUM                = 'schemaEnum';
    public const KIND_SCHEMA_INVALID_JSON        = 'schemaInvalidJson';
    public const KIND_SCHEMA_OTHER               = 'schemaOther';
    public const KIND_AI_TRUNCATED               = 'aiTruncated';
    public const KIND_AI_ERROR                   = 'aiError';
    public const KIND_CONFIG_ERROR               = 'configError';
    public const KIND_INVALID_OUTPUT             = 'invalidOutput';
    public const KIND_UNKNOWN                    = 'unknown';

    private const PROFILES_TABLE = 'core_mail_ai_profiles';

    /** Prefixy Python cesty, které uživatele nezajímají (obal výstupu analyzeru). */
    private const PATH_PREFIX = ['document', 'extracted_json'];

    /**
     * Anglický fallback bez compiled configu — stejné znění jako holá pole
     * v analysisErrorKinds.jsonc.
     *
     * @var array<string, array{name: string, description: string, detail?: string}>
     */
    private const FALLBACK = [
        self::KIND_SCHEMA_ADDITIONAL_PROPERTY => [
            'name'        => 'AI returned data in an unexpected shape',
            'description' => "This is not a problem with the message or its attachment, but with Shipard's analysis setup.",
            'detail'      => 'AI added a field "{key}" ({path}) that the document format does not know.',
        ],
        self::KIND_SCHEMA_TOO_LONG => [
            'name'        => 'AI returned data in an unexpected shape',
            'description' => "This is not a problem with the message or its attachment, but with Shipard's analysis setup.",
            'detail'      => 'The value in field {path} is longer than the format allows.',
        ],
        self::KIND_SCHEMA_ENUM => [
            'name'        => 'AI returned data in an unexpected shape',
            'description' => "This is not a problem with the message or its attachment, but with Shipard's analysis setup.",
            'detail'      => 'The value in field {path} is not one of the allowed options.',
        ],
        self::KIND_SCHEMA_INVALID_JSON => [
            'name'        => 'AI returned data in an unexpected shape',
            'description' => "This is not a problem with the message or its attachment, but with Shipard's analysis setup.",
            'detail'      => 'The AI response is not valid JSON.',
        ],
        self::KIND_SCHEMA_OTHER => [
            'name'        => 'AI returned data in an unexpected shape',
            'description' => "This is not a problem with the message or its attachment, but with Shipard's analysis setup.",
        ],
        self::KIND_AI_TRUNCATED => [
            'name'        => 'The AI response did not fit within the limit',
            'description' => 'The document is too large for a single analysis, typically because it has many lines.',
        ],
        self::KIND_AI_ERROR => [
            'name'        => 'The AI service could not process the message',
            'description' => 'For example because of an unsupported or damaged attachment.',
        ],
        self::KIND_CONFIG_ERROR => [
            'name'        => 'Error in the link between the analyzer and Shipard',
            'description' => "The problem is in Shipard's operation, not in the message.",
        ],
        self::KIND_INVALID_OUTPUT => [
            'name'        => 'AI returned an unusable proposal',
            'description' => 'The proposal did not pass the document format check and cannot be used.',
        ],
        self::KIND_UNKNOWN => [
            'name'        => 'Analysis failed',
            'description' => 'Unknown kind of error.',
        ],
    ];

    /** @var array<string, string> */
    private const FALLBACK_COMMON = [
        'whatHappened'  => 'What happened',
        'whatToDo'      => 'What to do',
        'hintReanalyze' => 'The analysis has been updated since — try Reanalyze.',
        'hintManual'    => 'Repeating with the same analysis version will end the same way. Enter the document manually and let us know which message it was.',
    ];

    /** Memoizovaná normalizovaná verze promptu výchozího profilu; false = ještě nečteno. */
    private string|null|false $defaultProfileVersion = false;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
    ) {}

    /**
     * Hláška selhaného běhu (`status = 3`). `$failedPromptVersion` je
     * `prompt_version` téhož běhu — řídí hint a `reanalysisRecommended`.
     */
    public function fromErrorMessage(?string $errorMessage, ?string $failedPromptVersion = null): AnalysisErrorInfo
    {
        $technical = trim((string) $errorMessage);
        [$kind, $values] = $this->classify($technical);

        return $this->build($kind, $values, $failedPromptVersion, $technical !== '' ? $technical : null);
    }

    /**
     * Hláška pro úspěšný běh, jehož výstup neprošel serverovou validací
     * (forenzní wrapper `_validationError` v canonical_json, D5).
     */
    public function forInvalidOutput(?string $failedPromptVersion = null): AnalysisErrorInfo
    {
        return $this->build(self::KIND_INVALID_OUTPUT, [], $failedPromptVersion, null);
    }

    /**
     * D4: reanalýza má smysl, jen když výchozí aktivní profil nese novější
     * `prompt_version` než selhaný běh. Neparsovatelná nebo chybějící
     * verze (null, `unknown`, `isdoc`) → false.
     */
    public function isReanalysisRecommended(?string $failedPromptVersion): bool
    {
        $failed = self::normalizeVersion($failedPromptVersion);
        if ($failed === null) {
            return false;
        }
        $current = $this->defaultProfileVersion();
        if ($current === null) {
            return false;
        }
        return version_compare($current, $failed, '>');
    }

    /**
     * Řádky rozbalovacího detailu karty Dashboardu: „Co se stalo"
     * (description + detail) a „Co dělat" (hint). Technická hláška
     * do karty nepatří.
     *
     * @return list<array{label: string, value: string}>
     */
    public function cardDetails(AnalysisErrorInfo $info): array
    {
        $happened = $info->description;
        if ($info->detail !== null && $info->detail !== '') {
            $happened .= ' ' . $info->detail;
        }
        return [
            ['label' => $this->commonText('whatHappened'), 'value' => $happened],
            ['label' => $this->commonText('whatToDo'),     'value' => $info->hint],
        ];
    }

    // -------------------------------------------------------------------------
    // Rozbor error_message
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: array<string, string>} kategorie + hodnoty zástupných symbolů
     */
    private function classify(string $message): array
    {
        if ($message === '' || !preg_match('/^\[([a-z_]+)\]\s*(.*)$/s', $message, $m)) {
            return [self::KIND_UNKNOWN, []];
        }
        $type = $m[1];
        $rest = trim($m[2]);

        return match ($type) {
            'schema_error' => $this->classifySchemaError($rest),
            'ai_error'     => [
                str_contains($rest, 'output truncated at max_tokens') ? self::KIND_AI_TRUNCATED : self::KIND_AI_ERROR,
                [],
            ],
            'config_error' => [self::KIND_CONFIG_ERROR, []],
            default        => [self::KIND_UNKNOWN, []],
        };
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function classifySchemaError(string $rest): array
    {
        if (
            str_starts_with($rest, 'fenced JSON is invalid')
            || str_starts_with($rest, 'output is not valid JSON')
            || str_starts_with($rest, 'output JSON must be an object')
        ) {
            return [self::KIND_SCHEMA_INVALID_JSON, []];
        }

        $prefix = 'output does not match schema: ';
        if (!str_starts_with($rest, $prefix)) {
            return [self::KIND_SCHEMA_OTHER, []];
        }
        $body = substr($rest, strlen($prefix));

        // „<hláška> at [<python list>]" — greedy první skupina bere poslední
        // „ at [", takže u více chyb za sebou zůstane v hlášce zbytek a žádný
        // specifický tvar níže nesedne → schemaOther.
        $path = null;
        if (preg_match('/^(.*) at \[(.*)\]$/s', $body, $pm)) {
            $body = $pm[1];
            $path = self::dottedPath($pm[2]);
        }
        $values = $path !== null ? ['path' => $path] : [];

        if (preg_match('/^Additional properties are not allowed \((.+) (?:was|were) unexpected\)$/s', $body, $am)) {
            $keys = self::quotedStrings($am[1]);
            if ($keys !== []) {
                $values['key'] = implode(', ', $keys);
            }
            return [self::KIND_SCHEMA_ADDITIONAL_PROPERTY, $values];
        }
        if (str_ends_with($body, ' is too long')) {
            return [self::KIND_SCHEMA_TOO_LONG, $values];
        }
        if (str_contains($body, ' is not one of [')) {
            return [self::KIND_SCHEMA_ENUM, $values];
        }
        return [self::KIND_SCHEMA_OTHER, $values];
    }

    /**
     * Python list `'document', 'extracted_json', 'rows', 0, 'vat'` →
     * `rows.0.vat` (bez obalového prefixu). Prázdná cesta → null.
     */
    private static function dottedPath(string $pythonList): ?string
    {
        if (!preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|(\d+)/', $pythonList, $all, PREG_SET_ORDER)) {
            return null;
        }
        $segments = [];
        foreach ($all as $seg) {
            $segments[] = $seg[1] !== '' ? $seg[1] : (($seg[2] ?? '') !== '' ? $seg[2] : ($seg[3] ?? ''));
        }
        foreach (self::PATH_PREFIX as $prefix) {
            if (($segments[0] ?? null) === $prefix) {
                array_shift($segments);
            }
        }
        $segments = array_values(array_filter($segments, static fn(string $s): bool => $s !== ''));
        return $segments === [] ? null : implode('.', $segments);
    }

    /**
     * Řetězce v uvozovkách z Python reprezentace (`'a', 'b'` i `"it's"`).
     *
     * @return list<string>
     */
    private static function quotedStrings(string $text): array
    {
        if (!preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/', $text, $all, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($all as $m) {
            $value = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Texty
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $values
     */
    private function build(string $kind, array $values, ?string $failedPromptVersion, ?string $technical): AnalysisErrorInfo
    {
        $entry = $this->catalogEntry($kind);
        $recommended = $this->isReanalysisRecommended($failedPromptVersion);

        return new AnalysisErrorInfo(
            kind: $kind,
            title: (string) ($entry['name'] ?? self::FALLBACK[$kind]['name']),
            description: (string) ($entry['description'] ?? self::FALLBACK[$kind]['description']),
            detail: self::fillDetail($entry['detail'] ?? self::FALLBACK[$kind]['detail'] ?? null, $values),
            hint: $this->commonText($recommended ? 'hintReanalyze' : 'hintManual'),
            reanalysisRecommended: $recommended,
            technical: $technical,
        );
    }

    /**
     * Dosazení `{key}` / `{path}`. Chybí-li hodnota, nejdřív zmizí závorkový
     * doplněk „ ({path})"; zůstane-li v šabloně nedosazený symbol, detail
     * se vynechá úplně (lepší nic než „Hodnota v poli  je…").
     *
     * @param array<string, string> $values
     */
    private static function fillDetail(mixed $template, array $values): ?string
    {
        if (!is_string($template) || trim($template) === '') {
            return null;
        }
        foreach (['key', 'path'] as $placeholder) {
            if (!isset($values[$placeholder])) {
                $template = (string) preg_replace('/\s*\(\{' . $placeholder . '\}\)/', '', $template);
            }
        }
        if (preg_match('/\{(key|path)\}/', $template, $m) && !isset($values[$m[1]])) {
            return null;
        }
        $replace = [];
        foreach ($values as $k => $v) {
            $replace['{' . $k . '}'] = $v;
        }
        return strtr($template, $replace);
    }

    /** @return array<string, mixed> */
    private function catalogEntry(string $kind): array
    {
        $cfg = $this->config?->cfgItem(self::CFG_ITEM);
        $entry = is_array($cfg) ? ($cfg[$kind] ?? null) : null;
        return is_array($entry) ? $entry : [];
    }

    private function commonText(string $key): string
    {
        $cfg = $this->config?->cfgItem(self::CFG_ITEM);
        $common = is_array($cfg) ? ($cfg['_common'] ?? null) : null;
        $value = is_array($common) ? ($common[$key]['name'] ?? null) : null;
        return is_string($value) && $value !== '' ? $value : self::FALLBACK_COMMON[$key];
    }

    // -------------------------------------------------------------------------
    // Verze promptu
    // -------------------------------------------------------------------------

    private function defaultProfileVersion(): ?string
    {
        if ($this->defaultProfileVersion === false) {
            $row = $this->db->fetchRow(
                'SELECT `prompt_version` FROM %n WHERE `is_active` = %i'
                . ' ORDER BY `is_default` DESC, `id` ASC LIMIT 1',
                self::PROFILES_TABLE,
                1,
            );
            $this->defaultProfileVersion = self::normalizeVersion(
                $row !== null ? (string) ($row['prompt_version'] ?? '') : null,
            );
        }
        return $this->defaultProfileVersion;
    }

    /**
     * `v4.3.0` → `4.3.0`; cokoli jiného než číselná verze (`unknown`,
     * `isdoc`, prázdno) → null. `version_compare` by s takovými řetězci
     * vracel nesmysly.
     */
    private static function normalizeVersion(?string $version): ?string
    {
        $v = ltrim(trim((string) $version), 'vV');
        return preg_match('/^\d+(\.\d+)*$/', $v) === 1 ? $v : null;
    }
}
