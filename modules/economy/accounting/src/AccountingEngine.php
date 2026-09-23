<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalLineView;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\JournalEventDispatcher;

/**
 * Generátor účetního deníku z dokladu — obecný interpret deklarativního
 * účtovacího předpisu (cfgItem `economy.accounting.rules.{country}`).
 *
 * Vstup: id hlavičky dokladu (head + rows + vat_recap si načte z DB —
 * Fáze 1 garantuje, že computed sloupce vč. _dom jsou v DB aktuální).
 * Výstup: přepsané řádky deníku (DELETE + INSERT, idempotentní) +
 * aktualizované accounting_state / accounting_messages na hlavičce.
 * Vše v jedné transakci.
 *
 * Filozofie chyb: účtování nikdy neblokuje doklad. Nedohledaný účet →
 * chybový řádek deníku (account NULL, maska s '?', is_error) + message;
 * fatálnější problémy (chybí předpis, fiskální období) → prázdný deník.
 * Výsledek vždy accounting_state 1 (OK) / 2 (chybová zpráva v messages;
 * zpráva úrovně `warning` stav nemění).
 *
 * Contributoři deníku (#79 D3b, `journalContributors` v module.jsonc):
 * po seskupení vlastních řádků engine předá kontext a pohledy na řádky
 * registrovaným contributorům, jejich požadavky převede na řádky (účet
 * dle kategorie nebo přesného čísla, identita z požadavku, operace NULL)
 * a znovu seskupí — pak teprve kontrola vyrovnanosti a zápis. Prázdná
 * sada = chování beze změny; výjimka contributoru = varování
 * `contributor_failed`, deník bez příspěvku.
 *
 * Algoritmus a sémantika kroků předpisu: docs/accounting.md sekce 4, 5, 7.3.
 *
 * Reverse charge: primární řádek rekapitulace nese odpočet (účtuje se na
 * stranu kroku), oddaňovací pár (`is_reverse_pair = 1`) na stranu opačnou —
 * deník je vyrovnaný a obě strany dostanou analytiku svého vat kódu.
 */
final class AccountingEngine
{
    /** Délka čísla účtu pro chybovou masku (504 → '504???'). */
    private const ACCOUNT_NUMBER_LENGTH = 6;

    private const ITEM_TYPE_ACC_ENTRY = 2;

    /**
     * Stavy, ve kterých je účet rozvrhu odkazovatelný — historické doklady
     * smí účtovat na archivní (70) účty, vyloučen je jen smazaný (90).
     * Stejná konvence jako `StatementImportService::LINKABLE_STATES`.
     */
    private const LINKABLE_STATES = [10, 40, 70, 80];

    /** Per-run dohledávač účtů dle masky (cache se nuluje s novým během). */
    private AccountMaskResolver $maskResolver;

    /** @var list<array{code: string, message: string, rowId: int|null, level?: string}> */
    private array $messages = [];

