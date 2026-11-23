<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\Controller\ExchangeController;
use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Endpoint `POST /_exchange/docs/document/interpret` (#166 D55, U4) nad
 * skutečným zdrojem dat: návrhy z pošty zdroje (papírová podoba dokladu
 * z poslední analýzy zprávy) vrátí jako hotový doklad a zprávu, nic
 * neuloží — a hotový doklad poslaný na `/apply` jako pokyn dá **stejný
 * doklad** jako `/apply` s `applyOptions.interpret: true`.
 *
 * Pouští se nad zdrojem s nahranou veřejnou sadou (návrhy mohou i nemusí
 * být použité — test čte jen jejich předlohu a chybějící záznamy si založí
 * prvním použitím). Vše běží ve vnější transakci, která se vrací zpět;
 * každé porovnávané uložení navíc ve vlastním savepointu.
 */
final class DocumentInterpretEndpointTest extends IntegrationTestCase
{
    /** Kolik návrhů sady stačí porovnat. */
    private const SAMPLE = 6;

    private ExchangeController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->dsConfig->isFree()) {
            $this->markTestSkipped('Test ukládá doklady (a vrací je) — jen nad zdrojem dat s třídou `free`.');
        }
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        $dibi = $this->db->getDibiConnection();
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $config);
        $applier = DocumentApplier::create($dibi, $config, $this->dsConfig, $registry, $this->tables, $dispatcher);
        $this->controller = new ExchangeController(
            $applier,
            proposal: DocumentProposal::create($dibi, $config, $this->dsConfig, $registry, $this->tables, $dispatcher, $applier),
        );
        $dibi->begin();
    }

    protected function onTearDown(): void
    {
        if (isset($this->controller)) {
            $this->db->getDibiConnection()->rollback();
        }
    }

    /**
     * Předlohy dokladů z analýz pošty zdroje dat — bez vazby na zprávu
     * (idempotence podle zprávy a zápis vazby tu nejsou předmětem testu).
     *
     * @return list<array<string, mixed>>
     */
    private function papers(): array
    {
        $papers = [];
        $rows = $this->db->fetchAll(
            'SELECT canonical_json FROM core_mail_message_analyses WHERE status = %i AND canonical_json IS NOT NULL ORDER BY id',
            2,
        );
        foreach ($rows as $row) {
            $paper = json_decode((string) $row['canonical_json'], true);
            if (!is_array($paper) || ($paper['format'] ?? null) !== 'shpd.docs.document' || isset($paper['_validationError'])) {
                continue;
            }
            unset($paper['source']['message'], $paper['source']['ref']);
            $paper['applyOptions'] = ['targetDocState' => 10];
            $papers[] = $paper;
        }
        return $papers;
    }

    /** @param array<string, mixed> $body */
    private function post(string $action, array $body): Response
    {
        $request = Request::fromArray(
            'POST',
            "/api/v1/_exchange/docs/document/{$action}",
            [],
            (string) json_encode($body),
            ['HTTP_HOST' => 'localhost'],
        );
        return $this->controller->{$action}($request);
    }

    /**
     * Uložený doklad bez toho, co se mezi dvěma uloženími liší z principu
     * (id, zástupné číslo konceptu, časy).
     *
     * @return array{head: array<string, mixed>, rows: list<array<string, mixed>>, recap: list<array<string, mixed>>}
     */
    private function saved(int $docId): array
    {
        $strip = function (string $table, array $row): array {
            foreach ($this->db->fetchAll(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s'
                . ' AND DATA_TYPE IN (%s, %s)',
                $table, 'datetime', 'timestamp',
            ) as $column) {
                unset($row[(string) $column['COLUMN_NAME']]);
            }
            unset($row['id'], $row['doc_head'], $row['doc_number']);
            return $row;
        };
        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertNotNull($head, "doklad {$docId} není");
        return [
            'head'  => $strip('docs_core_heads', $head),
            'rows'  => array_map(
                static fn (array $row): array => $strip('docs_core_rows', $row),
                $this->db->fetchAll('SELECT * FROM docs_core_rows WHERE doc_head = %i ORDER BY order_pos, id', $docId),
            ),
            'recap' => array_map(
                static fn (array $row): array => $strip('docs_core_vat_recap', $row),
                $this->db->fetchAll('SELECT * FROM docs_core_vat_recap WHERE doc_head = %i ORDER BY id', $docId),
            ),
        ];
    }

    /** Spustí práci v savepointu a vrátí ji zpět. */
    private function rolledBack(\Closure $work): mixed
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->begin('interpret_endpoint');
        try {
            return $work();
        } finally {
            $dibi->rollback('interpret_endpoint');
        }
    }

    public function testInterpretedDocumentAppliedAsInstructionEqualsApplyWithInterpret(): void
    {
        $papers = $this->papers();
        if ($papers === []) {
            $this->markTestSkipped('Zdroj dat nemá analýzy pošty s předlohou dokladu — nahraj veřejnou sadu (dataset-seed).');
        }
        $headsBefore = (int) $this->db->fetchRow('SELECT COUNT(*) AS c FROM docs_core_heads')['c'];

        $compared = 0;
        foreach ($papers as $paper) {
            // Záznamy, které předloha potřebuje (dodavatel, položky), založí
            // první použití návrhu — zůstane ve vnější transakci testu. Další
            // interpretace téže předlohy už nic zakládat nemusí.
            $prepared = $this->post('apply', ['applyOptions' => ['targetDocState' => 10, 'autoCreateMode' => 'liberal', 'interpret' => true]] + $paper);
            if ($prepared->getStatus() !== 200) {
                continue;
            }
            $paper['docNumber'] = ($paper['docNumber'] ?? 'IT') . '/2';

            $interpreted = $this->post('interpret', $paper);
            $this->assertSame(200, $interpreted->getStatus(), json_encode($interpreted->getPayload(), JSON_UNESCAPED_UNICODE));
            $data = $interpreted->getPayload()['data'];
            $this->assertSame(['document', 'report'], array_keys($data));
            $errors = array_filter($data['report']['issues'], static fn (array $issue): bool => $issue['severity'] === 'error');
            if ($data['report']['pending'] !== [] || $errors !== []) {
                // Záznam k založení nebo rozhodnutí — pokyn by doklad odmítl (U4).
                continue;
            }

            $label = (string) ($paper['docNumber'] ?? '?');
            $asInstruction = $this->rolledBack(function () use ($data, $label): array {
                $response = $this->post('apply', $data['document']);
                $this->assertSame(200, $response->getStatus(), $label . ' pokyn: ' . json_encode($response->getPayload(), JSON_UNESCAPED_UNICODE));
                return $this->saved((int) $response->getPayload()['data']['savedDocId']);
            });
            $asProposal = $this->rolledBack(function () use ($paper, $label): array {
                $paper['applyOptions']['interpret'] = true;
                $response = $this->post('apply', $paper);
                $this->assertSame(200, $response->getStatus(), $label . ' návrh: ' . json_encode($response->getPayload(), JSON_UNESCAPED_UNICODE));
                return $this->saved((int) $response->getPayload()['data']['savedDocId']);
            });

            $this->assertSame($asProposal, $asInstruction, "doklad {$label}");
            if (++$compared >= self::SAMPLE) {
                break;
            }
        }

        if ($compared === 0) {
            $this->markTestSkipped('Žádný návrh zdroje dat nejde interpretovat bez zakládání záznamů.');
        }
        // `/interpret` sám nic neuložil a porovnávaná uložení se vrátila —
        // přibyly jen doklady z přípravy (po jednom na předlohu).
        $this->assertLessThanOrEqual(
            $headsBefore + count($papers),
            (int) $this->db->fetchRow('SELECT COUNT(*) AS c FROM docs_core_heads')['c'],
        );
        $this->assertGreaterThan(0, $compared);
    }

    public function testInterpretReportsWhatHasToBeCreatedAndSavesNothing(): void
    {
        $paper = [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'invoiceReceived',
            'selfParty'     => 'customer',
            'docNumber'     => 'IT-INTERPRET-1',
            'currency'      => 'CZK',
            'dates'         => ['issueDate' => '2026-03-10', 'taxPointDate' => '2026-03-10'],
            'supplier'      => ['name' => 'IT Interpret Endpoint s.r.o.', 'companyId' => '88' . random_int(100000, 999999), 'country' => 'CZ'],
            'rows'          => [['description' => 'Služba', 'quantity' => 1, 'unitPrice' => 500.0, 'totalPrice' => 500.0, 'vat' => ['pct' => 21]]],
            'applyOptions'  => ['autoCreateMode' => 'liberal'],
        ];

        $response = $this->post('interpret', $paper);

        $this->assertSame(200, $response->getStatus());
        $data = $response->getPayload()['data'];
        $this->assertArrayNotHasKey('ref', $data['document']['supplier']);
        $this->assertSame(['supplier'], array_column($data['report']['pending'], 'key'));
        $this->assertSame('supplier.ref', $data['report']['pending'][0]['ref']);
        $this->assertNull($this->db->fetchRow('SELECT id FROM base_persons_persons WHERE company_id = %s', $paper['supplier']['companyId']));

        // Hotový doklad s nezaloženým dodavatelem pokyn odmítne.
        $rejected = $this->post('apply', $data['document']);
        $this->assertSame(422, $rejected->getStatus());
        $codes = array_column($rejected->getPayload()['error']['details']['canonical']['_resolve']['issues'], 'code');
        $this->assertContains('reference_missing', $codes);
    }
}
