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
 *
 * Přehled neopakuje hlavičku (#140 D26): nad pěti nejnovějšími záznamy
 * každého vieweru se žádná položka prvního tabu soustavně nerovná titulku,
 * části podtitulku ani štítku. Porovnání je ve statických metodách
 * (`overviewRepeats()`, `systematicRepeats()`), které pokrývá unit test
 * `OverviewRepeatCheckTest` bez zdroje dat.
 */
class ViewerDetailContractTest extends IntegrationTestCase
{
    /** Oddělovač částí podtitulku — `RecordPattern` i viewery s `implode(' · ')`. */
    private const SUBTITLE_SEPARATOR = ' · ';

    private const PART_NAMES = [
        'title'    => 'titulek',
        'subtitle' => 'část podtitulku',
        'badge'    => 'štítek',
    ];

    /**
     * Shody hodnot, které nejsou opakováním hlavičky (#140 §1): jiný údaj
     * se stejnou hodnotou. Viewer id → popisky položek Přehledu.
     */
    private const OVERVIEW_REPEAT_ALLOWED = [
        // Druh položky se často jmenuje stejně jako typ položky (Služba / Služba).
        'economy.items.kinds' => ['Typ položky'],
        // Součty případu s jediným pohybem = částka toho pohybu v podtitulku.
        'economy.accbal.ledger' => ['Předpisy', 'Úhrady', 'Zůstatek'],
        // Kód bankovního účtu bývá jeho pořadové číslo.
        'economy.codebooks.bankAccounts' => ['Pořadí'],
        // Systémový kód jednotky se v seedu shoduje se zkratkou (m, kg, l…); hlavička ho nenese.
        'core.units' => ['Systémový kód'],
        // Splatnost bankovních a pokladních zápisů = datum zápisu v podtitulku; jiný údaj.
        'economy.accounting.journal' => ['Splatnost'],
    ];

    /**
     * Položky, které hlavičku opakují a čekají na odstranění (#140 D26) —
     * commity po modulech je ubírají, poslední konstantu smaže. Nevyužitá
     * výjimka test neshazuje (data zdrojů se liší).
     */
    private const PENDING = [
    ];

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

    // -------------------------------------------------------------------------
    // Porovnání Přehledu s hlavičkou (čisté funkce, bez zdroje dat)
    // -------------------------------------------------------------------------

    /**
     * Položky prvního tabu detailu s neprázdnou hodnotou: `properties` →
     * `groups[].items`, `composite` → `blocks` rekurzivně; `document` a ostatní
     * typy obsahu položky nemají.
     *
     * @param array<string, mixed> $detail
     * @return list<array{label: string, value: string}>
     */
    public static function overviewItems(array $detail): array
    {
        $content = $detail['tabs'][0]['content'] ?? null;
        return is_array($content) ? self::collectItems($content) : [];
    }

    /**
     * Položky Přehledu, které opakují hlavičku: hodnota se (bez okrajových
     * mezer, bez HTML, bez ohledu na velikost písmen) rovná titulku, jedné
     * části podtitulku nebo popisku štítku, anebo se část podtitulku rovná
     * „popisek hodnota“ / „popisek: hodnota“. Vrací popisek a druh části
     * (`title` / `subtitle` / `badge`), nikdy hodnoty.
     *
     * @param array<string, mixed> $detail
     * @return list<array{label: string, part: string}>
     */
    public static function overviewRepeats(array $detail): array
    {
        $title = self::normalize((string) ($detail['title'] ?? ''));

        $subtitleParts = [];
        foreach (explode(self::SUBTITLE_SEPARATOR, (string) ($detail['subtitle'] ?? '')) as $part) {
            $part = self::normalize($part);
            if ($part !== '') {
                $subtitleParts[] = $part;
            }
        }

        $badges = [];
        foreach ($detail['badges'] ?? [] as $badge) {
            $label = self::normalize((string) ($badge['label'] ?? ''));
            if ($label !== '') {
                $badges[] = $label;
            }
        }

        $repeats = [];
        foreach (self::overviewItems($detail) as ['label' => $label, 'value' => $value]) {
            $normValue = self::normalize($value);
            $normLabel = self::normalize($label);
            if ($normValue === '') {
                continue;
            }

            $part = null;
            if ($title !== '' && $normValue === $title) {
                $part = 'title';
            } elseif (
                in_array($normValue, $subtitleParts, true)
                || ($normLabel !== '' && in_array($normLabel . ' ' . $normValue, $subtitleParts, true))
                || ($normLabel !== '' && in_array($normLabel . ': ' . $normValue, $subtitleParts, true))
            ) {
                $part = 'subtitle';
            } elseif (in_array($normValue, $badges, true)) {
                $part = 'badge';
            }

            if ($part !== null) {
                $repeats[] = ['label' => $label, 'part' => $part];
            }
        }
        return $repeats;
    }

    /**
     * Soustavné opakování nad vzorkem detailů jednoho vieweru: dvojice
     * (popisek, část hlavičky) se shoduje ve všech záznamech, kde položka je,
     * a takových záznamů jsou aspoň dva. Ojedinělá shoda (kód náhodou stejný
     * jako název) není chyba; popisky z `$allowedLabels` se vynechají.
     *
     * @param list<array<string, mixed>> $details
     * @param list<string> $allowedLabels
     * @return list<array{label: string, part: string}>
     */
    public static function systematicRepeats(array $details, array $allowedLabels = []): array
    {
        $present = []; // popisek → počet záznamů s položkou
        $matched = []; // "popisek\0část" → počet záznamů se shodou

        foreach ($details as $detail) {
            foreach (array_unique(array_column(self::overviewItems($detail), 'label')) as $label) {
                $present[$label] = ($present[$label] ?? 0) + 1;
            }
            $pairs = [];
            foreach (self::overviewRepeats($detail) as ['label' => $label, 'part' => $part]) {
                $pairs[$label . "\0" . $part] = true;
            }
            foreach (array_keys($pairs) as $key) {
                $matched[$key] = ($matched[$key] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($matched as $key => $count) {
            [$label, $part] = explode("\0", (string) $key, 2);
            if (in_array($label, $allowedLabels, true)) {
                continue;
            }
            $presentCount = $present[$label] ?? 0;
            if ($presentCount < 2 || $count < $presentCount) {
                continue;
            }
            $out[] = ['label' => $label, 'part' => $part];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $content
     * @return list<array{label: string, value: string}>
     */
    private static function collectItems(array $content): array
    {
        $items = [];
        switch ($content['type'] ?? null) {
            case 'properties':
                foreach ($content['groups'] ?? [] as $group) {
                    foreach ($group['items'] ?? [] as $item) {
                        $raw = $item['value'] ?? null;
                        $value = is_scalar($raw) ? trim((string) $raw) : '';
                        if ($value === '') {
                            continue;
                        }
                        $items[] = ['label' => (string) ($item['label'] ?? ''), 'value' => $value];
                    }
                }
                break;
            case 'composite':
                foreach ($content['blocks'] ?? [] as $block) {
                    if (is_array($block)) {
                        $items = [...$items, ...self::collectItems($block)];
                    }
                }
                break;
        }
        return $items;
    }

    private static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return mb_strtolower(trim($text));
    }

    // -------------------------------------------------------------------------
    // Testy nad zdrojem dat
    // -------------------------------------------------------------------------

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

    /**
     * Přehled neopakuje hlavičku (#140 D26): pět nejnovějších záznamů každého
     * vieweru; chyba je jen soustavná shoda (viz `systematicRepeats()`).
     * Hláška nese viewer, popisek a druh části hlavičky — nikdy hodnoty,
     * zdroj dat může nést reálná data.
     */
    public function testOverviewDoesNotRepeatHeader(): void
    {
        $checked = 0;
        $failures = [];
        foreach ($this->tableViewers() as $def) {
            $rows = $this->db->fetchAll('SELECT `id` FROM `' . $def->table . '` ORDER BY `id` DESC LIMIT 5');
            $details = [];
            foreach ($rows as $row) {
                $response = $this->detail($def->id, (int) $row['id']);
                if (self::responseStatus($response) === 404) {
                    continue;
                }
                $this->assertSame(200, self::responseStatus($response), "viewer {$def->id}, záznam {$row['id']}");
                $details[] = $response->getPayload()['data']['detail'];
            }
            if ($details === []) {
                continue;
            }
            $checked++;

            $allowed = [
                ...(self::OVERVIEW_REPEAT_ALLOWED[$def->id] ?? []),
                ...(self::PENDING[$def->id] ?? []),
            ];
            foreach (self::systematicRepeats($details, $allowed) as ['label' => $label, 'part' => $part]) {
                $failures[] = sprintf(
                    'viewer %s: položka „%s“ v Přehledu opakuje %s hlavičky (%d z %d vzorků)',
                    $def->id,
                    $label,
                    self::PART_NAMES[$part] ?? $part,
                    count($details),
                    count($details),
                );
            }
        }
        $this->assertSame([], $failures, implode("\n", $failures));
        if ($checked === 0) {
            $this->markTestSkipped('Žádný tabulkový viewer nemá na zdroji dat záznam.');
        }
    }
}
