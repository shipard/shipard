<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

/**
 * Lidská podoba selhaného předzpracování jedné zprávy — výstup
 * {@see PreprocessErrorPresenter}. Texty jsou už v jazyce requestu
 * (z cfgItem `core.mail.preprocessErrorKinds`). `technical` jsou spojené
 * poznámky neúspěšných akcí: nesou URL (často s tokeny v query), proto
 * patří jen do sbaleného detailu zprávy — nikdy na Dashboard, do
 * `warning` ani do upozornění v tabu Návrh.
 */
final readonly class PreprocessFailureInfo
{
    /** Stav 40 „Hotovo s chybami" — návrh / klasifikace vznikly bez výsledku předzpracování. */
    public const VARIANT_WARNING = 'warning';
    /** Jen informace — selhaný import ISDOC, stav 40 nenastavuje. */
    public const VARIANT_INFO = 'info';

    public function __construct(
        /** Klíč kategorie v katalogu (kód selhání, `isdocFailed`, `unknown`). */
        public string $kind,
        /** `warning` | `info` — varianta karty (FailureCard). */
        public string $variant,
        public string $title,
        public string $description,
        /** Co dělat — s dosazeným id pravidla, je-li známé. */
        public string $hint,
        /** Počet neúspěšných záznamů v `preprocess_log.results`. */
        public int $failedCount,
        /** Spojené poznámky neúspěšných akcí; null, když žádné nejsou. */
        public ?string $technical,
        /** `ruleId` záznamu, podle kterého se kategorie vybrala; null bez pravidla (plán, sweep, ISDOC). */
        public ?string $ruleId,
    ) {}

    /**
     * Tvar pro API (blok `failure` v tabu Obsah) — klíče kompatibilní
     * s AnalysisErrorInfo::toArray() (sdílená komponenta FailureCard, D6),
     * navíc `variant` a `failedCount`.
     *
     * @return array{kind: string, variant: string, title: string, description: string, detail: null, hint: string, failedCount: int, technical: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind'        => $this->kind,
            'variant'     => $this->variant,
            'title'       => $this->title,
            'description' => $this->description,
            'detail'      => null,
            'hint'        => $this->hint,
            'failedCount' => $this->failedCount,
            'technical'   => $this->technical,
        ];
    }
}
