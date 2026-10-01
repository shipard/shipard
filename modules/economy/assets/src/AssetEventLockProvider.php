<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\AbstractDocumentLockProvider;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Codebooks\FiscalMonthLookup;

/**
 * Zámek potvrzené události majetku (`documentLockProviders` pro
 * `economy_assets_events`, D29):
 *
 *   a) historie se rozebírá od konce — potvrzená událost je zamčená,
 *      dokud po ní v jejím okruhu (`both` = v obou) existuje potvrzený
 *      odpis; pořadí dává `AssetEventDocument::orderKey()`;
 *   b) zámek účetního měsíce — potvrzená událost s datem v zamčeném
 *      měsíci, a stejně tak událost, která se do zamčeného měsíce
 *      potvrzuje (koncept v zamčeném měsíci zamčený není);
 *   c) zaúčtování (D52) — událost s navázaným živým účetním dokladem
 *      (`doc_head`, doklad mimo Storno / Smazáno). Odemkne ji jen
 *      „Zrušit zaúčtování období“.
 *
 * Nové události před potvrzeným odpisem hlídá validace dokumentu
 * (`notAtEnd`), provider chrání jen uložené záznamy.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetEventLockProvider extends AbstractDocumentLockProvider
{
    public const SOURCE_HISTORY = 'asset_event_history';
    public const SOURCE_MONTH = 'fiscal_month';
    public const SOURCE_POSTED = 'asset_event_posted';

    /** tableId `docs_core_heads` */
    public const HEADS_TABLE_ID = 401;

    /** tableId `economy_assets_events` */
    public const SUBJECT_TABLE_ID = 454;
    /** tableId `economy_codebooks_fiscal_months` */
    public const MONTH_TABLE_ID = 314;

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        if ($this->db === null) {
            return [];
        }
        $reasons = [];

        $originalConfirmed = $original !== null
            && (int) ($original['docState'] ?? 0) === AssetEventDocument::STATE_CONFIRMED;

        if ($originalConfirmed) {
            $later = $this->laterDepreciation($original);
            if ($later !== null) {
                $reasons[] = new DocumentLockReason(
                    source: self::SOURCE_HISTORY,
                    title: 'Po události následuje potvrzený odpis za období do '
                        . self::czDate((string) $later['period_end']),
                    message: 'Historie majetku se rozebírá od konce — nejdřív zruš pozdější odpisy okruhu.',
                    subjectTableId: self::SUBJECT_TABLE_ID,
                    subjectRowId: (int) $later['id'],
                    params: ['periodEnd' => (string) $later['period_end'], 'scope' => (string) $later['scope']],
                );
            }
        }

        $docHead = $original !== null ? (int) ($original['doc_head'] ?? 0) : 0;
        if ($docHead > 0) {
            $document = $this->postingDocument($docHead);
            if ($document !== null) {
                $number = $document['doc_number'] !== '' ? $document['doc_number'] : '#' . $docHead;
                $reasons[] = new DocumentLockReason(
                    source: self::SOURCE_POSTED,
                    title: "Zaúčtováno dokladem {$number}",
                    message: 'Zaúčtovanou událost nejde opravit ani smazat — nejdřív zruš zaúčtování období'
                        . ' (Majetek → Odpisy za období).',
                    subjectTableId: self::HEADS_TABLE_ID,
                    subjectRowId: $docHead,
                    params: ['docNumber' => $number],
                );
            }
        }

        $dates = [];
        if ($originalConfirmed) {
            $dates[] = AssetEventDocument::date($original['event_date'] ?? null);
        }
        if ((int) ($data['docState'] ?? 0) === AssetEventDocument::STATE_CONFIRMED) {
            $dates[] = AssetEventDocument::date($data['event_date'] ?? null);
        }
        foreach (array_unique(array_filter($dates)) as $date) {
            $month = $this->lockedMonthForDate($date);
            if ($month === null) {
                continue;
            }
            $label = sprintf('%04d/%02d', $month['calendar_year'], $month['calendar_month']);
            $reasons[] = new DocumentLockReason(
                source: self::SOURCE_MONTH,
                title: "Fiskální měsíc {$label} je uzamčený",
                message: 'Událost má datum v uzamčeném fiskálním měsíci — potvrzení, oprava i smazání'
                    . ' vyžadují jeho odemknutí (Fiskální období → Měsíce).',
                subjectTableId: self::MONTH_TABLE_ID,
                subjectRowId: $month['id'],
                params: ['year' => $month['calendar_year'], 'month' => $month['calendar_month'], 'label' => $label],
            );
        }

        return $reasons;
    }

    /**
     * Nejbližší potvrzený odpis téže karty v okruhu události, který je
     * v historii za ní.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function laterDepreciation(array $event): ?array
    {
        $key = AssetEventDocument::orderKey($event);
        $scope = (string) ($event['scope'] ?? AssetEvent::SCOPE_BOTH);
        foreach ($this->confirmedDepreciationsFrom((int) ($event['asset'] ?? 0), $key[0], (int) ($event['id'] ?? 0)) as $row) {
            if (AssetEventDocument::inScope((string) $row['scope'], $scope)
                && AssetEventDocument::orderKey($row) > $key
            ) {
                return $row;
            }
        }
        return null;
    }

    private static function czDate(string $date): string
    {
        return $date !== '' ? (new \DateTimeImmutable($date))->format('j. n. Y') : '';
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * Potvrzené odpisy karty s datem od `$fromDate` (kromě `$excludeId`),
     * chronologicky.
     *
     * @return list<array<string, mixed>>
     */
    protected function confirmedDepreciationsFrom(int $assetId, string $fromDate, int $excludeId): array
    {
        if ($this->db === null || $assetId <= 0 || $fromDate === '') {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [id], [asset], [event_kind], [scope], [event_date], [period_end]'
            . ' FROM [' . AssetEventDocument::TABLE . ']'
            . ' WHERE [asset] = %i AND [event_kind] = %s AND [docState] = %i AND [event_date] >= %d AND [id] <> %i'
            . ' ORDER BY [event_date], [id]',
            $assetId,
            AssetEvent::KIND_DEPRECIATION,
            AssetEventDocument::STATE_CONFIRMED,
            $fromDate,
            $excludeId,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = AssetPlanService::plain($row);
        }
        return $out;
    }

    /**
     * Živý účetní doklad (mimo Storno / Smazáno), kterým je událost zaúčtovaná.
     *
     * @return array{doc_number: string}|null
     */
    protected function postingDocument(int $docHeadId): ?array
    {
        $row = $this->db?->fetch(
            'SELECT [doc_number] FROM [docs_core_heads] WHERE [id] = %i AND [docState] NOT IN %in',
            $docHeadId,
            AssetEventDocument::DEAD_DOC_STATES,
        );

        return $row === null || $row === false ? null : ['doc_number' => (string) ($row['doc_number'] ?? '')];
    }

    /** @return array{id: int, calendar_year: int, calendar_month: int}|null */
    protected function lockedMonthForDate(string $date): ?array
    {
        return $this->db !== null ? FiscalMonthLookup::lockedMonthForDate($this->db, $date) : null;
    }
}
