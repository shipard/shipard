<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Database\TableDefinition;

/**
 * Orchestrace nabídky účetních položek (docs/ds-setup.md D18/D19): brána
 * dostupnosti (varianta osnovy, extension `accounting_account`), seed
 * nabídky, prerekvizity materializeru a generování per kód. Sdílí ji
 * `POST /_setup/accounting-items` (průvodce nastavením) a `dataset-seed`
 * (`provision.accountingItems` v manifestu sady, #103 D22).
 *
 * Nabídka zůstává jednorázová akce — žádný provisioner; `ds-upgrade`
 * položky nevrací ani nedoplňuje.
 */
class AccountingItemsGenerator
{
    public const ALL = 'all';

    private const ITEMS_TABLE = 'economy_items';

    /**
     * @param array<string, TableDefinition> $tables
     */
    public function __construct(
        private readonly AccountingItemsOffer $offer,
        private readonly AccountingItemMaterializer $materializer,
        private readonly array $tables = [],
    ) {}

    /**
     * Proč nabídka není dostupná; null = dostupná.
     * `accounting_inactive`: sloupec accounting_account je extension
     * z economy.accounting — bez aktivního modulu neexistuje a položky
     * by neměly kam dostat účet.
     *
     * @return 'chart_undecided'|'chart_none'|'accounting_inactive'|null
     */
    public function unavailableReason(): ?string
    {
        $variant = $this->offer->variant();
        if ($variant === null) {
            return AccountingItemsUnavailableException::CHART_UNDECIDED;
        }
        if ($variant === 'none') {
            return AccountingItemsUnavailableException::CHART_NONE;
        }

        $def = $this->tables[self::ITEMS_TABLE] ?? null;
        foreach ($def?->columns ?? [] as $column) {
            if ($column->id === 'accounting_account') {
                return null;
            }
        }
        return AccountingItemsUnavailableException::ACCOUNTING_INACTIVE;
    }

    /**
     * Kódy nabídky aktivní varianty osnovy v pořadí souboru. Klíče seedu
     * s číselným tvarem (568201) drží PHP jako int — cast zpět na string,
     * `materializeOfferCode(string)` je pod strict_types.
     *
     * @return list<string>
     * @throws AccountingItemsUnavailableException
     */
    public function offerCodes(): array
    {
        return array_map(strval(...), array_keys($this->loadSeed()['items']));
    }

    /**
     * Vygeneruje položky nabídky. Prerekvizity (varianta, seed, druh
     * `accounting`, jednotka `pcs`) se ověří před prvním zápisem a selhání
     * je výjimka — nic se nezapsalo. Per kód: existující kód → `skipped`
     * (`already_exists`, opakované generování je bezpečné), účet mimo
     * osnovu → `skipped` (`account_not_found` + `accountNumber`), neznámý
     * kód → `skipped` (`unknown_code`), chyba zápisu → `failed`.
     *
     * @param list<string>|'all' $codes
     * @param bool $stopOnFailure první `failed` ukončí smyčku (fail-fast
     *        setup endpointu); jinak se pokračuje dalším kódem (seed)
     * @return array{
     *     created: list<array{id: int, code: string, name: string}>,
     *     skipped: list<array{code: string, reason: string, accountNumber?: string}>,
     *     failed: list<array{code: string, reason: string, message: string}>,
     * }
     * @throws AccountingItemsUnavailableException
     */
    public function generate(array|string $codes, bool $stopOnFailure = false): array
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new AccountingItemsUnavailableException($reason, $this->offer->variant());
        }
        $seed = $this->loadSeed();
        $prereq = $this->materializer->missingPrerequisite();
        if ($prereq !== null) {
            throw new AccountingItemsUnavailableException($prereq, $this->offer->variant());
        }

        $list = $codes === self::ALL
            ? array_map(strval(...), array_keys($seed['items']))
            : array_values(array_unique(array_map(strval(...), $codes)));

        $created = [];
        $skipped = [];
        $failed = [];
        foreach ($list as $code) {
            $result = $this->materializer->materializeOfferCode($code);
            if ($result['status'] === 'created') {
                $created[] = ['id' => $result['id'], 'code' => $result['code'], 'name' => $result['name']];
                continue;
            }
            if ($result['status'] === 'skipped') {
                $skip = ['code' => $code, 'reason' => $result['reason']];
                if (isset($result['accountNumber'])) {
                    $skip['accountNumber'] = $result['accountNumber'];
                }
                $skipped[] = $skip;
                continue;
            }
            $failed[] = [
                'code'    => $code,
                'reason'  => $result['reason'],
                'message' => $result['message'] ?? $result['reason'],
            ];
            if ($stopOnFailure) {
                break;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @return array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}
     * @throws AccountingItemsUnavailableException
     */
    private function loadSeed(): array
    {
        $variant = $this->offer->variant();
        $seed = $variant !== null ? $this->offer->loadSeed($variant) : null;
        if ($seed === null) {
            throw new AccountingItemsUnavailableException(AccountingItemsUnavailableException::SEED_MISSING, $variant);
        }
        return $seed;
    }
}
