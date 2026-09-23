<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Accounting\AbstractOpenItemLookup;
use Shipard\Core\Accounting\OpenItem;

/**
 * Dohledání otevřeného případu nad saldo deníkem (economy_accbal_ledger) —
 * poskytovatel `openItemLookup` modulu economy.accbal (#69 D3/D8, T1, D19).
 *
 * Cíle plynou z nastavení saldokont, ne z kódu skupiny ani z čísel účtů:
 * cílem je každá skupina s řádkem předpisu (`bal_side = 0`, kladné částky,
 * bez `modify_sign`). Skupina je pro směr **přirozená**, vzniká-li její
 * předpis na straně směru (příjem → MD, výdaj → DAL), jinak **opačná**.
 * Otevřený případ = reziduum > 0 v přirozené skupině (dluh se platí),
 * nebo reziduum < 0 v opačné skupině (přeplatek či dobropis se vrací —
 * D14/D19). Pořadí: přirozené skupiny dle nastavení, pak opačné; první
 * zásah vyhrává. Na seedu příjem prohledá Pohledávky, poskytnuté zálohy,
 * NPO (+), pak Závazky, přijaté zálohy, úvěry (−); výdaj zrcadlově.
 *
 * Řádky klíče se berou na účtech s prefixem některého řádku skupiny bez
 * `modify_sign` (předpis i úhrada) — reziduum = celá skupina jako v
 * {@see CaseQuery}. Přednost nejdelšího prefixu (#69 D22) je věc
 * generátoru: skupinu pohybu nese `balance` v klíči dotazu, prefixy
 * skupiny se jí neúčastní (pohyb z doby před platností přesunutého
 * podúčtu leží v původní skupině a najde se tam). Sign-ruled řádky
 * (dobropis 311 v Závazcích, 321 v Pohledávkách, výchozí seed) jsou
 * lookupu neviditelné: bankovní engine účtuje stranu podle směru, takže
 * úhradu takového předpisu by generátor nedokázal zařadit — vratka
 * dobropisu na výchozím seedu jde na clearing (docs/accbal.md §5.1, §13).
 * Na legacy seedu (bez sign-pravidel) je to celá skupina beze zbytku.
 * Skupina „Nespárované platby“ nemá řádek předpisu → nikdy se neprohledá.
 * Vrácený účet je účet prvního předpisu klíče vč. analytiky (311100,
 * 336101…), u platby bez předpisu účet úhrady; reziduum nese znaménko
 * (záporné = vratka, `bank.md` §6.1). Skupina s `payment_category`
 * (Zálohové faktury vydané → `advances.received`, #79 D3a) předá kategorii
 * v `OpenItem::paymentCategory` — engine úhradu položí na 324, ne na 756;
 * pořadí cílů ani reziduum se tím nemění.
 *
 * Klíč případu = {@see CaseQuery} (skupina, období, partner, VS, SS, měna;
 * #69 D1/D11): normalizovaný vstup, rovnost přes idx_case, prázdný SS
 * `IS NULL`. Reziduum klíče = Σ předpisy − Σ úhrady (v měně dokladu) v
 * rámci období; počítá se v PHP nad řádky klíče ({@see CaseResidual}).
 * Pravidlo 1 z D5; pravidla 2–3 přidá T3.
 *
 * Tentýž agregát klíče (vč. Σ v domácí měně a účtu prvního předpisu)
 * poskytuje {@see caseResidual} contributorům deníku — uzavírání případu
 * úhradou mimo skupinu (`CaseClosureContributor`, #79 D3b/c) čte skupiny
 * s `payment_category` i `closing_category` přes {@see closingGroups}.
 * Jedno SQL, jedna definice cílů.
 */
final class LedgerOpenItemLookup extends AbstractOpenItemLookup
{
    /** Aktivní docState (archivní sada) pro nastavení saldokont. */
    private const ACTIVE_STATES = [10, 40, 80];

    /** Strana, na které předpis pro daný směr vzniká (acc_side: 0 = MD, 1 = DAL); směr 1 = příjem, 2 = výdaj. */
    private const DIRECTION_SIDE = [1 => 0, 2 => 1];

    private const TOLERANCE = 0.005;

    /** @var list<array{balance: int, name: string, request_side: int, prefixes: list<string>, payment_category: ?string, closing_category: ?string}>|null skupiny s předpisem (cache) */
    private ?array $groups = null;

    /** @var array<int, list<array{balance: int, prefixes: list<string>, natural: bool, payment_category: ?string}>> směr → cíle (cache) */
    private array $targets = [];

