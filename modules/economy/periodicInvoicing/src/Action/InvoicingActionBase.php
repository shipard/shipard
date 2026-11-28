<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Action;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunFactory;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunService;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;
use Shipard\Module\Economy\PeriodicInvoicing\Viewer\InvoicingTexts;
use Shipard\Module\Economy\PeriodicInvoicing\Viewer\PeriodOverview;

/**
 * Společný základ akcí záznamu periodické fakturace (#175 D8). Akce jsou
 * společné všem zdrojům: zdroj je deklaruje v `recordActions` nad svou
 * tabulkou a akce si ho podle tabulky najde (`InvoicingSources::forTable`)
 * — id období platí jen v evidenci zdroje, proto se běh nikdy nevolá bez
 * něj. Tabulka bez zdroje = akce není dostupná.
 *
 * Běh se skládá z kontextu akce (`RecordActionContext::$documents`,
 * `$tables`, `$dispatcher`, U3); `available()` a `describe()` ho
 * nepotřebují. Zdroje, evidence období a služba jsou švy pro testy.
 */
abstract class InvoicingActionBase implements RecordAction
{
    public const FIELD_PERIOD = 'period';

    /** @var array<string, PeriodOverview> „zdroj:id záznamu“ → období (jeden dotaz na požadavek) */
    private array $overviews = [];

    protected function sources(RecordActionContext $context): InvoicingSources
    {
        return InvoicingSources::fromConfig($context->config, $context->db->getDibiConnection(), $context->dsConfig);
    }

    protected function periods(PeriodicInvoicingSource $source, RecordActionContext $context): PeriodRepository
    {
        return PeriodRepository::forSource($context->db->getDibiConnection(), $source);
    }

    /** @throws \RuntimeException kontext nenese služby dokladů (volání mimo endpoint akce) */
    protected function service(RecordActionContext $context): InvoicingRunService
    {
        if ($context->documents === null || $context->dsConfig === null) {
            throw new \RuntimeException('Periodic invoicing action needs the document registry in the action context.');
        }
        return InvoicingRunFactory::create(
            $context->db->getDibiConnection(),
            $context->config,
            $context->dsConfig,
            $context->documents,
            $context->tables,
            $context->dispatcher,
        );
    }

    protected function source(string $table, RecordActionContext $context): ?PeriodicInvoicingSource
    {
        return $this->sources($context)->forTable($table);
    }

    /** Zdroj tabulky při provedení — akce bez zdroje by neprošla `available()`. */
    protected function requireSource(string $table, RecordActionContext $context): PeriodicInvoicingSource
    {
        return $this->source($table, $context)
            ?? throw new \RuntimeException("No periodic invoicing source is registered for table '{$table}'.");
    }

    /** @param array<string, mixed> $record */
    protected function overview(PeriodicInvoicingSource $source, array $record, RecordActionContext $context): PeriodOverview
    {
        $recordId = (int) ($record['id'] ?? 0);
        return $this->overviews[$source->id() . ':' . $recordId] ??= PeriodOverview::load(
            $this->periods($source, $context),
            $recordId,
            $this->texts($context),
            $context->language,
        );
    }

    protected function texts(RecordActionContext $context): InvoicingTexts
    {
        return new InvoicingTexts($context->config);
    }
}
