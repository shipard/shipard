<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Corrective;

use Dibi\Connection;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Export\CanonicalParty;
use Shipard\Module\Docs\Core\DocTypes;

/**
 * Vystavit opravný doklad (#92 D6, tasks/corrective-doc-exchange.md U6–U7):
 * k potvrzenému dokladu typu s příznakem `corrective` založí koncept
 * opravného dokladu — canonical z {@see CorrectiveDocBuilder}, uložení přes
 * {@see DocumentApplier} (jediná cesta generovaných dokladů, CLAUDE.md →
 * Dokladový systém). Odkaz na původní doklad nese `_resolve.correction`,
 * applier z něj doplní číslo a datum plnění a sazby DPH hledá k původnímu
 * plnění; kurz jde z původního dokladu v canonicalu.
 *
 * Odmítnutí jako {@see CorrectiveDocException} (kód = HTTP status): doklad
 * neexistuje (404), typ bez příznaku nebo stav jiný než V pořádku (422
 * `CORRECTIVE_SOURCE_INVALID`), neúspěšné apply (422
 * `CORRECTIVE_APPLY_FAILED` s hláškami). Transakci drží applier.
 */
class CorrectiveDocService
{
    private const STATE_DONE = 40;

    public function __construct(
        private readonly Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly DocumentApplier $applier,
    ) {
    }

    /**
     * Složení z produkčního wiringu (controller akce detailu, vzor
     * InvoicingRunFactory).
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public static function create(
        Connection $db,
        ?ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?DocumentEventDispatcher $dispatcher = null,
    ): self {
        if ($config === null) {
            throw new \RuntimeException('Opravný doklad potřebuje zkompilovanou konfiguraci (ds-upgrade).');
        }
        return new self($db, $config, DocumentApplier::create($db, $config, $dsConfig, $registry, $tables, $dispatcher));
    }

    /**
     * @param string|null $today datum vystavení (`Y-m-d`), null = dnes
     * @return int id nového konceptu opravného dokladu
     * @throws CorrectiveDocException
     */
    public function createFor(int $docId, ?string $today = null): int
    {
        $head = $this->db->fetch(
            'SELECT [h.*], [vr.country] AS [vat_reg_country]
             FROM [docs_core_heads] AS [h]
             LEFT JOIN [economy_codebooks_vat_registrations] AS [vr] ON [vr.id] = [h.vat_registration]
             WHERE [h.id] = %i',
            $docId,
        );
        if ($head === null) {
            throw CorrectiveDocException::notFound($docId);
        }
        $head = is_array($head) ? $head : $head->toArray();

        $docType = (string) ($head['doc_type'] ?? '');
        if (!DocTypes::isCorrective($this->config, $docType)) {
            throw CorrectiveDocException::sourceInvalid('K tomuto typu dokladu opravný doklad vystavit nejde.');
        }
        if ((int) ($head['docState'] ?? 0) !== self::STATE_DONE) {
            throw CorrectiveDocException::sourceInvalid('Opravný doklad jde vystavit jen k dokladu ve stavu V pořádku.');
        }

        $dimensions = JournalDimensionSet::fromConfig($this->config);
        $rows = [];
        foreach ($this->rows($docId) as $row) {
            $row['dimensions'] = $this->dimensionKeys($dimensions, $row, head: false);
            $rows[] = $row;
        }

        $canonical = CorrectiveDocBuilder::build(
            $head,
            $rows,
            CanonicalParty::fromPerson($this->db, V::int($head['partner'] ?? null)),
            $this->dimensionKeys($dimensions, $head, head: true),
            $today ?? date('Y-m-d'),
        );

        $result = $this->applier->apply($canonical);
        if (!$result->success || $result->savedId === null) {
            throw CorrectiveDocException::applyFailed($result);
        }
        return $result->savedId;
    }

    /**
     * Řádky původního dokladu s kódem a názvem položky a kódem jednotky
     * (přirozené klíče pro canonical; položka navíc pinem podle id).
     *
     * @return list<array<string, mixed>>
     */
    private function rows(int $docId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [r.*], [i.code] AS [item_code], [i.name] AS [item_name],
                    [u.system_code] AS [unit_code], [u.shortcut] AS [unit_shortcut]
             FROM [docs_core_rows] AS [r]
             LEFT JOIN [economy_items] AS [i] ON [i.id] = [r.item]
             LEFT JOIN [core_units] AS [u] ON [u.id] = [r.unit]
             WHERE [r.doc_head] = %i
             ORDER BY [r.order_pos], [r.id]',
            $docId,
        );
        return array_map(static fn ($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /**
     * Dimenze deníku jako přirozené klíče (`exchangeKey`) — tak je čte
     * applier (`dimensions` objekt, DimensionResolver); vzor
     * DocumentExporter::dimensionValues.
     *
     * @param array<string, mixed> $record hlavička nebo řádek
     * @return array<string, string> id dimenze → klíč
     */
    private function dimensionKeys(JournalDimensionSet $dimensions, array $record, bool $head): array
    {
        $out = [];
        foreach ($dimensions as $dimension) {
            if ($dimension->exchangeKey === null) {
                continue;
            }
            $column = $head ? $dimension->headColumn : $dimension->rowColumn;
            if ($column === null) {
                continue;
            }
            $recordId = V::int($record[$column] ?? null);
            if ($recordId === null) {
                continue;
            }
            $key = V::str($this->db->fetchSingle(
                "SELECT [{$dimension->exchangeKey}] FROM [{$dimension->table}] WHERE [id] = %i",
                $recordId,
            ));
            if ($key !== null) {
                $out[$dimension->id] = $key;
            }
        }
        return $out;
    }
}
