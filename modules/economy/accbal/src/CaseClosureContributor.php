<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Accounting\AbstractJournalContributor;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalLineView;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Module\Economy\Accounting\AccountingRules;

/**
 * Uzavření případu úhradou mimo skupinu (#79 D3b/D3c) — contributor deníku
 * modulu saldokonta (`journalContributors` v module.jsonc), volají ho oba
 * účtovací enginy před zápisem deníku zdroje.
 *
 * Generický nad nastavením skupin, ne nad „proformami“: cílová skupina G
 * je každá aktivní skupina s vyplněnou `payment_category` **a**
 * `closing_category` ({@see LedgerOpenItemLookup::closingGroups}; na seedu
 * jen Zálohové faktury vydané: předpis 756 MD, úhrada jde na
 * `advances.received` 324, uzavírá se proti `offbalance.contra` 799).
 *
 * Spouštěcí řádek pro G: operace příjmu/výdeje peněz nebo zálohy
 * ({@see TRIGGER_OPERATIONS} — banka a pokladna; `sale.advanceVat`,
 * `sale.advanceDeduction` ani ruční `acc.entry` nic nespouští), účet
 * začíná maskou `payment_category` z předpisu, strana **opačná** k
 * předpisové straně G (756 MD → spouští 324 DAL), kladná částka, partner
 * a VS, bez chyby. Klíč případu = (G, fiskální rok zdroje, partner, VS,
 * SS, měna zdroje) — období v klíči (#69 D11): úhrada v jiném roce
 * proformu nenajde, dokud ji nepřenese otevírací doklad.
 *
 * Částka = min(řádek, reziduum − už spotřebované tímto zdrojem) v měně
 * případu; v domácí měně kurzem předpisů případu (Σ amount_hc / Σ amount,
 * D3c), poslední uzavření dorovná haléře tak, aby Σ dom úhrad = Σ dom
 * předpisů (podrozvaha na nulu; kurzový rozdíl zůstává na zálohách).
 * Pár požadavků: `closing_category` na předpisovou stranu G (799 MD),
 * přesný účet prvního předpisu případu na stranu úhrady (756100 DAL),
 * identita spouštěcího řádku. Reziduum se čte z ledgeru **bez pohybů
 * vlastního zdroje** — reaccount je idempotentní. Nic se nezapisuje.
 *
 * Výpočet je čistá funkce nad poli ({@see closures}) — DB jen v dodaném
 * agregátu případu. Vlastnosti (idempotence, pořadí úhrad, změna proformy
 * po úhradě): docs/accbal.md §5.8.
 */
final class CaseClosureContributor extends AbstractJournalContributor
{
    /** Operace řádku, které uzavírají případ: příjem/výdej peněz a zálohy (banka, pokladna). */
    public const TRIGGER_OPERATIONS = ['payment.in', 'payment.out', 'advance.received', 'advance.given'];

    private const TOLERANCE = 0.005;

    private ?LedgerOpenItemLookup $lookup = null;

    public function contribute(JournalSourceContext $context, array $lines): array
    {
        if ($this->db === null) {
            return [];
        }
        $lookup = $this->lookup();
        $groups = $lookup->closingGroups();
        if ($groups === []) {
            return [];
        }

        $rules = AccountingRules::resolve($this->config, $this->db);
        $targets = [];
        foreach ($groups as $group) {
            $mask = AccountingRules::firstMaskForCategory($rules, $group['payment_category']);
            if ($mask === '') {
                continue;
            }
            $targets[] = [
                'balance'          => $group['balance'],
                'name'             => $group['name'],
                'request_side'     => $group['request_side'],
                'payment_mask'     => $mask,
                'closing_category' => $group['closing_category'],
            ];
        }

        return self::closures(
            $context,
            $lines,
            $targets,
            static fn(int $balance, array $key): ?CaseResidual
                => $lookup->caseResidual($balance, $key, $context->sourceKind, $context->sourceId),
        );
    }