    public function findOpenRequest(
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        int $direction,
        ?int $fiscalYear,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem {
        $paymentReference = CaseQuery::normalizeSymbol($paymentReference);
        if (
            $this->db === null || $paymentReference === null || $fiscalYear === null
            || !isset(self::DIRECTION_SIDE[$direction])
        ) {
            return null;
        }
        $key = [
            'fiscal_year'       => $fiscalYear,
            'partner'           => $partner,
            'payment_reference' => $paymentReference,
            'specific_symbol'   => CaseQuery::normalizeSymbol($specificSymbol),
            'currency'          => CaseQuery::normalizeCurrency($currency),
        ];

        foreach ($this->targetsForDirection($direction) as $target) {
            $rows = $this->loadKeyRows(
                ['balance' => $target['balance']] + $key,
                $target['prefixes'],
                $excludeSourceKind,
                $excludeSourceId,
            );
            $item = $this->residualOf($target['balance'], $rows, $target['natural'], $target['payment_category']);
            if ($item !== null) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Skupiny, jejichž případ se uzavírá úhradou mimo skupinu (#79 D3b):
     * aktivní skupiny s řádkem předpisu a vyplněnou `payment_category`
     * **i** `closing_category` (na seedu jen Zálohové faktury vydané).
     * Pořadí nastavení.
     *
     * @return list<array{balance: int, name: string, request_side: int, prefixes: list<string>, payment_category: string, closing_category: string}>
     */
    public function closingGroups(): array
    {
        if ($this->db === null) {
            return [];
        }
        $out = [];
        foreach ($this->groups() as $group) {
            if ($group['payment_category'] === null || $group['closing_category'] === null) {
                continue;
            }
            $out[] = $group;
        }
        return $out;
    }

    /**
     * Agregát klíče případu ve skupině — Σ předpisů a úhrad v obou měnách,
     * účet prvního předpisu — nad řádky na účtech s prefixem některého
     * řádku skupiny bez `modify_sign` (týž výběr jako {@see findOpenRequest}).
     * Pohyby `$excludeSourceKind` / `$excludeSourceId` se nepočítají
     * (právě účtovaný zdroj — reaccount bez paměti). Skupina bez předpisu
     * nebo klíč bez pohybů → null. Klíč musí být úplný (bez `balance`).
     *
     * @param array<string, mixed> $key fiscal_year, partner, payment_reference, specific_symbol, currency
     */
    public function caseResidual(int $balance, array $key, ?string $excludeSourceKind = null, ?int $excludeSourceId = null): ?CaseResidual
    {
        if ($this->db === null) {
            return null;
        }
        foreach (CaseQuery::KEY_COLUMNS as $col) {
            if ($col !== 'balance' && !array_key_exists($col, $key)) {
                throw new \InvalidArgumentException("LedgerOpenItemLookup::caseResidual needs a complete case key, missing '{$col}'");
            }
        }
        $group = null;
        foreach ($this->groups() as $g) {
            if ($g['balance'] === $balance) {
                $group = $g;
                break;
            }
        }
        if ($group === null) {
            return null;
        }
        $rows = $this->loadKeyRows(
            ['balance' => $balance] + $key,
            $group['prefixes'],
            $excludeSourceKind,
            $excludeSourceId,
        );
        return $rows === [] ? null : CaseResidual::fromRows($rows);
    }

    /**
     * Skupiny s řádkem předpisu, v pořadí nastavení: strana předpisu
     * (acc_side prvního řádku `bal_side = 0`, kladné částky, bez
     * modify_sign) a prefixy všech řádků skupiny bez modify_sign. Skupina
     * bez předpisu nebo bez prefixu není cíl. Kategorie úhrady skupiny
     * (`payment_category`, #79 D3a) a kategorie uzavření (`closing_category`,
     * #79 D3b) jdou do cíle beze změny pořadí.
     * Nastavení se čte jednou per instance.
     *
     * @return list<array{balance: int, name: string, request_side: int, prefixes: list<string>, payment_category: ?string, closing_category: ?string}>
     */
    private function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $rows = $this->db->fetchAll(
            'SELECT a.[balance], a.[account_number], a.[acc_side], a.[bal_side], a.[modify_sign], a.[amounts_sign],
                    b.[name], b.[payment_category], b.[closing_category]
             FROM [economy_accbal_balance_accounts] a
             JOIN [economy_accbal_balances] b ON b.[id] = a.[balance]
             WHERE a.[docState] IN %in AND b.[docState] IN %in
             ORDER BY b.[sort_order], a.[sort_order], a.[id]',
            self::ACTIVE_STATES,
            self::ACTIVE_STATES,
        );

        /** @var array<int, array{balance: int, name: string, request_side: ?int, prefixes: list<string>, payment_category: ?string, closing_category: ?string}> $byBalance */
        $byBalance = [];
        foreach ($rows as $r) {
            $balance = (int) $r['balance'];
            $category = trim((string) ($r['payment_category'] ?? ''));
            $closing  = trim((string) ($r['closing_category'] ?? ''));
            $byBalance[$balance] ??= [
                'balance'          => $balance,
                'name'             => trim((string) ($r['name'] ?? '')),
                'request_side'     => null,
                'prefixes'         => [],
                'payment_category' => $category !== '' ? $category : null,
                'closing_category' => $closing !== '' ? $closing : null,
            ];
            if (!empty($r['modify_sign'])) {
                continue;
            }
            $prefix = trim((string) $r['account_number']);
            if ($prefix !== '' && !in_array($prefix, $byBalance[$balance]['prefixes'], true)) {
                $byBalance[$balance]['prefixes'][] = $prefix;
            }
            if ((int) $r['bal_side'] === 0 && in_array((int) ($r['amounts_sign'] ?? 0), [0, 1], true)) {
                $byBalance[$balance]['request_side'] ??= (int) $r['acc_side'];
            }
        }

        $out = [];
        foreach ($byBalance as $group) {
            if ($group['request_side'] === null || $group['prefixes'] === []) {
                continue;
            }
            $out[] = [
                'balance'          => $group['balance'],
                'name'             => $group['name'],
                'request_side'     => $group['request_side'],
                'prefixes'         => $group['prefixes'],
                'payment_category' => $group['payment_category'],
                'closing_category' => $group['closing_category'],
            ];
        }

        return $this->groups = $out;
    }

    /**
     * Cíle pro směr: přirozené skupiny (předpis na straně směru) v pořadí
     * nastavení, pak opačné. První zásah vyhrává.
     *
     * @return list<array{balance: int, prefixes: list<string>, natural: bool, payment_category: ?string}>
     */
    private function targetsForDirection(int $direction): array
    {
        if (isset($this->targets[$direction])) {
            return $this->targets[$direction];
        }

        $natural = [];
        $opposite = [];
        foreach ($this->groups() as $group) {
            $isNatural = $group['request_side'] === self::DIRECTION_SIDE[$direction];
            $target = [
                'balance'          => $group['balance'],
                'prefixes'         => $group['prefixes'],
                'natural'          => $isNatural,
                'payment_category' => $group['payment_category'],
            ];
            if ($isNatural) {
                $natural[] = $target;
            } else {
                $opposite[] = $target;
            }
        }

        return $this->targets[$direction] = [...$natural, ...$opposite];
    }

    /**
     * Řádky ledgeru klíče ve skupině — na účtech s prefixem některého
     * řádku skupiny bez modify_sign. Rovnost klíče přes
     * {@see CaseQuery::keyConditions} (idx_case).
     *
     * @param array<string, mixed> $key úplný klíč případu vč. balance
     * @param non-empty-list<string> $prefixes
     * @return list<array<string, mixed>|\Dibi\Row>
     */
    private function loadKeyRows(
        array $key,
        array $prefixes,
        ?string $excludeSourceKind,
        ?int $excludeSourceId,
    ): array {
        [$conds, $args] = CaseQuery::keyConditions($key, 'l');

        $conds[] = '(' . implode(' OR ', array_fill(0, count($prefixes), 'l.[account_number] LIKE %like~')) . ')';
        $args = [...$args, ...$prefixes];

        if ($excludeSourceKind !== null && $excludeSourceId !== null) {
            $conds[] = 'NOT (l.[source_kind] = %s AND l.[source_id] = %i)';
            $args[]  = $excludeSourceKind;
            $args[]  = $excludeSourceId;
        }

        return $this->db->fetchAll(
            'SELECT l.[id], l.[bal_side], l.[account_number], l.[amount], l.[amount_hc]
             FROM [economy_accbal_ledger] l
             WHERE ' . implode(' AND ', $conds) . '
             ORDER BY l.[id]',
            ...$args,
        );
    }

    /**
     * Σ předpisy − Σ úhrady se znaménkem; otevřený = > 0 v přirozené
     * skupině, < 0 v opačné (vratka). Účet = první předpis klíče, u platby
     * bez předpisu první řádek klíče; kategorie úhrady skupiny se předá dál.
     *
     * @param list<array<string, mixed>|\Dibi\Row> $rows
     */
    private function residualOf(int $balance, array $rows, bool $natural, ?string $paymentCategory = null): ?OpenItem
    {
        if ($rows === []) {
            return null;
        }
        $case = CaseResidual::fromRows($rows);
        $residual = $case->residual();
        $open = $natural ? $residual > self::TOLERANCE : $residual < -self::TOLERANCE;
        if (!$open) {
            return null;
        }
        return new OpenItem($balance, $case->firstRequestAccount ?? $case->firstAccount, $residual, $paymentCategory);
    }
}
