<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\VatAnalytics;

/**
 * Křížová kontrola živého přiznání proti účetnímu deníku (D1): součty daně
 * per kód DPH z `vat_recap` výběru vs. 343 analytiky deníku za tentýž výběr
 * dokladů — dvě nezávislé cesty ke stejným číslům.
 *
 * Analytika řádku rekapitulace = táž pravidla předpisu, podle kterých účtuje
 * engine ({@see VatAnalytics::masks()}): konvence `343{NNN}` z `cz-NNN`
 * (docs/accounting.md §5) a výjimka podle předmětu plnění tuzemského
 * přenesení (343117 pro cz-115 + předmět 5, #89 D9). Bez pravidel předpisu
 * platí jen konvence. Strana
 * v deníku dle směru kódu: vstup (odpočet) = MD, výstup = D — párové řádky
 * samovyměření nesou výstupní kód, takže znaménko vychází ze směru kódu
 * (tatáž konvence jako AccountingEngine::buildVatLines).
 */
final class VatJournalCrossCheck
{
    /** Tolerance shody — pod ní se strany berou jako shodné. */
    public const TOLERANCE = 0.005;

    /**
     * @param array<string, array<string, mixed>> $vatCodes Definice kódů
     *        z world.vat (klíč = kód; používá se pole `direction`).
     * @param list<array<string, mixed>> $accountRules Sekce `accounts`
     *        efektivního účtovacího předpisu (`AccountingRules::resolve()`);
     *        prázdná = jen konvence `343{NNN}`.
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly array $vatCodes,
        private readonly array $accountRules = [],
    ) {}

    /**
     * @param list<array<string, mixed>> $docs Doklady z VatDocumentSelection.
     * @return array{
     *     differences: list<array{account: string, vatCode: ?string, recapTax: float, journalTax: float, delta: float}>,
     *     journalErrorRows: int,
     * } `journalErrorRows` = počet 343 řádků deníku s `is_error` za výběr.
     */
    public function check(array $docs): array
    {
        // Očekávaný netto zůstatek (MD − D) per analytika: vstupní kód
        // (odpočet) je MD (+daň), výstupní D (−daň).
        $expectedByAccount = [];
        foreach ($docs as $doc) {
            foreach ($doc['recap'] ?? [] as $row) {
                $code = (string) $row['vat_code'];
                if (!str_contains($code, '-')) {
                    continue;
                }
                $account   = $this->analyticAccount($code, $row['reverse_charge_code'] ?? null);
                $direction = (string) ($this->vatCodes[$code]['direction'] ?? 'input');
                $tax       = (float) $row['tax_dom'];
                $expectedByAccount[$account] = [
                    'vatCode' => $code,
                    'net'     => ($expectedByAccount[$account]['net'] ?? 0.0) + ($direction === 'input' ? $tax : -$tax),
                ];
            }
        }
        foreach ($expectedByAccount as $account => $expected) {
            $expectedByAccount[$account]['net'] = round($expected['net'], 2);
        }

        $journalByAccount = [];
        $errorRows        = 0;
        $docIds           = array_column($docs, 'id');
        if ($docIds !== []) {
            $rows = $this->db->fetchAll(
                'SELECT [account_number], [is_error], COUNT(*) AS [cnt],'
                . ' SUM([money_dr]) AS [dr], SUM([money_cr]) AS [cr]'
                . ' FROM [economy_accounting_journal]'
                . ' WHERE [doc_head] IN %in AND [account_number] LIKE %s'
                . ' GROUP BY [account_number], [is_error]',
                $docIds, '343%',
            );
            foreach ($rows as $row) {
                if ((int) $row['is_error'] === 1) {
                    $errorRows += (int) $row['cnt'];
                    continue;
                }
                $account = (string) $row['account_number'];
                $journalByAccount[$account] = ($journalByAccount[$account] ?? 0.0)
                    + (float) $row['dr'] - (float) $row['cr'];
            }
        }

        $accounts = array_unique(array_merge(array_keys($expectedByAccount), array_keys($journalByAccount)));
        sort($accounts);

        $differences = [];
        foreach ($accounts as $account) {
            $recapNet   = $expectedByAccount[$account]['net'] ?? 0.0;
            $journalNet = round($journalByAccount[$account] ?? 0.0, 2);
            if (abs($recapNet - $journalNet) <= self::TOLERANCE) {
                continue;
            }
            $differences[] = [
                'account'    => $account,
                'vatCode'    => $expectedByAccount[$account]['vatCode'] ?? null,
                'recapTax'   => $recapNet,
                'journalTax' => $journalNet,
                'delta'      => round($journalNet - $recapNet, 2),
            ];
        }

        return ['differences' => $differences, 'journalErrorRows' => $errorRows];
    }

    /**
     * Analytika 343 řádku rekapitulace: první maska pravidla předpisu, která
     * je číslem účtu; maska se zástupnými znaky nebo chybějící pravidla =
     * konvence `343{NNN}`.
     */
    private function analyticAccount(string $code, mixed $subject): string
    {
        $subject = trim((string) ($subject ?? ''));
        foreach (VatAnalytics::masks($this->accountRules, $code, $subject !== '' ? $subject : null) as $mask) {
            if (preg_match('/^[0-9A-Z]+$/', $mask) === 1) {
                return $mask;
            }
        }
        return '343' . substr($code, (int) strpos($code, '-') + 1);
    }
}