    /**
     * Uzavírací páry pro spouštěcí řádky zdroje — čistá funkce.
     *
     * Řádky se procházejí v pořadí; víc řádků téhož klíče postupně
     * spotřebovává reziduum. `$residualOf(balance, key)` dodá agregát
     * případu bez pohybů vlastního zdroje (null = klíč bez pohybů).
     *
     * @param list<JournalLineView> $lines
     * @param list<array{balance: int, name: string, request_side: int, payment_mask: string, closing_category: string}> $targets
     * @param callable(int, array<string, mixed>): ?CaseResidual $residualOf
     * @return list<JournalLineRequest>
     */
    public static function closures(JournalSourceContext $context, array $lines, array $targets, callable $residualOf): array
    {
        if ($targets === []) {
            return [];
        }
        $requests = [];
        /** @var array<string, array{cur: float, dom: float}> $consumed klíč → částky už uzavřené tímto zdrojem */
        $consumed = [];
        $currency = CaseQuery::normalizeCurrency($context->currency);

        foreach ($lines as $line) {
            if (!in_array($line->operation, self::TRIGGER_OPERATIONS, true)) {
                continue;
            }
            $lineCur = round($line->moneyCur, 2);
            if ($lineCur <= self::TOLERANCE || $line->partner === null || $line->partner <= 0) {
                continue;
            }
            $vs = CaseQuery::normalizeSymbol($line->paymentReference);
            if ($vs === null) {
                continue;
            }
            $ss = CaseQuery::normalizeSymbol($line->specificSymbol);

            foreach ($targets as $target) {
                if ($line->side === $target['request_side']
                    || !str_starts_with($line->accountNumber, $target['payment_mask'])
                ) {
                    continue;
                }
                $key = [
                    'fiscal_year'       => $context->fiscalYear,
                    'partner'           => $line->partner,
                    'payment_reference' => $vs,
                    'specific_symbol'   => $ss,
                    'currency'          => $currency,
                ];
                $case = $residualOf($target['balance'], $key);
                if ($case === null || $case->firstRequestAccount === null) {
                    continue;
                }

                $keyId = $target['balance'] . '|' . implode('|', array_map(static fn($v) => (string) ($v ?? ''), $key));
                $used = $consumed[$keyId] ?? ['cur' => 0.0, 'dom' => 0.0];
                $remaining = round($case->residual() - $used['cur'], 2);
                if ($remaining <= self::TOLERANCE) {
                    continue;
                }

                $cur = min($lineCur, $remaining);
                $closesFully = abs($cur - $remaining) <= self::TOLERANCE;
                // D3c: domácí částka kurzem předpisů případu; poslední uzavření
                // dorovná zbytek, aby Σ dom úhrad = Σ dom předpisů.
                $dom = $closesFully
                    ? round($case->residualHc() - $used['dom'], 2)
                    : round($cur * $case->requestRate(), 2);
                if ($dom <= 0.0) {
                    $dom = round($cur * $case->requestRate(), 2);
                }

                $consumed[$keyId] = ['cur' => round($used['cur'] + $cur, 2), 'dom' => round($used['dom'] + $dom, 2)];
                $text = 'Uzavření ' . mb_lcfirst($target['name']) . ' ' . $vs;

                $requests[] = new JournalLineRequest(
                    $target['request_side'],
                    $target['closing_category'],
                    null,
                    $line->partner,
                    $vs,
                    $ss,
                    $dom,
                    $cur,
                    $text,
                );
                $requests[] = new JournalLineRequest(
                    1 - $target['request_side'],
                    null,
                    $case->firstRequestAccount,
                    $line->partner,
                    $vs,
                    $ss,
                    $dom,
                    $cur,
                    $text,
                );
                break;
            }
        }

        return $requests;
    }

    private function lookup(): LedgerOpenItemLookup
    {
        if ($this->lookup === null) {
            $lookup = new LedgerOpenItemLookup();
            $lookup->setDb($this->db);
            if ($this->config !== null) {
                $lookup->setConfig($this->config);
            }
            if ($this->dsConfig !== null) {
                $lookup->setDsConfig($this->dsConfig);
            }
            $this->lookup = $lookup;
        }
        return $this->lookup;
    }
}
