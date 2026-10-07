<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Module\Economy\Assets\AssetAcquisitionService;
use Shipard\Module\Economy\Assets\AssetJournalCheck;
use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Posting\AssetPostingInput;

/**
 * Kontrola evidence × deník (docs/assets.md invariant §1, D67) za účetní
 * rok. Dvě části oddělené nadpisovým řádkem:
 *
 *  1. **Účty účetních skupin** — stav / obrat podle evidence proti deníku
 *     a obrat roku s kartou a bez karty;
 *  2. **Nesoulady po kartách** — zaúčtované události ≠ deník (a),
 *     pořízení ≠ zařazení + TZ (b), nezaúčtované starší události (c).
 *
 * Každý nesoulad je zpráva s `rowRef`: rozdíl na účtu, (a) a (b) chyba,
 * zápisy bez karty a (c) varování — `status` reportu tak říká, jestli
 * kontrola prošla. Rozdíl na účtu pořízení je jen varování (D73):
 * evidence tam počítá pouze pořízení s kartou, takže rozdíl znamená
 * pořízení bez karty nebo zařazení bez navázaného pořízení, ne chybu
 * evidence. Rok, který v deníku ještě nemá počáteční stavy (D85), dostane
 * jedno varování `noOpeningBalances` a účty se stavem bez hodnoty deníku
 * a rozdílu — porovnají se až po otevření roku. Čistý stav = žádné
 * zprávy. Logika je v `AssetJournalCheck` (sdílí ji alerty a karta).
 *
 * Drill-down (D70): účet vede do deníku s filtrem účtu a roku, nesoulad
 * karty na kartu (ve vieweru — je tam co opravit) a jeho druh do deníku
 * s filtrem účtu a karty.
 */
final class JournalCheckBuilder implements ReportBuilder
{
    public function __construct(
        private readonly ?AssetReportSupport $support = null,
        private readonly ?AssetJournalCheck $check = null,
    ) {
    }

