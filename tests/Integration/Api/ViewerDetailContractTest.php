<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Api;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\PrintDefinitionLoader;
use Shipard\Api\Response;
use Shipard\Api\ViewerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kontrakt detailu záznamu nad skutečným zdrojem dat (#136 D23), jen
 * čtení: každý tabulkový viewer, jehož tabulka na zdroji dat je, vrátí přes
 * `ViewerController::detail()` pro první záznam tabulky hlavičku s titulkem
 * a ikonou; záznam s přílohou má její id v `detail.attachments.groups`
 * (umístění `overview` / `tab`). Prázdné tabulky se přeskakují.
 */
class ViewerDetailContractTest extends IntegrationTestCase
{
    private ViewerRegistry $registry;
    private ConfigRuntime $config;
    private DocumentRegistry $documents;
    private PrintRegistry $prints;
    private ViewerController $controller;
    private AuthContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->registry = ViewerLoader::load($this->dsConfig, $resolver, 'cs');
        $this->registry->setTables($this->tables);
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->documents = DocumentLoader::load($this->dsConfig, $resolver);
        $this->prints = PrintDefinitionLoader::load($this->dsConfig, $resolver, 'cs');
        $this->controller = new ViewerController();
        $this->admin = new AuthContext(true, 1, 'session', 'shpd_st_test', isAdmin: true);
    }

    /** @return list<ViewerDefinition> tabulkové viewery, jejichž tabulka na zdroji dat je */
    private function tableViewers(): array
    {
        $out = [];
        foreach ($this->registry->getAll() as $def) {
            if ($def->table === null || !isset($this->tables[$def->table])) {
                continue;
            }
            $out[] = $def;
        }
        return $out;
    }

    private function detail(string $viewerId, int $recordId): Response
    {
        return $this->controller->detail(
            $viewerId,
            $recordId,
            $this->admin,
            $this->registry,
            $this->tables,
            $this->db,
            $this->config,
            'cs',
            $this->documents,
            $this->dsConfig,
            $this->prints,
        );
    }

    private static function responseStatus(Response $response): int
    {
        return (new \ReflectionProperty($response, 'status'))->getValue($response);
    }

    public function testTableViewersRenderHeaderForFirstRecord(): void
    {
        $checked = 0;
        foreach ($this->tableViewers() as $def) {
            $id = $this->db->fetchSingle('SELECT `id` FROM `' . $def->table . '` ORDER BY `id` LIMIT 1');
            if ($id === null) {
                continue;
            }
            $response = $this->detail($def->id, (int) $id);
            $this->assertSame(200, self::responseStatus($response), "viewer {$def->id}, záznam {$id}: " . json_encode($response->getPayload()));

            $detail = $response->getPayload()['data']['detail'];
            $this->assertNotSame('', trim((string) ($detail['title'] ?? '')), "viewer {$def->id}: prázdný titulek");
            $this->assertNotSame('', (string) ($detail['icon'] ?? ''), "viewer {$def->id}: chybí ikona");
            $this->assertIsArray($detail['badges'] ?? null, "viewer {$def->id}: chybí badges");
            $this->assertNotEmpty($detail['tabs'] ?? [], "viewer {$def->id}: detail bez tabů");
            $checked++;
        }
        if ($checked === 0) {
            $this->markTestSkipped('Žádný tabulkový viewer nemá na zdroji dat záznam.');
        }
    }

    public function testRecordAttachmentsAppearInDetail(): void
    {
        $checked = 0;
        foreach ($this->tableViewers() as $def) {
            if ($def->detailAttachments === ViewerDefinition::ATTACHMENTS_CONTENT) {
                continue;
            }
            $file = $this->db->fetchRow(
                'SELECT `id`, `record_id` FROM `core_attachments_files`'
                . ' WHERE `table_id` = %i AND `is_deleted` = 0 ORDER BY `id` LIMIT 1',
                $this->tables[$def->table]->tableId,
            );
            if ($file === null) {
                continue;
            }
            $response = $this->detail($def->id, (int) $file['record_id']);
            if (self::responseStatus($response) === 404) {
                continue; // příloha osiřelého záznamu
            }
            $this->assertSame(200, self::responseStatus($response), "viewer {$def->id}, záznam {$file['record_id']}");

            $detail = $response->getPayload()['data']['detail'];
            $ids = [];
            foreach ($detail['attachments']['groups'] ?? [] as $group) {
                foreach ($group['attachments'] ?? [] as $attachment) {
                    $ids[] = (int) $attachment['id'];
                }
            }
            $this->assertContains(
                (int) $file['id'],
                $ids,
                "viewer {$def->id}: příloha {$file['id']} záznamu {$file['record_id']} chybí v detail.attachments",
            );
            if ($def->detailAttachments === ViewerDefinition::ATTACHMENTS_TAB) {
                $tabs = array_column($detail['tabs'], 'id');
                $this->assertSame('attachments', end($tabs), "viewer {$def->id}: tab Přílohy není poslední");
            }
            $checked++;
        }
        if ($checked === 0) {
            $this->markTestSkipped('Žádný tabulkový viewer nemá na zdroji dat záznam s přílohou.');
        }
    }
}
