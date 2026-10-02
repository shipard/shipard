<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Překlad selhaného předzpracování zprávy na lidskou hlášku
 * (tasks/mail-preprocess-error-messages.md D1–D3).
 *
 * Vstupem je `preprocess_state` + `preprocess_log`. Ve stavu 40 „Hotovo
 * s chybami" se kategorie vybírá z kódů neúspěšných záznamů `results`
 * ({@see PreprocessFailureCode}, žádný rozbor textu poznámek) podle
 * PreprocessFailureCode::PRIORITY; záznam bez kódu (starší logy, D1)
 * nebo s neznámým kódem padá do `unknown`. Mimo stav 40 zbývá jediný
 * případ: `log.isdoc = 'failed'` → informativní `isdocFailed` (import
 * ISDOC stav 40 nenastavuje). Jinak null — nic neselhalo.
 *
 * Texty čte z cfgItem `core.mail.preprocessErrorKinds` (compiled config
 * je per jazyk, pole přijdou už lokalizovaná); bez configu anglický
 * fallback natvrdo — lokalizace degraduje, ne crash. `{ruleId}` v hintu
 * nahradí id pravidla vybraného záznamu; bez pravidla (prázdný plán,
 * sweep) token zmizí i s předcházející mezerou (U3).
 */
class PreprocessErrorPresenter
{
    public const CFG_ITEM = 'core.mail.preprocessErrorKinds';

    public const KIND_ISDOC_FAILED = 'isdocFailed';
    public const KIND_UNKNOWN      = 'unknown';

    /**
     * Anglický fallback bez compiled configu — stejné znění jako holá pole
     * v preprocessErrorKinds.jsonc.
     *
     * @var array<string, array{name: string, description: string, hint: string}>
     */
    private const FALLBACK = [
        PreprocessFailureCode::LINK_NOT_FOUND => [
            'name'        => 'The e-mail has no link to the document',
            'description' => 'The preprocessing rule expected a link to the document in the e-mail but found none that matched. The sender has probably changed the layout of the e-mail.',
            'hint'        => 'Download the document from the e-mail manually and upload it on the Dashboard. Check rule {ruleId} in Settings → Mail → Preprocess rules, or let us know.',
        ],
        PreprocessFailureCode::LINK_EXPIRED => [
            'name'        => 'The document link does not work',
            'description' => "The sender's server did not provide the document — the link may have expired or requires signing in.",
            'hint'        => 'Open the link in the e-mail, download the document and upload it on the Dashboard.',
        ],
        PreprocessFailureCode::REMOTE_UNAVAILABLE => [
            'name'        => 'The server with the document was unavailable',
            'description' => "The download failed because of an outage or a slow response on the sender's side.",
            'hint'        => 'Download the document from the link in the e-mail manually and upload it on the Dashboard.',
        ],
        PreprocessFailureCode::UNEXPECTED_CONTENT => [
            'name'        => 'The link does not lead to a usable document',
            'description' => 'The link led to a page or file that cannot be used as a document (different format, empty or too large a file).',
            'hint'        => 'Open the link in the e-mail manually; if the document is there, download it and upload it on the Dashboard.',
        ],
        PreprocessFailureCode::BODY_RENDER => [
            'name'        => 'The e-mail text could not be converted to PDF',
            'description' => 'The rule was supposed to create a PDF document from the e-mail body, but the conversion did not happen.',
            'hint'        => 'Enter the document manually and let us know which message it was.',
        ],
        PreprocessFailureCode::RULE_CONFIG => [
            'name'        => 'The preprocessing rule is set up incorrectly',
            'description' => "The error is in the rule's settings, not in the message.",
            'hint'        => 'Check rule {ruleId} in Settings → Mail → Preprocess rules, or let us know.',
        ],
        PreprocessFailureCode::INTERNAL => [
            'name'        => "Preprocessing failed on Shipard's side",
            'description' => 'An internal processing error, not an error in the message.',
            'hint'        => 'Let us know which message it was.',
        ],
        self::KIND_ISDOC_FAILED => [
            'name'        => 'The ISDOC attachment could not be read',
            'description' => 'The AI will therefore extract the document from the other attachments.',
            'hint'        => 'Check the proposal the usual way.',
        ],
        self::KIND_UNKNOWN => [
            'name'        => 'Preprocessing ended with an error',
            'description' => 'Unknown kind of error.',
            'hint'        => 'Let us know which message it was.',
        ],
    ];

