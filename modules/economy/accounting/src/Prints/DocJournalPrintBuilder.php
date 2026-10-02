<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Prints;

use Shipard\Core\Accounting\JournalDimensionLabels;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildResult;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Utils\Slug;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Docs\Core\PersonSnapshotBuilder;
use Shipard\Module\Docs\Core\Prints\Blocks\DocDocumentBlock;
use Shipard\Module\Docs\Core\Prints\DocPrintContext;

/**
 * Data tisku „Kontace“ (#90 D27) — účetní zápisy jednoho dokladu, interní
 * tisk nad `docs_core_heads` pro všechny typy dokladů. Řádky deníku, sloupce
 * a dimenze jsou tytéž jako na tabu Účtování v detailu dokladu
 * (`DocsHeadsViewer`); názvy účtů jsou aktuální z rozvrhu (D14).
 *
 * Strany se jen uvádějí: účetní jednotka ze snapshotu dokladu, a když ho
 * doklad nemá (účetní doklad bez směru), z aktuálních dat vlastní firmy —
 * interní tisk to smí (D2). Doklad bez zápisů nebo s chybou účtování se
 * vytiskne a dostane varování do `messages`.
 *
 * Kontrakt `data` popisuje `docs/prints.md`.
 */
final class DocJournalPrintBuilder implements PrintBuilder
{
    public const VERSION = 1;

    private const TITLE_VARIANT = 'docJournal';

    /** `accounting_state`: zaúčtování skončilo chybou. */
    private const STATE_ERROR = 2;