    public function build(ReportRequest $request): ReportResult
    {
        $cs = $request->language === 'cs';
        $support = $this->support ?? new AssetReportSupport();
        $check = $this->check ?? new AssetJournalCheck($request->db->getDibiConnection());
        $period = $support->period($request);
        $plans = $support->planService($request);
        $today = $plans->today();

        $rows = [];
        $messages = [];

        // ── 1. Účty účetních skupin ─────────────────────────────────────────
        $accounts = $check->accounts(
            $period['yearBegin'],
            $period['yearEnd'],
            $period['yearId'],
            $request->range?->monthIdsBefore ?? [],
            $request->range?->monthIdsInRange ?? [],
        );
        if ($accounts !== []) {
            $rows[] = $this->section('accounts', $cs ? 'Účty účetních skupin' : 'Accounting group accounts');
        }
        if (array_any($accounts, static fn(array $a): bool => $a['noOpeningBalances'])) {
            // D85: rok ještě neotevřený — stavy účtů majetku, pořízení a oprávek
            // v deníku nejsou, porovnání by hlásilo rozdíl v celé výši.
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'assets.journalCheck.noOpeningBalances',
                $cs
                    ? sprintf('Rok %s nemá v deníku počáteční stavy — účty majetku, pořízení a oprávek se porovnají až po otevření roku.',
                        $period['yearName'])
                    : sprintf('Year %s has no opening balances in the journal — the asset, acquisition and accumulated depreciation'
                        . ' accounts will be compared once the year is opened.', $period['yearName']),
            );
        }
        foreach ($accounts as $account) {
            $index = count($rows);
            $values = [
                'number'   => '',
                'kind'     => $this->roleLabel($account['role'], $cs),
                'measure'  => $account['balance'] ? ($cs ? 'zůstatek' : 'balance') : ($cs ? 'obrat' : 'turnover'),
                'evidence' => AssetReportSupport::money($account['evidence']),
            ];
            if ($account['journal'] !== null) {
                // Bez počátečních stavů (D85) zůstává buňka deníku prázdná.
                $values['journal'] = AssetReportSupport::money($account['journal']);
            }
            $values += [
                'difference'   => AssetReportSupport::money($account['difference']),
                'withAsset'    => AssetReportSupport::money($account['withAsset']),
                'withoutAsset' => AssetReportSupport::money($account['withoutAsset']),
            ];
            $rows[] = new ReportRow(
                ReportRowKind::Detail,
                1,
                $account['account'],
                $account['name'],
                $values,
                'account:' . $account['account'],
                // Deník účtu za kontrolovaný rok.
                AssetReportSupport::journalLink($account['account'], $period['yearId']),
            );
            if (abs($account['difference']) >= Amounts::EPSILON) {
                // D73: evidence počítá na účtu pořízení jen pořízení s kartou —
                // rozdíl je vždy pořízení bez karty (vč. otevíracího zůstatku)
                // nebo zařazení bez navázaného pořízení (import), ne chyba evidence.
                $acquisition = $account['role'] === AssetPostingInput::ACCOUNT_ACQUISITION;
                $messages[] = new ReportMessage(
                    $acquisition ? ReportMessageSeverity::Warning : ReportMessageSeverity::Error,
                    $acquisition ? 'assets.journalCheck.acquisitionAccountDifference' : 'assets.journalCheck.accountMismatch',
                    $cs
                        ? sprintf('Účet %s: evidence %s, deník %s, rozdíl %s.', $account['account'], Amounts::money($account['evidence']),
                            Amounts::money($account['journal']), Amounts::money($account['difference']))
                            . ($acquisition ? ' Na účtu pořízení je pořízení bez karty majetku nebo zařazení bez navázaného pořízení.' : '')
                        : sprintf('Account %s: register %s, journal %s, difference %s.', $account['account'], Amounts::money($account['evidence']),
                            Amounts::money($account['journal']), Amounts::money($account['difference']))
                            . ($acquisition ? ' The acquisition account holds acquisitions without an asset card or activations without a linked acquisition.' : ''),
                    "rows.{$index}",
                );
            }
            if ($account['withoutAssetRows'] > 0) {
                $messages[] = new ReportMessage(
                    ReportMessageSeverity::Warning,
                    'assets.journalCheck.withoutAsset',
                    $cs
                        ? sprintf('Účet %s: v roce %s jsou zápisy bez karty majetku (%d, obrat %s) — evidence je nevidí.',
                            $account['account'], $period['yearName'], $account['withoutAssetRows'], Amounts::money($account['withoutAsset']))
                        : sprintf('Account %s: %s has entries without an asset card (%d, turnover %s) — the register cannot see them.',
                            $account['account'], $period['yearName'], $account['withoutAssetRows'], Amounts::money($account['withoutAsset'])),
                    "rows.{$index}",
                );
            }
        }

        // ── 2. Nesoulady po kartách ─────────────────────────────────────────
        $findings = $check->cardFindings($period['yearEnd']);
        $cardRows = [];