    /** @var array<string, string> */
    private const FALLBACK_COMMON = [
        'proposalWarningTitle' => 'The proposal was created without the preprocessing result',
        'proposalWarningText'  => 'The AI worked without the document that preprocessing was supposed to create, so the proposal or classification may be off. Details are in the Content tab.',
        'cardWarning'          => 'Preprocessing: {title}',
    ];

    public function __construct(private readonly ?ConfigRuntime $config)
    {
    }

    /**
     * Hláška podle stavu a logu zprávy; null, když nic neselhalo.
     *
     * @param mixed $log `preprocess_log` — JSON řetězec nebo už dekódované pole.
     */
    public function fromLog(int $state, mixed $log): ?PreprocessFailureInfo
    {
        $log = PreprocessRunner::decodeLog($log);

        if ($state === PreprocessRunner::STATE_DONE_WITH_ERRORS) {
            return $this->fromFailedResults($log);
        }
        if (($log['isdoc'] ?? null) === 'failed') {
            return $this->build(self::KIND_ISDOC_FAILED, PreprocessFailureInfo::VARIANT_INFO, 0, null, null);
        }
        return null;
    }

    /**
     * Upozornění pro tab Návrh (D3b): návrh / klasifikace vznikly bez
     * výsledku předzpracování. Jen pro variantu `warning` (stav 40).
     *
     * @return array{kind: string, title: string, text: string}
     */
    public function proposalWarning(PreprocessFailureInfo $info): array
    {
        return [
            'kind'  => $info->kind,
            'title' => $this->commonText('proposalWarningTitle'),
            'text'  => $this->commonText('proposalWarningText'),
        ];
    }

    /**
     * Řádek `warning` na kartě Dashboardu (D3c) — jen titulek kategorie
     * s prefixem, žádné poznámky s URL.
     */
    public function cardWarning(PreprocessFailureInfo $info): string
    {
        return strtr($this->commonText('cardWarning'), ['{title}' => $info->title]);
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $log */
    private function fromFailedResults(array $log): PreprocessFailureInfo
    {
        $failed = [];
        foreach (is_array($log['results'] ?? null) ? $log['results'] : [] as $result) {
            if (is_array($result) && empty($result['ok'])) {
                $failed[] = $result;
            }
        }

        $codes = array_map(static fn(array $r): string => (string) ($r['code'] ?? ''), $failed);
        $kind = PreprocessFailureCode::mostSpecific($codes) ?? self::KIND_UNKNOWN;

        // Záznam, podle kterého se kategorie vybrala — první s vítězným
        // kódem; u `unknown` první bez použitelného kódu.
        $selected = null;
        foreach ($failed as $i => $result) {
            $matches = $kind === self::KIND_UNKNOWN
                ? !PreprocessFailureCode::isKnown($codes[$i])
                : $codes[$i] === $kind;
            if ($matches) {
                $selected = $result;
                break;
            }
        }
        $ruleId = trim((string) ($selected['ruleId'] ?? ''));

        $notes = [];
        foreach ($failed as $result) {
            $label = trim(((string) ($result['ruleId'] ?? '')) . ' / ' . ((string) ($result['action'] ?? '')), ' /');
            $note = trim((string) ($result['note'] ?? ''));
            if ($note === '') {
                continue;
            }
            $notes[] = $label !== '' ? "{$label}: {$note}" : $note;
        }

        return $this->build(
            $kind,
            PreprocessFailureInfo::VARIANT_WARNING,
            count($failed),
            $notes !== [] ? implode("\n", $notes) : null,
            $ruleId !== '' ? $ruleId : null,
        );
    }

    private function build(string $kind, string $variant, int $failedCount, ?string $technical, ?string $ruleId): PreprocessFailureInfo
    {
        $entry = $this->catalogEntry($kind);

        return new PreprocessFailureInfo(
            kind: $kind,
            variant: $variant,
            title: (string) ($entry['name'] ?? self::FALLBACK[$kind]['name']),
            description: (string) ($entry['description'] ?? self::FALLBACK[$kind]['description']),
            hint: self::fillRuleId((string) ($entry['hint'] ?? self::FALLBACK[$kind]['hint']), $ruleId),
            failedCount: $failedCount,
            technical: $technical,
            ruleId: $ruleId,
        );
    }

    /** `{ruleId}` → id pravidla; bez něj token zmizí i s mezerou před ním (U3). */
    private static function fillRuleId(string $template, ?string $ruleId): string
    {
        if ($ruleId !== null && $ruleId !== '') {
            return strtr($template, ['{ruleId}' => $ruleId]);
        }
        return (string) preg_replace('/\s*\{ruleId\}/u', '', $template);
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
}
