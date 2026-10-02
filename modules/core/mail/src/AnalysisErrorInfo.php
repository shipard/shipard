<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Lidská podoba jednoho selhání AI analýzy — výstup
 * {@see AnalysisErrorPresenter}. Texty jsou už v jazyce requestu
 * (z cfgItem `core.mail.analysisErrorKinds`), `technical` je původní
 * `error_message` běhu (může nést hodnoty z dokladu — zobrazovat jen
 * sbalené v detailu zprávy, nikdy na kartě Dashboardu).
 */
final readonly class AnalysisErrorInfo
{
    public function __construct(
        /** Klíč kategorie v katalogu (`schemaEnum`, `aiTruncated`, `unknown`, …). */
        public string $kind,
        /** Titulek — na Dashboardu nahrazuje obecný „Chyba analýzy e-mailu". */
        public string $title,
        /** Vysvětlení: co se stalo a čí je to chyba. */
        public string $description,
        /** Upřesnění s dosazenými {key}/{path}, nebo null (kategorie bez detailu / chybějící hodnota). */
        public ?string $detail,
        /** Co dělat — společný hint podle doporučení reanalýzy (D4). */
        public string $hint,
        /** Výchozí aktivní profil má novější prompt než selhaný běh. */
        public bool $reanalysisRecommended,
        /** Původní technická hláška (`[typ] zpráva`), u invalidOutput null. */
        public ?string $technical,
    ) {}

    /**
     * Tvar pro API (tab Návrh, tab Analýzy) — stejné klíče jako property.
     *
     * @return array{kind: string, title: string, description: string, detail: ?string, hint: string, reanalysisRecommended: bool, technical: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind'                  => $this->kind,
            'title'                 => $this->title,
            'description'           => $this->description,
            'detail'                => $this->detail,
            'hint'                  => $this->hint,
            'reanalysisRecommended' => $this->reanalysisRecommended,
            'technical'             => $this->technical,
        ];
    }
}