        foreach ($findings['posting'] as $finding) {
            $title = trim($finding['number'] . ' ' . $finding['name']);
            foreach ($finding['accounts'] as $account) {
                $expected = round($account['expectedDr'] - $account['expectedCr'], 2);
                $journal = round($account['journalDr'] - $account['journalCr'], 2);
                $cardRows[] = [
                    new ReportRow(
                        ReportRowKind::Detail,
                        1,
                        $account['account'] !== '' ? $account['account'] : null,
                        $finding['name'],
                        [
                            'number'     => $finding['number'],
                            'kind'       => $cs ? 'zaúčtování ≠ deník' : 'posting ≠ journal',
                            'measure'    => $cs ? 'obrat' : 'turnover',
                            'evidence'   => ['md' => $account['expectedDr'], 'd' => $account['expectedCr'], 'balance' => $expected],
                            'journal'    => ['md' => $account['journalDr'], 'd' => $account['journalCr'], 'balance' => $journal],
                            'difference' => AssetReportSupport::money($expected - $journal),
                        ],
                        'posting:' . $finding['assetId'] . ':' . $account['account'],
                        AssetReportSupport::cardLink($finding['assetId'], true),
                        // Řádky deníku účtu s kartou přes všechny roky.
                        $account['account'] !== ''
                            ? ['kind' => AssetReportSupport::journalLink($account['account'], null, $finding['assetId'])]
                            : [],
                    ),
                    new ReportMessage(
                        ReportMessageSeverity::Error,
                        'assets.journalCheck.postingMismatch',
                        $cs
                            ? sprintf('%s, účet %s: zaúčtované události MD %s / D %s, deník s kartou MD %s / D %s.', $title,
                                $account['account'] !== '' ? $account['account'] : '(chybí v účetní skupině)',
                                Amounts::money($account['expectedDr']), Amounts::money($account['expectedCr']),
                                Amounts::money($account['journalDr']), Amounts::money($account['journalCr']))
                            : sprintf('%s, account %s: posted events Dr %s / Cr %s, journal with the card Dr %s / Cr %s.', $title,
                                $account['account'] !== '' ? $account['account'] : '(missing in the accounting group)',
                                Amounts::money($account['expectedDr']), Amounts::money($account['expectedCr']),
                                Amounts::money($account['journalDr']), Amounts::money($account['journalCr'])),
                    ),
                ];
            }
        }

        foreach ($findings['acquisition'] as $finding) {
            // Nezařazená karta: pořízení smí čekat, nesouladem je až po lhůtě.
            if (!$finding['started'] && !AssetJournalCheck::isOverdue($finding['since'], $today)) {
                continue;
            }
            $title = trim($finding['number'] . ' ' . $finding['name']);
            $cardRows[] = [
                new ReportRow(
                    ReportRowKind::Detail,
                    1,
                    null,
                    $finding['name'],
                    [
                        'number'     => $finding['number'],
                        'kind'       => $cs ? 'pořízení ≠ zařazení' : 'acquisition ≠ activation',
                        'measure'    => $cs ? 'zůstatek' : 'balance',
                        'evidence'   => AssetReportSupport::money($finding['activated']),
                        'journal'    => AssetReportSupport::money($finding['acquired']),
                        'difference' => AssetReportSupport::money($finding['activated'] - $finding['acquired']),
                    ],
                    'acquisition:' . $finding['assetId'],
                    AssetReportSupport::cardLink($finding['assetId'], true),
                    // Pořízení karty na účtech pořízení v deníku.
                    ['kind' => AssetReportSupport::journalLink(
                        AssetAcquisitionService::ACQUISITION_ACCOUNT_PREFIX,
                        null,
                        $finding['assetId'],
                    )],
                ),
                new ReportMessage(
                    ReportMessageSeverity::Error,
                    'assets.journalCheck.acquisitionMismatch',
                    $finding['started']
                        ? ($cs
                            ? sprintf('%s: pořízení na účtu pořízení %s ≠ zařazení a technická zhodnocení %s (rozdíl %s).', $title,
                                Amounts::money($finding['acquired']), Amounts::money($finding['activated']), Amounts::money($finding['difference']))
                            : sprintf('%s: acquisition on the acquisition account %s ≠ activation and improvements %s (difference %s).', $title,
                                Amounts::money($finding['acquired']), Amounts::money($finding['activated']), Amounts::money($finding['difference'])))
                        : ($cs
                            ? sprintf('%s: pořízení %s zůstává na účtu pořízení bez zařazení déle než %d dní.', $title,
                                Amounts::money($finding['acquired']), AssetJournalCheck::ACQUISITION_DAYS)
                            : sprintf('%s: acquisition of %s has stayed on the acquisition account without activation for more than %d days.', $title,
                                Amounts::money($finding['acquired']), AssetJournalCheck::ACQUISITION_DAYS)),
                ),
            ];
        }