    public function build(PrintRequest $request): PrintBuildResult
    {
        $context    = DocPrintContext::forHead($request);
        $head       = $context->head;
        $translator = $request->translator;
        $foreign    = $context->foreignCurrency();

        $title    = $translator->t('title.' . self::TITLE_VARIANT);
        $typeName = $this->typeName($request, $context->docType());
        $number   = (string) ($head['doc_number'] ?? '');

        $dimensions      = JournalDimensionSet::fromConfig($request->config);
        $journalRows     = $this->loadJournal($request, $dimensions);
        $dimensionLabels = JournalDimensionLabels::forRows($dimensions, $request->db, $journalRows);
        $accountNames    = $this->accountNames($request, $journalRows);

        $journal = [];
        $sum     = ['debit' => 0.0, 'credit' => 0.0, 'debitCur' => 0.0, 'creditCur' => 0.0];
        foreach ($journalRows as $row) {
            $rowDimensions = [];
            foreach ($dimensionLabels as $dimensionId => $info) {
                $rowDimensions[$dimensionId] = $info['labels'][(int) ($row[$info['column']] ?? 0)] ?? null;
            }
            $journal[] = [
                'accountNumber' => (string) ($row['account_number'] ?? ''),
                'accountName'   => $accountNames[(int) ($row['account'] ?? 0)] ?? null,
                'text'          => DocPrintContext::text($row['text'] ?? null),
                'debit'         => self::nonZero($row['money_dr'] ?? null),
                'credit'        => self::nonZero($row['money_cr'] ?? null),
                'debitCur'      => $foreign ? self::nonZero($row['money_dr_cur'] ?? null) : null,
                'creditCur'     => $foreign ? self::nonZero($row['money_cr_cur'] ?? null) : null,
                'dimensions'    => $rowDimensions,
                'isError'       => (int) ($row['is_error'] ?? 0) === 1,
            ];
            $sum['debit']     += (float) ($row['money_dr'] ?? 0);
            $sum['credit']    += (float) ($row['money_cr'] ?? 0);
            $sum['debitCur']  += (float) ($row['money_dr_cur'] ?? 0);
            $sum['creditCur'] += (float) ($row['money_cr_cur'] ?? 0);
        }

        $state      = (int) ($head['accounting_state'] ?? 0);
        $stateLabel = $this->stateLabel($request, $state);

        $messages = [];
        if ($journal === []) {
            $messages[] = PrintMessage::warning('noJournal', $translator->t('message.noJournal'));
        }
        if ($state === self::STATE_ERROR) {
            $messages[] = PrintMessage::warning(
                'accountingError',
                $translator->t('message.accountingError', ['state' => $stateLabel]),
            );
        }

        $dimensionList = [];
        foreach ($dimensionLabels as $dimensionId => $info) {
            $dimensionList[] = ['id' => $dimensionId, 'label' => $info['name']];
        }

        $data = [
            'document' => DocDocumentBlock::describe($context, [
                'typeName' => $typeName,
                'title'    => $title,
            ]),
            'dates' => [
                'issue'      => DocPrintContext::date($head['issue_date'] ?? null),
                'accounting' => DocPrintContext::date($head['accounting_date'] ?? null),
                'duzp'       => $context->isTaxDocument() ? DocPrintContext::date($head['vat_duzp'] ?? null) : null,
            ],
            'accountingUnit' => $context->own() ?? $this->currentOwnCompany($request),
            'partner'        => $context->partner(),
            'accounting'     => ['state' => $state, 'stateLabel' => $stateLabel],
            'dimensions'     => $dimensionList,
            'journal'        => $journal,
            'totals'         => [
                'debit'     => round($sum['debit'], 2),
                'credit'    => round($sum['credit'], 2),
                'debitCur'  => $foreign ? round($sum['debitCur'], 2) : null,
                'creditCur' => $foreign ? round($sum['creditCur'], 2) : null,
            ],
        ];

        return new PrintBuildResult(
            data: $data,
            title: trim($title . ' ' . trim($typeName . ' ' . $number)),
            fileName: Slug::make($translator->t('fileName.' . self::TITLE_VARIANT), fallback: 'document')
                . ($number !== '' ? '-' . Slug::make($number, fallback: 'x') : '')
                . '.pdf',
            messages: $messages,
        );
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /**
     * Řádky deníku dokladu v pořadí vzniku, se sloupci dimenzí — stejný
     * výběr jako tab Účtování.
     *
     * @return list<array<string, mixed>>
     */
    private function loadJournal(PrintRequest $request, JournalDimensionSet $dimensions): array
    {
        $dimensionColumns = '';
        foreach ($dimensions as $dimension) {
            $dimensionColumns .= ', `' . $dimension->journalColumn . '`';
        }

        return $request->db->fetchAll(
            'SELECT `account`, `account_number`, `text`, `money_dr`, `money_cr`,'
            . ' `money_dr_cur`, `money_cr_cur`, `is_error`' . $dimensionColumns
            . ' FROM `economy_accounting_journal`'
            . ' WHERE `doc_head` = %i'
            . ' ORDER BY `id` ASC',
            $request->recordId,
        );
    }

    /**
     * @param list<array<string, mixed>> $journalRows
     * @return array<int, string> id účtu → aktuální název
     */
    private function accountNames(PrintRequest $request, array $journalRows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['account'] ?? 0),
            $journalRows,
        ))));
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($request->db->fetchAll(
            'SELECT [id], [name] FROM [economy_accounting_accounts] WHERE [id] IN %in',
            $ids,
        ) as $account) {
            $names[(int) $account['id']] = (string) $account['name'];
        }
        return $names;
    }

    /** Vlastní firma z aktuálních dat — pro doklad bez snapshotu vlastní strany. */
    private function currentOwnCompany(PrintRequest $request): ?array
    {
        $dibi     = $request->db->getDibiConnection();
        $own      = new OwnCompanyResolver($dibi);
        $personId = $own->getOwnPersonId();
        if ($personId === null) {
            return null;
        }
        $address  = $own->getOwnHeadquartersAddress();
        $snapshot = (new PersonSnapshotBuilder($dibi))->build(
            $personId,
            $address !== null ? (int) $address['id'] : null,
            null,
            $request->record['vat_registration'] ?? null,
        );

        return $snapshot === [] ? null : $snapshot;
    }

    private function typeName(PrintRequest $request, string $docType): string
    {
        $docTypes = $request->config?->cfgItem('docs.core.docTypes');
        $name     = is_array($docTypes) ? ($docTypes[$docType]['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : $docType;
    }

    private function stateLabel(PrintRequest $request, int $state): string
    {
        $states = $request->config?->cfgItem('economy.accounting.accountingStates');
        $name   = is_array($states) ? ($states[(string) $state]['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : (string) $state;
    }

    /** Částka zápisu; nulová strana zápisu je null (tiskne se prázdná). */
    private static function nonZero(mixed $amount): ?float
    {
        $value = (float) ($amount ?? 0);
        return $value === 0.0 ? null : $value;
    }
}
