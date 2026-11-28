<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Viewer;

use Shipard\Core\I18n\DisplayDate;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;

/**
 * Období jednoho záznamu s dokladem a odvozeným stavem — jeden výpočet pro
 * tabulku období na záložce Fakturace i pro nabídky akcí Přegenerovat
 * (období s konceptem) a Obnovit (zastavená období).
 */
final class PeriodOverview
{
    private const DOC_STATE_DRAFT = 10;

    /**
     * @param list<array{period: array<string, mixed>, state: string, docId: int, doc: ?array<string, mixed>, label: string, documentLabel: string}> $entries
     */
    private function __construct(public readonly array $entries)
    {
    }

    /** Období záznamu, nejnovější nahoře. */
    public static function load(PeriodRepository $periods, int $recordId, InvoicingTexts $texts, string $language): self
    {
        $rows = $periods->listFor($recordId);
        $docInfo = $periods->docInfo(array_map(static fn(array $p): int => (int) ($p['doc'] ?? 0), $rows));

        $entries = [];
        foreach ($rows as $period) {
            $docId = (int) ($period['doc'] ?? 0);
            $doc = $docInfo[$docId] ?? null;
            $entries[] = [
                'period'        => $period,
                'state'         => PeriodRepository::effectiveState($period, $docInfo),
                'docId'         => $docId,
                'doc'           => $doc,
                'label'         => DisplayDate::date($period['period_from'], $language) . ' – ' . DisplayDate::date($period['period_to'], $language),
                'documentLabel' => $doc !== null ? self::documentLabel($doc, $docId, $texts) : '',
            ];
        }
        return new self($entries);
    }

    /**
     * Období, jejichž doklad je v Konceptu (ne zastavená) — Přegenerovat.
     *
     * @return list<array{value: string, label: string}>
     */
    public function regenerable(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if ($entry['doc'] !== null
                && (int) ($entry['doc']['docState'] ?? 0) === self::DOC_STATE_DRAFT
                && $entry['state'] !== PeriodRepository::STATE_STOPPED
            ) {
                $out[] = ['value' => (string) $entry['period']['id'], 'label' => $entry['label'] . ' — ' . $entry['documentLabel']];
            }
        }
        return $out;
    }

    /**
     * Zastavená období (doklad v koši nebo chybí) — Obnovit.
     *
     * @return list<array{value: string, label: string}>
     */
    public function stopped(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if ($entry['state'] === PeriodRepository::STATE_STOPPED) {
                $out[] = ['value' => (string) $entry['period']['id'], 'label' => $entry['label']];
            }
        }
        return $out;
    }

    /**
     * Číslo dokladu, nebo „koncept #id“ — koncept nese jen zástupné číslo
     * (`!000…`, DocDocument::afterPersist), to uživateli nic neřekne.
     *
     * @param array<string, mixed> $doc
     */
    private static function documentLabel(array $doc, int $docId, InvoicingTexts $texts): string
    {
        $number = (string) ($doc['doc_number'] ?? '');
        if ($number !== '' && !str_starts_with($number, '!')) {
            return $number;
        }
        return $texts->text('text.draft', 'draft') . ' #' . $docId;
    }
}