    private readonly JournalContributorSet $contributors;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?JournalEventDispatcher $journalEvents = null,
        ?JournalContributorSet $contributors = null,
    ) {
        // DS bez přispívajícího modulu (nebo engine postavený bez sady) → beze změny.
        $this->contributors = $contributors ?? JournalContributorSet::empty();
    }

    /**
     * Přeúčtuje doklad: smaže starý deník, vygeneruje nový, uloží stav.
     *
     * @return array{state: int, messages: list<array{code: string, message: string, rowId: int|null, level?: string}>}
     */
    public function accountDocument(int $docHeadId): array
    {
        $this->messages = [];
        $this->maskResolver = new AccountMaskResolver($this->db);

        $headRow = $this->db->fetch(
            'SELECT * FROM [docs_core_heads] WHERE [id] = %i',
            $docHeadId,
        );
        if ($headRow === null) {
            throw new \DomainException("Doklad #{$docHeadId} nenalezen");
        }
        $head = $headRow->toArray();

        $steps = $this->resolveSteps((string) ($head['doc_type'] ?? ''));
        if ($steps === null) {
            $this->addMessage(
                'rules_not_found',
                sprintf(
                    'Účtovací předpis pro typ dokladu %s nenalezen',
                    (string) ($head['doc_type'] ?? '?'),
                ),
            );
            return $this->writeResult($docHeadId, []);
        }

        if (empty($head['fiscal_year']) || empty($head['fiscal_month'])) {
            $this->addMessage(
                'fiscal_period_missing',
                'Doklad nemá přiřazený fiskální rok/měsíc — zkontroluj účetní datum a číselník období',
            );
            return $this->writeResult($docHeadId, []);
        }

        $rows = $this->loadRows($docHeadId);
        $recap = $this->loadVatRecap($docHeadId);

        $lines = [];
        foreach ($steps as $step) {
            foreach ($this->buildStepLines($step, $head, $rows, $recap) as $line) {
                $lines[] = $line;
            }
        }

        $grouped = $this->groupLines($lines);

        if ($grouped === []) {
            $this->addMessage('empty_journal', 'Z dokladu nevznikl žádný řádek deníku');
            return $this->writeResult($docHeadId, []);
        }

        $grouped = $this->applyContributions($docHeadId, $head, $grouped);

        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($grouped as $line) {
            $sumDr += $line['money_dr'];
            $sumCr += $line['money_cr'];
        }
        if (round($sumDr, 2) !== round($sumCr, 2)) {
            $this->addMessage(
                'unbalanced',
                sprintf('Deník není vyrovnaný: MD %.2f ≠ DAL %.2f', $sumDr, $sumCr),
            );
        }

        return $this->writeResult($docHeadId, $grouped, $head);
    }

    // ── Předpis ─────────────────────────────────────────────────────────────

    /**
     * Kroky předpisu pro docType — předpis dle země vlastní firmy,
     * fallback cz. Vrací null, když předpis nebo docType sekce chybí.
     *
     * @return list<array<string, mixed>>|null
     */
    private function resolveSteps(string $docType): ?array
    {
        $rules = $this->resolveRules();
        if ($rules === null || $docType === '') {
            return null;
        }
        foreach ($rules['documents'] ?? [] as $doc) {
            if (is_array($doc) && ($doc['docType'] ?? null) === $docType) {
                $steps = $doc['accounting'] ?? null;
                return is_array($steps) ? array_values($steps) : null;
            }
        }
        return null;
    }

    /**
     * Předpis dle země vlastní firmy, fallback cz ({@see AccountingRules}).
     *
     * @return array<string, mixed>|null
     */
    private function resolveRules(): ?array
    {
        return AccountingRules::resolve($this->config, $this->db);
    }

    // ── Kroky → kandidátní řádky ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $recap
     * @return list<array<string, mixed>>
     */
    private function buildStepLines(array $step, array $head, array $rows, array $recap): array
    {
        // headQuery: filtr nad hlavičkou pro libovolný src — `query` se u
        // rows/vat kroků vyhodnocuje nad řádkem / rekapitulací, takže bez
        // něj nejde v jednom bloku rozlišit strany podle cash_dir (#59 D8).
        if (isset($step['headQuery']) && !$this->matchesQuery(['query' => $step['headQuery']], $head)) {
            return [];
        }

        $src = (string) ($step['src'] ?? '');
        return match ($src) {
            'rows' => $this->buildRowLines($step, $head, $rows),
            'vat'  => $this->buildVatLines($step, $head, $recap),
            'head' => $this->buildHeadLines($step, $head),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRowLines(array $step, array $head, array $rows): array
    {
        $allowedOps = null;
        if (isset($step['operation'])) {
            $allowedOps = [(string) $step['operation']];
        } elseif (isset($step['operations']) && is_array($step['operations'])) {
            $allowedOps = array_map(strval(...), $step['operations']);
        }

        $lines = [];
        foreach ($rows as $row) {
            $operation = (string) ($row['operation'] ?? '');
            if ($allowedOps !== null && !in_array($operation, $allowedOps, true)) {
                continue;
            }
            if (!$this->matchesQuery($step, $row)) {
                continue;
            }

            $dom = (float) ($row['vat_base_dom'] ?? 0);
            $cur = (float) ($row['vat_base'] ?? 0);
            if (!$this->passesSignAndReverse($step, $dom, $cur)) {
                continue;
            }
            if ($dom === 0.0 && $cur === 0.0) {
                continue;
            }

            $rowId = (int) $row['id'];
            $account = match ($step['accountSrc'] ?? null) {
                'row'   => $this->resolveRowAccount($row, $rowId),
                'item'  => $this->resolveItemAccount($row, $rowId),
                default => $this->resolveCategoryAccount($step, $row, $head, $rowId),
            };

            // Strana z řádku (sideSrc:'row' → acc_side), jinak ze kroku.
            $lineStep = $step;
            if (($step['sideSrc'] ?? null) === 'row') {
                $lineStep['side'] = (int) ($row['acc_side'] ?? 0);
            }

            $lines[] = $this->makeLine(
                $lineStep,
                $head,
                $account,
                $dom,
                $cur,
                text: (string) ($step['text'] ?? $row['description'] ?? ''),
                operation: $operation !== '' ? $operation : null,
                rowId: $rowId,
                identity: $this->resolveRowIdentity($row, $head, $operation),
            );
        }
        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVatLines(array $step, array $head, array $recap): array
    {
        $lines = [];
        foreach ($recap as $r) {
            // Odvozené pole pro query kroků i mapování účtů (cz-110 → cz).
            // Nepersistuje se — existuje jen po dobu matchování; umožňuje
            // omezit fallback masky na tuzemské kódy (viz accounts sekce
            // předpisu), aby zahraniční kód bez mapování selhal hlasitě.
            $r['vat_code_country'] = self::vatCodeCountry((string) ($r['vat_code'] ?? ''));

            if (!$this->matchesQuery($step, $r)) {
                continue;
            }
            $dom = (float) ($r['tax_dom'] ?? 0);
            $cur = (float) ($r['tax'] ?? 0);
            if (!$this->passesSignAndReverse($step, $dom, $cur)) {
                continue;
            }
            if ($dom === 0.0 && $cur === 0.0) {
                continue;
            }

            $defaultText = sprintf(
                'DPH %s %s%%',
                (string) ($r['vat_code'] ?? ''),
                rtrim(rtrim(number_format((float) ($r['vat_pct'] ?? 0), 2, '.', ''), '0'), '.'),
            );

            // Oddaňovací pár reverse charge jde na opačnou stranu než krok —
            // primární řádek nese odpočet (strana kroku), pár samovyměření.
            // Nezávislé na reverseSign (ten otáčí znaménko částky).
            $lineStep = $step;
            if (!empty($r['is_reverse_pair'])) {
                $lineStep['side'] = ((int) ($step['side'] ?? 0)) === 0 ? 1 : 0;
            }

            $lines[] = $this->makeLine(
                $lineStep,
                $head,
                $this->resolveCategoryAccount($step, $r, $head, null),
                $dom,
                $cur,
                text: (string) ($step['text'] ?? $defaultText),
                operation: null,
                rowId: null,
            );
        }
        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHeadLines(array $step, array $head): array
    {
        if (!$this->matchesQuery($step, $head)) {
            return [];
        }

        $col = (string) ($step['col'] ?? 'total');
        [$dom, $cur] = $col === 'rounding'
            ? [(float) ($head['total_rounding_dom'] ?? 0), (float) ($head['total_rounding'] ?? 0)]
            : [(float) ($head['total_amount_dom'] ?? 0), (float) ($head['total_amount'] ?? 0)];

        if (!$this->passesSignAndReverse($step, $dom, $cur)) {
            return [];
        }
        if ($dom === 0.0 && $cur === 0.0) {
            return [];
        }

        $account = match ($step['accountSrc'] ?? null) {
            'cashDesk' => $this->resolveCashDeskAccount($head),
            default    => $this->resolveCategoryAccount($step, $head, $head, null),
        };

        return [$this->makeLine(
            $step,
            $head,
            $account,
            $dom,
            $cur,
            text: (string) ($step['text'] ?? $head['doc_text'] ?? ''),
            operation: null,
            rowId: null,
            identity: $this->headStepIdentity($step, $head),
        )];
    }

    /**
     * Identita hlavičkového kroku dle `partnerSrc` (#72 D3): `"balance"` =
     * saldokontní řádek za osobou pro saldokonto (`partner_balance`, fallback
     * partner hlavičky — DS bez terminálů účtuje jako dřív); bez atributu
     * identita hlavičky (null → makeLine ji doplní sám). VS/SS/KS/splatnost
     * zůstávají z hlavičky vždy.
     *
     * @return array{partner: int|null, payment_reference: ?string, specific_symbol: ?string, constant_symbol: ?string, due_date: ?string}|null
     */
    private function headStepIdentity(array $step, array $head): ?array
    {
        $src = $step['partnerSrc'] ?? null;
        if ($src === null) {
            return null;
        }
        if ($src !== 'balance') {
            throw new \LogicException("Účtovací předpis: neznámý partnerSrc '{$src}' (podporováno: balance)");
        }
        $identity = $this->headIdentity($head);
        $balance = isset($head['partner_balance']) && $head['partner_balance'] !== null
            ? (int) $head['partner_balance'] : 0;
        if ($balance > 0) {
            $identity['partner'] = $balance;
        }
        return $identity;
    }

    /**
     * Účet pokladny z hlavičky (accountSrc: "cashDesk", #59 D8):
     * head.cash_desk → economy_codebooks_cash_desks.accounting_account
     * (extension economy.accounting, 211xxx) → účet rozvrhu. Chybějící
     * pokladna (faktura s Hotovostí bez pokladny) nebo účet → chybový řádek
     * 211??? + message cash_desk_account_missing; alert vzniká z
     * accounting_state 2 (AccountingErrorsCheck). Vzor
     * BankTransactionAccountingEngine::resolveBankAccount.
     *
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveCashDeskAccount(array $head): array
    {
        $errorAccount = ['number' => str_pad('211', self::ACCOUNT_NUMBER_LENGTH, '?'), 'is_error' => true];

        $cashDeskId = (int) ($head['cash_desk'] ?? 0);
        if ($cashDeskId === 0) {
            $this->addMessage(
                'cash_desk_account_missing',
                'Doklad je hrazen hotově, ale nemá pokladnu — doplň pokladnu a přeúčtuj',
            );
            return $errorAccount;
        }

        $desk = $this->db->fetch(
            'SELECT [accounting_account] FROM [economy_codebooks_cash_desks] WHERE [id] = %i',
            $cashDeskId,
        );
        $accountId = $desk !== null ? (int) ($desk['accounting_account'] ?? 0) : 0;
        $account = $accountId > 0
            ? $this->db->fetch(
                'SELECT [id], [number] FROM [economy_accounting_accounts]
                 WHERE [id] = %i AND [docState] IN %in',
                $accountId,
                self::LINKABLE_STATES,
            )
            : null;

        if ($account === null) {
            $this->addMessage(
                'cash_desk_account_missing',
                'Pokladna nemá vyplněný nebo platný účet pro pohyby (211xxx)',
            );
            return $errorAccount;
        }

        return ['id' => (int) $account['id'], 'number' => (string) $account['number']];
    }

    /** Část vat kódu před první pomlčkou, lowercase (`cz-110` → `cz`). */
    private static function vatCodeCountry(string $vatCode): string
    {
        $dash = strpos($vatCode, '-');
        return strtolower($dash === false ? $vatCode : substr($vatCode, 0, $dash));
    }

    /**
     * Filtr `sign` ('+' jen kladné, '-' jen záporné, hodnotí se domácí
     * částka; při nulové domácí rozhoduje cur) a `reverseSign` (otočení
     * znaménka obou částek — modifikuje $dom/$cur přes referenci).
     */
    private function passesSignAndReverse(array $step, float &$dom, float &$cur): bool
    {
        $probe = $dom !== 0.0 ? $dom : $cur;
        $sign = $step['sign'] ?? null;
        if ($sign === '+' && $probe <= 0.0) {
            return false;
        }
        if ($sign === '-' && $probe >= 0.0) {
            return false;
        }
        if (!empty($step['reverseSign'])) {
            $dom = -$dom;
            $cur = -$cur;
        }
        return true;
    }

    /**
     * Obecný filtr `query` {sloupec: hodnota} nad zdrojovým záznamem —
     * volné porovnání (DB vrací stringy, předpis píše čísla). Hodnota-pole
     * je operátorový objekt: `{"$ne": v}` (nerovnost), `{"$in": [v, …]}`.
     * Neznámý operátor je chyba předpisu a padá hlasitě — tiché „nikdy
     * nematchne" by krok jen zmizel. Sdílené kroky i `accounts[]` kategorie.
     */
    private function matchesQuery(array $step, array $record): bool
    {
        $query = $step['query'] ?? null;
        if (!is_array($query)) {
            return true;
        }
        foreach ($query as $col => $expected) {
            $actual = $record[$col] ?? null;
            if (is_array($expected)) {
                foreach ($expected as $op => $value) {
                    $ok = match ((string) $op) {
                        '$ne'   => $actual != $value,
                        '$in'   => in_array($actual, is_array($value) ? $value : [$value]),
                        default => throw new \LogicException(
                            "Účtovací předpis: neznámý operátor '{$op}' ve query sloupce '{$col}'",
                        ),
                    };
                    if (!$ok) {
                        return false;
                    }
                }
                continue;
            }
            if ($actual != $expected) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{id: int, number: string, is_error?: bool}|array{number: string, is_error: bool} $account
     * @param array{partner: int|null, payment_reference: ?string, specific_symbol: ?string, constant_symbol: ?string, due_date: ?string}|null $identity
     *        Per-řádková identita; null → odvodí se z hlavičky (vat/head zdroje).
     */
    private function makeLine(
        array $step,
        array $head,
        array $account,
        float $dom,
        float $cur,
        string $text,
        ?string $operation,
        ?int $rowId,
        ?array $identity = null,
    ): array {
        $side = (int) ($step['side'] ?? 0);
        $identity ??= $this->headIdentity($head);
        return [
            'side'           => $side,
            'account'        => $account['id'] ?? null,
            'account_number' => $account['number'],
            'is_error'       => !empty($account['is_error']),
            'operation'      => $operation,
            'partner'           => $identity['partner'],
            'payment_reference' => $identity['payment_reference'],
            'specific_symbol'   => $identity['specific_symbol'],
            'constant_symbol'   => $identity['constant_symbol'],
            'due_date'          => $identity['due_date'],
            'text'           => mb_substr($text, 0, 200),
            'money_dr'       => $side === 0 ? round($dom, 2) : 0.0,
            'money_cr'       => $side === 1 ? round($dom, 2) : 0.0,
            'money_dr_cur'   => $side === 0 ? round($cur, 2) : 0.0,
            'money_cr_cur'   => $side === 1 ? round($cur, 2) : 0.0,
            'rowId'          => $rowId,
        ];
    }

    /**
     * Platební + saldo identita z hlavičky — fallback pro řádky bez vlajek
     * a pro vat/head zdroje (zachovává chování faktur).
     *
     * @return array{partner: int|null, payment_reference: ?string, specific_symbol: ?string, constant_symbol: ?string, due_date: ?string}
     */
    private function headIdentity(array $head): array
    {
        return [
            'partner' => isset($head['partner']) && $head['partner'] !== null
                ? (int) $head['partner'] : null,
            'payment_reference' => $head['payment_reference'] ?? null,
            'specific_symbol'   => $head['specific_symbol'] ?? null,
            'constant_symbol'   => $head['constant_symbol'] ?? null,
            'due_date'          => $this->normalizeDate($head['due_date'] ?? null),
        ];
    }

    /**
     * Per-řádková identita dle vlajek operace (docs.core.rowOperations):
     * rowPartner → partner z řádku, rowPaymentId → platební symboly +
     * splatnost z řádku. Bez vlajky (faktury) → vše z hlavičky.
     *
     * @return array{partner: int|null, payment_reference: ?string, specific_symbol: ?string, constant_symbol: ?string, due_date: ?string}
     */
    private function resolveRowIdentity(array $row, array $head, string $operation): array
    {
        $ops = $this->config?->cfgItem('docs.core.rowOperations');
        $opCfg = is_array($ops) ? ($ops[$operation] ?? null) : null;

        $identity = $this->headIdentity($head);

        if (is_array($opCfg) && !empty($opCfg['rowPartner'])) {
            $identity['partner'] = isset($row['partner'])
                && $row['partner'] !== null && $row['partner'] !== ''
                ? (int) $row['partner'] : null;
        }
        if (is_array($opCfg) && !empty($opCfg['rowPaymentId'])) {
            $identity['payment_reference'] = $row['payment_reference'] ?? null;
            $identity['specific_symbol']   = $row['specific_symbol'] ?? null;
            $identity['constant_symbol']   = $row['constant_symbol'] ?? null;
            $identity['due_date']          = $this->normalizeDate($row['due_date'] ?? null);
        }
        return $identity;
    }

    /** Datum jako 'Y-m-d' string (group key i INSERT) — DB date / DateTime / null. */
    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return (string) $value;
    }

    // ── Dohledávání účtů ────────────────────────────────────────────────────

    /**
     * Účet přímo z položky řádku (pohyb acc.entry). Měkká kontrola:
     * položka musí být typ 2 (Účetní položka) a mít vyplněný účet —
     * jinak chybový řádek (konfigurace položky se může změnit nezávisle
     * na dokladu).
     *
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveItemAccount(array $row, int $rowId): array
    {
        $itemId = (int) ($row['item'] ?? 0);
        $item = $itemId > 0
            ? $this->db->fetch(
                'SELECT [item_type], [accounting_account] FROM [economy_items] WHERE [id] = %i',
                $itemId,
            )
            : null;

        $accountId = $item !== null ? (int) ($item['accounting_account'] ?? 0) : 0;
        if ($item === null
            || (int) ($item['item_type'] ?? 0) !== self::ITEM_TYPE_ACC_ENTRY
            || $accountId === 0
        ) {
            $this->addMessage(
                'item_account_missing',
                'Položka řádku není typu Účetní položka nebo nemá vyplněný účet',
                $rowId,
            );
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        $account = $this->db->fetch(
            'SELECT [id], [number] FROM [economy_accounting_accounts]
             WHERE [id] = %i AND [docState] IN %in',
            $accountId,
            self::LINKABLE_STATES,
        );
        if ($account === null) {
            $this->addMessage(
                'item_account_missing',
                'Účet uvedený na položce řádku v rozvrhu neexistuje',
                $rowId,
            );
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        return ['id' => (int) $account['id'], 'number' => (string) $account['number']];
    }

    /**
     * Účet přímo z řádku dokladu (pohyb acc.record, accountSrc:'row').
     * Nevyplněný / v rozvrhu neexistující či smazaný → chybový řádek (maska
     * '??????', is_error) + message — stejný vzor jako resolveItemAccount.
     * Archivní účet (70) se dohledá — historická data, viz LINKABLE_STATES.
     *
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveRowAccount(array $row, int $rowId): array
    {
        $accountId = (int) ($row['account'] ?? 0);
        if ($accountId === 0) {
            $this->addMessage(
                'row_account_missing',
                'Řádek účetního zápisu nemá vyplněný účet',
                $rowId,
            );
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        $account = $this->db->fetch(
            'SELECT [id], [number] FROM [economy_accounting_accounts]
             WHERE [id] = %i AND [docState] IN %in',
            $accountId,
            self::LINKABLE_STATES,
        );
        if ($account === null) {
            $this->addMessage(
                'row_account_missing',
                'Účet uvedený na řádku v rozvrhu neexistuje',
                $rowId,
            );
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        return ['id' => (int) $account['id'], 'number' => (string) $account['number']];
    }

    /**
     * Kategorie kroku → masky (první záznam v accounts se shodnou cat a
     * vyhovující query nad zdrojovým záznamem) → účet v rozvrhu.
     * `accountMask` smí být řetězec nebo pole — řetěz masek zkoušených po
     * řadě, první dohledaná vyhrává (fallback pro rozvrhy bez jemnější
     * analytiky, např. 3249 → 324).
     *
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveCategoryAccount(array $step, array $record, array $head, ?int $rowId): array
    {
        $cat = (string) ($step['cat'] ?? '');
        $rules = $this->resolveRules();

        $masks = [];
        foreach ($rules['accounts'] ?? [] as $entry) {
            if (!is_array($entry) || ($entry['cat'] ?? null) !== $cat) {
                continue;
            }
            if (!$this->matchesQuery($entry, $record)) {
                continue;
            }
            $masks = self::masksOf($entry);
            break;
        }

        if ($masks === []) {
            $this->addMessage(
                'account_not_found',
                "Předpis nemá masku pro kategorii '{$cat}'",
                $rowId,
            );
            // Display-only: syntetika z poslední (nejobecnější) masky
            // kategorie, ať chybový řádek ukazuje aspoň oblast (343???).
            $hint = '';
            foreach ($rules['accounts'] ?? [] as $entry) {
                if (is_array($entry) && ($entry['cat'] ?? null) === $cat) {
                    $hint = substr(self::masksOf($entry)[0] ?? '', 0, 3);
                }
            }
            return [
                'number'   => str_pad($hint, self::ACCOUNT_NUMBER_LENGTH, '?'),
                'is_error' => true,
            ];
        }

        foreach ($masks as $mask) {
            $account = $this->maskResolver->resolve($mask, (string) ($head['accounting_date'] ?? ''));
            if ($account !== null) {
                return $account;
            }
        }

        $this->addMessage(
            'account_not_found',
            'Účet nenalezen pro masku ' . implode(', ', $masks),
            $rowId,
        );
        return [
            'number'   => str_pad($masks[0], self::ACCOUNT_NUMBER_LENGTH, '?'),
            'is_error' => true,
        ];
    }

    /**
     * Masky záznamu accounts jako neprázdný seznam — `accountMask` je
     * řetězec nebo pole řetězců; prázdné hodnoty se zahodí.
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function masksOf(array $entry): array
    {
        $raw = $entry['accountMask'] ?? [];
        $masks = [];
        foreach (is_array($raw) ? $raw : [$raw] as $mask) {
            if ((string) $mask !== '') {
                $masks[] = (string) $mask;
            }
        }
        return $masks;
    }

    // ── Contributoři deníku (#79 D3b) ───────────────────────────────────────

    /**
     * Krok contributorů: kontext zdroje + pohledy na seskupené řádky bez
     * chyby → požadavky → řádky deníku (účet dle kategorie / přesného
     * čísla, identita z požadavku, operace NULL, bez vazby na řádek
     * dokladu) → nové seskupení se stávajícími řádky. Prázdná sada nebo
     * žádný požadavek → vstup beze změny.
     *
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>> $grouped
     * @return list<array<string, mixed>>
     */
    private function applyContributions(int $docHeadId, array $head, array $grouped): array
    {
        if ($this->contributors->isEmpty()) {
            return $grouped;
        }

        $accountingDate = (string) ($this->normalizeDate($head['accounting_date'] ?? null) ?? '');
        $context = new JournalSourceContext(
            'doc',
            $docHeadId,
            $accountingDate,
            (int) $head['fiscal_year'],
            strtolower(trim((string) ($head['doc_currency'] ?? ''))),
        );

        $views = [];
        foreach ($grouped as $line) {
            if ($line['is_error']) {
                continue;
            }
            $views[] = self::lineView($line);
        }

        $requests = JournalContributions::collect(
            $this->contributors,
            $context,
            $views,
            fn(string $code, string $message) => $this->addMessage($code, $message, null, 'warning'),
        );
        if ($requests === []) {
            return $grouped;
        }

        $rules = $this->resolveRules();
        $lines = $grouped;
        foreach ($requests as $request) {
            $account = JournalContributions::resolveAccount(
                $request,
                $rules,
                $this->maskResolver,
                $accountingDate,
                fn(string $code, string $message) => $this->addMessage($code, $message),
            );
            $lines[] = $this->makeContributedLine($request, $account);
        }
        return $this->groupLines($lines);
    }

    /**
     * Pohled na seskupený řádek pro contributory — částka strany, kterou
     * řádek nese, identita z řádku.
     *
     * @param array<string, mixed> $line
     */
    private static function lineView(array $line): JournalLineView
    {
        $side = (int) $line['side'];
        return new JournalLineView(
            $side,
            (string) $line['account_number'],
            $line['operation'] ?? null,
            $line['partner'] ?? null,
            $line['payment_reference'] ?? null,
            $line['specific_symbol'] ?? null,
            (float) ($side === 0 ? $line['money_dr'] : $line['money_cr']),
            (float) ($side === 0 ? $line['money_dr_cur'] : $line['money_cr_cur']),
        );
    }

    /**
     * Řádek deníku z požadavku contributoru — tvar jako makeLine, identita
     * z požadavku (KS a splatnost nemá), operace NULL, bez řádku dokladu.
     *
     * @param array{id?: int, number: string, is_error?: bool} $account
     * @return array<string, mixed>
     */
    private function makeContributedLine(JournalLineRequest $request, array $account): array
    {
        $side = $request->side;
        return [
            'side'              => $side,
            'account'           => $account['id'] ?? null,
            'account_number'    => $account['number'],
            'is_error'          => !empty($account['is_error']),
            'operation'         => null,
            'partner'           => $request->partner,
            'payment_reference' => $request->paymentReference,
            'specific_symbol'   => $request->specificSymbol,
            'constant_symbol'   => null,
            'due_date'          => null,
            'text'              => mb_substr($request->text, 0, 200),
            'money_dr'          => $side === 0 ? round($request->moneyDom, 2) : 0.0,
            'money_cr'          => $side === 1 ? round($request->moneyDom, 2) : 0.0,
            'money_dr_cur'      => $side === 0 ? round($request->moneyCur, 2) : 0.0,
            'money_cr_cur'      => $side === 1 ? round($request->moneyCur, 2) : 0.0,
            'rowId'             => null,
        ];
    }

    // ── Seskupení a zápis ───────────────────────────────────────────────────

    /**
     * Seskupení klíčem (side, account_number, partner, operation + platební
     * identita) — shodné řádky se sčítají (dom i cur), text z prvního řádku
     * skupiny. Platební identita v klíči (D7) brání slévání saldokontních
     * řádků na stejný účet s různým VS/SS/KS/splatností (zápočet, mzdy);
     * u faktur je identita napříč řádky konstantní → klíč beze změny.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function groupLines(array $lines): array
    {
        $grouped = [];
        foreach ($lines as $line) {
            $key = implode('|', [
                $line['side'],
                $line['account_number'],
                $line['partner'] ?? '',
                $line['operation'] ?? '',
                $line['payment_reference'] ?? '',
                $line['specific_symbol'] ?? '',
                $line['constant_symbol'] ?? '',
                $line['due_date'] ?? '',
            ]);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $line;
                continue;
            }
            $grouped[$key]['money_dr']     = round($grouped[$key]['money_dr'] + $line['money_dr'], 2);
            $grouped[$key]['money_cr']     = round($grouped[$key]['money_cr'] + $line['money_cr'], 2);
            $grouped[$key]['money_dr_cur'] = round($grouped[$key]['money_dr_cur'] + $line['money_dr_cur'], 2);
            $grouped[$key]['money_cr_cur'] = round($grouped[$key]['money_cr_cur'] + $line['money_cr_cur'], 2);
            $grouped[$key]['is_error']     = $grouped[$key]['is_error'] || $line['is_error'];
        }
        return array_values($grouped);
    }

    /**
     * DELETE starého deníku + INSERT nových řádků + update hlavičky,
     * vše v jedné transakci. $grouped může být prázdné (chybové stavy
     * bez deníku).
     *
     * @param list<array<string, mixed>> $grouped
     * @return array{state: int, messages: list<array{code: string, message: string, rowId: int|null}>}
     */
    private function writeResult(int $docHeadId, array $grouped, array $head = []): array
    {
        $state = $this->hasErrors() ? 2 : 1;

        $this->db->begin();
        try {
            $this->db->delete('economy_accounting_journal')
                ->where('doc_head = %i', $docHeadId)
                ->execute();

            foreach ($grouped as $line) {
                $this->db->insert('economy_accounting_journal', [
                    'source_kind'     => 'doc',
                    'doc_head'        => $docHeadId,
                    'doc_type'        => $head['doc_type'] ?? null,
                    'doc_number'      => $head['doc_number'] ?? null,
                    'accounting_date' => $head['accounting_date'] ?? null,
                    'fiscal_year'     => $head['fiscal_year'] ?? null,
                    'fiscal_month'    => $head['fiscal_month'] ?? null,
                    'account'         => $line['account'],
                    'account_number'  => $line['account_number'],
                    'is_error'        => $line['is_error'] ? 1 : 0,
                    'operation'       => $line['operation'],
                    'money_dr'        => $line['money_dr'],
                    'money_cr'        => $line['money_cr'],
                    'currency'        => $head['doc_currency'] ?? null,
                    'money_dr_cur'    => $line['money_dr_cur'],
                    'money_cr_cur'    => $line['money_cr_cur'],
                    'partner'         => $line['partner'],
                    'text'            => $line['text'],
                    // Platební identita — per řádek deníku (engine ji razítkuje
                    // z řádku dokladu dle vlajek operace, fallback hlavička).
                    'payment_reference' => $line['payment_reference'] ?? null,
                    'specific_symbol'   => $line['specific_symbol'] ?? null,
                    'constant_symbol'   => $line['constant_symbol'] ?? null,
                    'due_date'          => $line['due_date'] ?? null,
                ])->execute();
            }

            $this->db->update('docs_core_heads', [
                'accounting_state'    => $state,
                'accounting_messages' => $this->messages === []
                    ? null
                    : json_encode($this->messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->where('id = %i', $docHeadId)->execute();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Po commitu (deník zapsán): saldo si pohyby zdroje (re)derivuje.
        // Výjimku handleru dispatcher spolkne — účtování už je hotové.
        $this->journalEvents?->dispatchJournalWritten('doc', $docHeadId);

        return ['state' => $state, 'messages' => $this->messages];
    }

    /**
     * Smaže deník dokladu a vynuluje stav účtování — výstup ze stavu 40
     * (V opravě, Storno) a beforeDelete cleanup.
     */
    public function clearDocument(int $docHeadId): void
    {
        $this->db->begin();
        try {
            $this->db->delete('economy_accounting_journal')
                ->where('doc_head = %i', $docHeadId)
                ->execute();
            $this->db->update('docs_core_heads', [
                'accounting_state'    => 0,
                'accounting_messages' => null,
            ])->where('id = %i', $docHeadId)->execute();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Deník vymazán → saldo musí pohyby zdroje odebrat.
        $this->journalEvents?->dispatchJournalWritten('doc', $docHeadId);
    }

    // ── Načítání zdrojových dat ─────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(int $docHeadId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_rows]
             WHERE [doc_head] = %i AND [row_kind] = 1
             ORDER BY [order_pos]',
            $docHeadId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadVatRecap(int $docHeadId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_vat_recap]
             WHERE [doc_head] = %i
             ORDER BY [order_pos]',
            $docHeadId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * Zpráva účtování; `level` 'error' (default, stav 2) nebo 'warning'
     * (jen informace, stav zůstává 1 — selhání contributoru, #79 D3b).
     * Pole `level` se zapisuje jen u varování, tvar chybové zprávy je
     * beze změny.
     */
    private function addMessage(string $code, string $message, ?int $rowId = null, string $level = 'error'): void
    {
        $entry = ['code' => $code, 'message' => $message, 'rowId' => $rowId];
        if ($level !== 'error') {
            $entry['level'] = $level;
        }
        $this->messages[] = $entry;
    }

    /** Aspoň jedna zpráva bez úrovně warning → stav účtování 2. */
    private function hasErrors(): bool
    {
        foreach ($this->messages as $message) {
            if (($message['level'] ?? 'error') === 'error') {
                return true;
            }
        }
        return false;
    }
}
