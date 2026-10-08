<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Docs\Core\DocsHeadsViewer;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoiceBuilder;
use Shipard\Module\Economy\WorkOrders\WorkOrdersViewer;

/**
 * Koncepty z periodické fakturace ke kontrole (tasks §6): **jeden**
 * souhrnný nález „N konceptů … ke kontrole“ — ne karta na doklad (zdroj
 * s ≈95 fakturami měsíčně by zahltil feed). Akce otevře viewer faktur
 * (a zálohových faktur, jsou-li) s filtrem zdroje Periodická fakturace.
 * Bez konceptů žádný nález → alert se uzavře.
 */
class InvoicesToReviewCheck extends AlertCheck
{
    public const FINDING_KEY = 'summary';

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $counts = $this->draftCounts();
        $total = array_sum($counts);
        if ($total === 0) {
            return [];
        }

        $actions = [];
        foreach ($counts as $docType => $count) {
            if ($count <= 0) {
                continue;
            }
            $viewerId = WorkOrdersViewer::DOC_TYPE_VIEWERS[$docType] ?? WorkOrdersViewer::DOCUMENTS_VIEWER;
            $actions[] = [
                'id'      => 'open_' . $docType,
                'label'   => $this->viewerLabel($docType, $count, $isCs),
                'kind'    => 'open_viewer',
                'primary' => $actions === [],
                'target'  => [
                    'viewerId' => $viewerId,
                    'filters'  => [DocsHeadsViewer::FILTER_SOURCE_KIND => InvoiceBuilder::SOURCE_KIND],
                ],
            ];
        }

        return [new AlertFinding(
            findingKey: self::FINDING_KEY,
            title: $isCs
                ? self::pluralCs($total, 'koncept', 'koncepty', 'konceptů') . ' z periodické fakturace ke kontrole'
                : ($total === 1 ? '1 periodic invoicing draft to review' : "{$total} periodic invoicing drafts to review"),
            message: $isCs
                ? 'Běh periodické fakturace vystavil koncepty faktur — zkontroluj je a potvrď (V pořádku), nebo je smaž: smazaný koncept období zastaví.'
                : 'The periodic invoicing run issued invoice drafts — review and confirm them, or delete them: a deleted draft stops its period.',
            severity: 'info',
            actions: $actions,
            context: ['total' => $total, 'byDocType' => $counts],
        )];
    }

    /**
     * Koncepty se zdrojem Periodická fakturace per typ dokladu (seam pro testy).
     *
     * @return array<string, int> doc_type → počet
     */
    protected function draftCounts(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [doc_type], COUNT(*) AS [n] FROM [docs_core_heads]'
            . ' WHERE [source_kind] = %s AND [docState] = %i GROUP BY [doc_type] ORDER BY [doc_type]',
            InvoiceBuilder::SOURCE_KIND,
            10,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['doc_type']] = (int) $row['n'];
        }
        return $out;
    }

    private function viewerLabel(string $docType, int $count, bool $isCs): string
    {
        $name = $this->config->cfgItem('docs.core.docTypes')[$docType]['name'] ?? $docType;
        return ($isCs ? 'Otevřít: ' : 'Open: ') . $name . " ({$count})";
    }

    public static function pluralCs(int $n, string $one, string $few, string $many): string
    {
        $word = $n === 1 ? $one : ($n >= 2 && $n <= 4 ? $few : $many);
        return "{$n} {$word}";
    }
}
