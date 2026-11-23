<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Shadow;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Dataset\Verify\ProposalApplyStep;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocuments;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentInterpreter;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;
use Shipard\Module\Core\Exchange\Document\Interpret\PendingRecords;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\MessageProposalApplier;
use Shipard\Tests\Integration\IntegrationTestCase;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\Shadow\ShadowRunner;

/**
 * Stínový test nad návrhy datové sady (#166 D57, U5): každý otevřený návrh
 * z pošty zdroje dat projde dnešní cestou návrhu i vstupem návrhu
 * (`DocumentProposal`) a obě musí dát totéž — odpovědi náhledu i uložení
 * a data předaná k uložení ({@see ShadowRunner}).
 *
 * Pouští se nad zdrojem s **čerstvě nahranou** veřejnou sadou
 * (`dataset-seed tests/datasets/cz/<sada> -y`):
 *
 *     SHIPARD_SHADOW_DATASET=1 SHIPARD_INTEGRATION_DS_PATH=<zdroj sady> vendor/bin/phpunit \
 *         --testsuite Integration --filter DatasetProposalShadowTest
 *
 * Návrhy použije stejně jako `dataset-verify` (zakládat vše, cíl V pořádku)
 * — **naostro**: zápis verdiktu u zprávy si otevírá vlastní transakci, takže
 * běh nejde zabalit a vrátit. Po testu jsou návrhy použité; další běh chce
 * nový seed (bez něj se test přeskočí). Proto běží jen na výslovné vyžádání
 * proměnnou `SHIPARD_SHADOW_DATASET=1` — běžný běh integrační sady návrhy
 * zdroje dat nepoužije. Nová cesta a varianta „jednoklik
 * z karty“ (zakládat bezpečně, cíl Koncept) běží v savepointu, který se
 * vrací zpět.
 *
 * Dočasný strážce přepnutí: zaniká se starou cestou v
 * `tasks/doc-interpretation-switch.md`.
 */
final class DatasetProposalShadowTest extends IntegrationTestCase
{
    public function testOpenProposalsGiveTheSameDocumentOnBothPaths(): void
    {
        if (getenv('SHIPARD_SHADOW_DATASET') !== '1') {
            $this->markTestSkipped('Test použije otevřené návrhy zdroje dat naostro — jen se SHIPARD_SHADOW_DATASET=1 nad čerstvě nahranou sadou.');
        }
        if (!$this->dsConfig->isFree()) {
            $this->markTestSkipped('Test používá návrhy z pošty — jen nad zdrojem dat s třídou `free`.');
        }
        $dibi = $this->db->getDibiConnection();
        $messageIds = array_map(
            static fn ($row): string => (string) $row['message_id'],
            $dibi->fetchAll('SELECT [message_id] FROM [core_mail_incoming_messages] WHERE [message_id] IS NOT NULL'),
        );
        $open = array_values(array_filter(
            SetDocuments::load($dibi, $messageIds),
            static fn ($document): bool => $document->hasOpenProposal(),
        ));
        if ($open === []) {
            $this->markTestSkipped('Zdroj dat nemá otevřené návrhy — nahraj veřejnou sadu (dataset-seed) a nepouštěj dataset-verify.');
        }

        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $config);
        $gateway = fn (string $class, string $table, bool $events = false): TransactionlessTableGateway => new $class(
            $table,
            $dibi,
            $registry,
            $this->tables[$table]->childTables,
            $config,
            $this->dsConfig,
            $events ? $dispatcher : null,
            $this->tables[$table]->docStates,
            $this->tables[$table],
        );
        /** @var RecordingGateway $heads */
        $heads = $gateway(RecordingGateway::class, 'docs_core_heads', true);
        $persons = $gateway(TransactionlessTableGateway::class, 'base_persons_persons');
        $items = $gateway(TransactionlessTableGateway::class, 'economy_items');

        $applier = ShadowingApplier::build($dibi, $config, $this->dsConfig, $this->tables, $heads, $persons, $items);
        $pending = new PendingRecords($dibi, $persons, $items);
        // Zápisy práce vrátí zpět — vlastní transakce, uvnitř jiné savepoint.
        $isolated = static function (\Closure $work) use ($dibi): mixed {
            $result = null;
            try {
                NestedTransaction::run($dibi, static function () use ($work, &$result): never {
                    $result = $work();
                    throw new ShadowRollback();
                });
            } catch (ShadowRollback) {
            }
            return $result;
        };
        $interpreter = DocumentInterpreter::create($dibi, $config, $this->dsConfig, $this->tables);
        $applier->shadow = new ShadowRunner(
            $applier,
            $interpreter,
            new DocumentProposal($dibi, new SchemaValidator(SchemaLoader::default()), $interpreter, $applier, $pending),
            static fn (): array => $heads->recorded,
            static function () use ($heads): void {
                $heads->recorded = [];
            },
            $isolated,
        );
        $proposals = new MessageProposalApplier(
            $this->db,
            $applier,
            RowEnrichmentPipeline::create($this->db, $config, $this->dsConfig),
            $config,
        );

        $applier->isolated = $isolated;
        $applier->variants = ['jako jednoklik' => ['autoCreateMode' => 'safe', 'targetDocState' => 10]];

        $outcomes = [];
        foreach ($open as $document) {
            $outcome = $proposals->apply($document->messageNdx, null, null, ProposalApplyStep::APPLY_OPTIONS);
            $key = $outcome->ok ? 'uloženo' : (string) $outcome->errorCode;
            $outcomes[$key] = ($outcomes[$key] ?? 0) + 1;
        }

        $this->assertSame(count($open), $applier->compared, 'každý otevřený návrh prošel porovnáním');
        $this->assertGreaterThan(0, $outcomes['uloženo'] ?? 0, 'aspoň jeden návrh se uložil');
        $this->assertSame([], $applier->differences, 'výsledky: ' . json_encode($outcomes, JSON_UNESCAPED_UNICODE));
    }
}