        // (c) Co už mělo být zaúčtované: události do konce předchozího
        // období účetních odpisů, nejdéle do konce zvoleného roku.
        $previousEnd = (new \DateTimeImmutable($plans->accCalendar()->periodOf($today)->begin))->modify('-1 day')->format('Y-m-d');
        foreach ($check->unposted(min($previousEnd, $period['yearEnd'])) as $finding) {
            $title = trim($finding['number'] . ' ' . $finding['name']);
            $kind = AssetReportSupport::eventKindLabel($request, $finding['kind']);
            $cardRows[] = [
                new ReportRow(
                    ReportRowKind::Detail,
                    1,
                    null,
                    $finding['name'],
                    [
                        'number'     => $finding['number'],
                        'kind'       => $cs ? 'nezaúčtovaná událost' : 'unposted event',
                        'measure'    => $kind . ' ' . AssetReportSupport::czDate($finding['date']),
                        'evidence'   => AssetReportSupport::money($finding['amount']),
                        'journal'    => AssetReportSupport::money(0.0),
                        'difference' => AssetReportSupport::money($finding['amount']),
                    ],
                    'unposted:' . $finding['eventId'],
                    AssetReportSupport::cardLink($finding['assetId'], true),
                ),
                new ReportMessage(
                    ReportMessageSeverity::Warning,
                    'assets.journalCheck.unposted',
                    $cs
                        ? sprintf('%s: %s z %s (%s) není zaúčtované.', $title, $kind, AssetReportSupport::czDate($finding['date']), Amounts::money($finding['amount']))
                        : sprintf('%s: %s of %s (%s) is not posted.', $title, $kind, AssetReportSupport::czDate($finding['date']), Amounts::money($finding['amount'])),
                ),
            ];
        }

        if ($cardRows !== []) {
            $rows[] = $this->section('cards', $cs ? 'Nesoulady po kartách' : 'Mismatches by asset card');
            foreach ($cardRows as [$row, $message]) {
                $messages[] = new ReportMessage($message->severity, $message->code, $message->text, 'rows.' . count($rows));
                $rows[] = $row;
            }
        }

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $messages,
            columns: [
                new ReportColumn('number', ReportColumn::TYPE_TEXT, $cs ? 'Inv. číslo' : 'Asset no.'),
                new ReportColumn('kind', ReportColumn::TYPE_TEXT, $cs ? 'Účet / nesoulad' : 'Account / mismatch'),
                new ReportColumn('measure', ReportColumn::TYPE_TEXT, $cs ? 'Porovnává se' : 'Compared'),
                new ReportColumn('evidence', ReportColumn::TYPE_MONEY, $cs ? 'Evidence' : 'Register'),
                new ReportColumn('journal', ReportColumn::TYPE_MONEY, $cs ? 'Deník' : 'Journal'),
                new ReportColumn('difference', ReportColumn::TYPE_MONEY, $cs ? 'Rozdíl' : 'Difference'),
                new ReportColumn('withAsset', ReportColumn::TYPE_MONEY, $cs ? 'Obrat roku s kartou' : 'Year turnover with a card'),
                new ReportColumn('withoutAsset', ReportColumn::TYPE_MONEY, $cs ? 'Obrat roku bez karty' : 'Year turnover without a card'),
            ],
            rows: $rows,
        );
    }

    /**
     * Nadpisový řádek části — odděluje účty od karet. Nese prázdnou textovou
     * buňku, aby `values` zůstalo v JSON objektem i bez čísel.
     */
    private function section(string $id, string $label): ReportRow
    {
        return new ReportRow(ReportRowKind::Subtotal, 0, null, $label, ['number' => ''], 'section:' . $id);
    }

    private function roleLabel(string $role, bool $cs): string
    {
        return match ($role) {
            AssetPostingInput::ACCOUNT_ASSET => $cs ? 'účet majetku' : 'asset account',
            AssetPostingInput::ACCOUNT_ACQUISITION => $cs ? 'účet pořízení' : 'acquisition account',
            AssetPostingInput::ACCOUNT_ACCUMULATED => $cs ? 'účet oprávek' : 'accumulated depreciation account',
            AssetPostingInput::ACCOUNT_DEPRECIATION => $cs ? 'účet odpisů' : 'depreciation account',
            AssetPostingInput::ACCOUNT_DISPOSAL => $cs ? 'účet zůstatkové ceny' : 'residual value account',
            default => $role,
        };
    }
}
