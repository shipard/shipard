<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Viewer\TableViewer;

/**
 * Společný základ read-only viewerů saldokonta ({@see CasesViewer} po
 * případech, {@see LedgerViewer} po pohybech): bez docStates, bez
 * toolbaru, grid jako výchozí layout, chip lišta saldokont jako viewGroups
 * (identita `code`) a sdílené formátování / popisky typů otevřenosti
 * případu — jedna definice pro oba pohledy.
 */
abstract class AccbalViewerBase extends TableViewer
{
    protected ?string $docStatesCfgItem = null;

    /**
     * Per-request cache saldokont pro viewGroups — meta volá getViewGroups()
     * i getDefaultViewGroup(), dotaz do DB stačí jednou.
     *
     * @var list<array{id: string, label: string}>|null
     */
    private ?array $viewGroups = null;

    /** Saldokonto se na desktopu otevírá jako tabulka; list zůstává mobilním formátem. */
    public function getDefaultLayout(): string
    {
        return 'grid';
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    /**
     * Skupiny = saldokonta dle sort_order; identita `code` (stabilní napříč
     * DS, čitelná v URL, bez kolize s rezervovanými 'active'/'archive'/
     * 'trash'/'all'), label short_name s fallbackem na name — stejná
     * konvence jako sidebar (accbal-nav-items).
     */
    public function getViewGroups(): array
    {
        if ($this->viewGroups === null) {
            $balances = $this->db->fetchAll(
                'SELECT `code`, `name`, `short_name` FROM `economy_accbal_balances`'
                . ' WHERE `docState` != 90 ORDER BY `sort_order` ASC, `name` ASC',
            );
            $this->viewGroups = [];
            foreach ($balances as $b) {
                $shortName = trim((string) ($b['short_name'] ?? ''));
                $this->viewGroups[] = [
                    'id'    => (string) $b['code'],
                    'label' => $shortName !== '' ? $shortName : (string) $b['name'],
                ];
            }
        }
        return $this->viewGroups;
    }

    public function getDefaultViewGroup(): string
    {
        $groups = $this->getViewGroups();
        return $groups !== [] ? $groups[0]['id'] : 'all';
    }

    /**
     * Podmínka viewGroup chipu: 'all' = bez podmínky; 'active' defenzivně
     * také — stale frontend z otevřené session může po nasazení poslat
     * starý docState default. Vrací [podmínka|null, parametr].
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function viewGroupCondition(string $value, string $balancesAlias = 'b'): array
    {
        if ($value === 'all' || $value === 'active') {
            return [null, null];
        }
        return [$balancesAlias . '.`code` = %s', $value];
    }

    /** Lokalizovaný název typu otevřenosti případu ({@see CaseQuery::kindOf}). */
    protected function kindLabel(string $kind): string
    {
        $cs = $this->language === 'cs';
        return match ($kind) {
            CaseQuery::KIND_DEBT        => $cs ? 'Dluh' : 'Debt',
            CaseQuery::KIND_OVERPAYMENT => $cs ? 'Přeplatek' : 'Overpayment',
            CaseQuery::KIND_UNREQUESTED => $cs ? 'Úhrada bez předpisu' : 'Payment without request',
            default                     => $cs ? 'Uzavřeno' : 'Closed',
        };
    }

    /** @param array<int, array{label: string, value: string}> $items */
    protected function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    protected function formatMoney(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, ',', ' ');
    }

    protected function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $ts = is_string($value) ? strtotime($value) : false;
        return $ts !== false ? date('j. n. Y', $ts) : null;
    }
}
