<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Export\DocumentExporter;

/**
 * Společné prostředí testů invariantu ukládání (#166): doklady cestou
 * formuláře (`TableGateway`), applier a exportér nad skutečným zdrojem dat,
 * fixtury s prefixem `IT-RT` a úklid po sobě. Sdílí ho matice round-tripu
 * (`DocumentRoundTripMatrixTest`) a případy payloadů pokynu
 * (`DocumentInstructionPayloadTest`).
 *
 * Třída, která trait používá, dědí `IntegrationTestCase`.
 */
trait RoundTripEnvironment
{
    private RoundTripFixtures $fixtures;
    private DocumentApplier $applier;
    private ConfigRuntime $configRuntime;
    private FormPath $formPath;

    /** Volat ze `setUp()` po `parent::setUp()`; přeskočí test, když zdroj dat nestačí. */
    private function bootRoundTripEnvironment(): void
    {
        if (!$this->dsConfig->isFree()) {
            $this->markTestSkipped('Test zapisuje a maže doklady — jen nad zdrojem dat s třídou `free`.');
        }
        if ($this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 AND docState IN (10, 40, 80) LIMIT 1') === null) {
            $this->markTestSkipped('Zdroj dat nemá vlastní firmu (is_own).');
        }
        if ($this->db->fetchRow('SELECT id FROM economy_codebooks_vat_registrations WHERE docState = 40 LIMIT 1') === null) {
            $this->markTestSkipped('Zdroj dat nemá registraci DPH.');
        }

        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $dibi = $this->db->getDibiConnection();
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $this->configRuntime);

        $gateway = new TableGateway(
            'docs_core_heads',
            $dibi,
            $registry,
            $this->tables['docs_core_heads']->childTables,
            $this->configRuntime,
            $this->dsConfig,
            $dispatcher,
            // Jako FormController: gateway odvozuje docStateMain ze stavu
            // a zná definici tabulky.
            $this->tables['docs_core_heads']->docStates,
            $this->tables['docs_core_heads'],
        );
        $this->applier = DocumentApplier::create(
            $dibi,
            $this->configRuntime,
            $this->dsConfig,
            $registry,
            $this->tables,
            $dispatcher,
        );

        // Cesta formuláře přes skutečný FormController (invariant U10).
        $this->formPath = new FormPath(
            $this->db, $this->tables, $this->configRuntime, $this->dsConfig, $resolver, $registry, $dispatcher,
        );

        $this->fixtures = new RoundTripFixtures($this->db, $gateway, $this->configRuntime, $this->dsConfig);
        // Zbytky po spadlém běhu by rozbily jednoznačnost partnerů a čísel.
        $this->fixtures->purge();
        $this->fixtures->snapshotCounters();
    }

    /** Volat z `onTearDown()`. */
    private function shutdownRoundTripEnvironment(): void
    {
        if (isset($this->fixtures)) {
            $this->fixtures->purge();
            $this->fixtures->restoreCounters();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function export(int $headId): array
    {
        $records = (new DocumentExporter($this->db->getDibiConnection(), $this->configRuntime, tables: $this->tables))->exportByIds([$headId]);
        $this->assertCount(1, $records, "Doklad #{$headId} nejde exportovat.");
        return $records[0]->data;
    }
}
