<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/**
 * Formulář události majetku (economy_assets_events, D28, D39). Druh
 * a okruh přicházejí z presetu akce detailu (`asset`, `event_kind`,
 * `scope`) a po založení se nemění; pole se skládají podle druhu:
 *
 *   - zařazení: datum, vstupní cena;
 *   - počáteční stav: datum (první den účetního roku), původní zařazení,
 *     vstupní cena, oprávky, odepsaná období (roky u ročních daňových
 *     metod, jinak měsíce), příznak zvýšené ceny (daňový okruh);
 *   - TZ / snížení: datum, částka;
 *   - odpis: období od–do, datum, částka, polovina (jen ke čtení);
 *   - přerušení: datum (rok se odvodí);
 *   - vyřazení: datum, polovina ročního daňového odpisu — nabízí se, jen
 *     když ji pravidla dovolují a majetek byl na začátku roku v evidenci
 *     (recalculate na datum), výchozí zapnutá (D35).
 *
 * Pravidla vynucuje AssetEventDocument; formulář jen skládá pole.
 */
class AssetEventsForm extends TableForm
{
    private const TITLES = [
        AssetEvent::KIND_OPENING      => 'Počáteční stav',
        AssetEvent::KIND_ACTIVATION   => 'Zařazení majetku',
        AssetEvent::KIND_IMPROVEMENT  => 'Technické zhodnocení',
        AssetEvent::KIND_REDUCTION    => 'Snížení hodnoty',
        AssetEvent::KIND_DEPRECIATION => 'Odpis',
        AssetEvent::KIND_INTERRUPTION => 'Přerušení odpisů',
        AssetEvent::KIND_DISPOSAL     => 'Vyřazení majetku',
    ];

    private ?AssetPlanService $planService = null;

    public function applyNewRecordDefaults(array &$data): void
    {
        $kind = (string) ($data['event_kind'] ?? '');
        $today = $this->planService()->today();

        if (empty($data['event_date'])) {
            $data['event_date'] = $kind === AssetEvent::KIND_OPENING
                ? $this->planService()->taxCalendar()->yearOf($today)->begin
                : $today;
        }
        // Data nesou default schématu (0) — polovina je výchozí zapnutá (D35).
        if ($kind === AssetEvent::KIND_DISPOSAL && empty($data['half_year'])) {
            $data['half_year'] = $this->halfYearAllowed($data) ? 1 : 0;
        }
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $kind = (string) ($data['event_kind'] ?? '');
        $scope = (string) ($data['scope'] ?? AssetEvent::SCOPE_BOTH);
        $title = self::TITLES[$kind] ?? 'Událost majetku';

        $tab = $this->tab('event', 'Událost')
            ->section()
                ->col()
                    ->lookup('asset', table: AssetDocument::TABLE, readOnly: true, required: true)
                    ->select('event_kind', options: $this->cfgOptions('economy.assets.eventKinds'), readOnly: true, required: true)
                    ->select(
                        'scope',
                        options: $this->cfgOptions('economy.assets.eventScopes'),
                        readOnly: true,
                        required: true,
                        hidden: $scope === AssetEvent::SCOPE_BOTH,
                    );

        switch ($kind) {
            case AssetEvent::KIND_ACTIVATION:
                $tab->date('event_date', required: true)
                    ->number('amount', label: 'Vstupní cena', required: true);
                break;

            case AssetEvent::KIND_OPENING:
                $annual = $this->openingCountsYears($data);
                $tab->date('event_date', required: true, hint: 'První den účetního roku, od kterého Shipard pokračuje v odpisování.')
                    ->date('original_date', required: true, hint: 'Podle něj se volí sazby a pravidla.')
                    ->number('amount', label: 'Vstupní cena', required: true, hint: 'Včetně dosavadních technických zhodnocení.')
                    ->number('accumulated', required: true)
                    ->number(
                        'units_done',
                        label: $annual ? 'Uplatněné roky odpisů' : 'Odepsané měsíce',
                        required: true,
                        hint: $annual
                            ? 'Počet let, za které byl odpis uplatněn — rozhoduje o sazbě / koeficientu.'
                            : 'Počet měsíců rozpisu, které už proběhly.',
                    )
                    ->checkbox(
                        'price_increased',
                        hidden: !($annual && $scope === AssetEvent::SCOPE_TAX),
                        hint: 'Vstupní cena byla zvýšena technickým zhodnocením — platí sazba / koeficient pro zvýšenou cenu.',
                    );
                break;

            case AssetEvent::KIND_IMPROVEMENT:
            case AssetEvent::KIND_REDUCTION:
                $tab->date('event_date', required: true)
                    ->number('amount', required: true);
                break;

            case AssetEvent::KIND_DEPRECIATION:
                $tab->date('period_begin', required: true)
                    ->date('period_end', required: true)
                    ->date('event_date', required: true, hint: 'V účetním roce konce období odpisu.')
                    ->number('amount', required: true, hint: 'Celé koruny.')
                    ->checkbox('half_year', readOnly: true);
                break;

            case AssetEvent::KIND_INTERRUPTION:
                $tab->date('event_date', required: true, hint: 'Přeruší se daňový odpis účetního roku, do kterého datum patří.');
                break;

            case AssetEvent::KIND_DISPOSAL:
                $allowed = $this->halfYearAllowed($data);
                $tab->date('event_date', required: true, triggers: 'reload')
                    ->checkbox(
                        'half_year',
                        hidden: !$allowed,
                        hint: 'Polovina ročního daňového odpisu za rok vyřazení; účetní odpis se doplní do měsíce vyřazení.',
                    );
                break;

            default:
                $tab->date('event_date', required: true)
                    ->number('amount');
        }

        $tab->textarea('note');

        return new FormDefinition(
            table: $this->table,
            title: $title,
            titleNew: $title,
            tabs: [$tab->build()],
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Počáteční stav ročních daňových metod počítá roky, ostatní měsíce. */
    private function openingCountsYears(array $data): bool
    {
        if ((string) ($data['scope'] ?? '') !== AssetEvent::SCOPE_TAX) {
            return false;
        }
        $card = $this->card($data);
        $taxMethod = (string) ($card['tax_method'] ?? '');
        return $taxMethod !== ''
            && $this->planService()->rules()->methodKind($taxMethod) === TaxDepreciationRules::KIND_ANNUAL;
    }

    /** @param array<string, mixed> $data */
    private function halfYearAllowed(array $data): bool
    {
        $card = $this->card($data);
        $date = (string) ($data['event_date'] ?? '');
        if ($card === null || strlen($date) < 10) {
            return false;
        }
        $service = $this->planService();
        return $service->halfYearAllowed($card, $service->confirmedEvents((int) $card['id']), substr($date, 0, 10));
    }

    /** @return array<string, mixed>|null */
    private function card(array $data): ?array
    {
        $assetId = (int) ($data['asset'] ?? 0);
        return $assetId > 0 ? $this->planService()->card($assetId) : null;
    }

    protected function planService(): AssetPlanService
    {
        return $this->planService ??= new AssetPlanService(
            $this->db?->getDibiConnection(),
            $this->config,
            $this->dsConfig?->getCountry() ?? 'cz',
            $this->db !== null ? new SettingsStore($this->db) : null,
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function cfgOptions(string $cfgItemId): array
    {
        $cfg = $this->config?->cfgItem($cfgItemId);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', $cfgItemId) : [];
    }
}
