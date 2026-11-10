<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Popisky polí metadat Spisovny (#133): každé pole každého druhu z
 * `docKinds.jsonc` má v katalogu `viewerDetailLabels.jsonc` (sekce
 * `fields`) položku s holým `name` i oběma jazyky — jinak detail dokumentu
 * ukáže technický klíč. Položka, kterou žádný druh nemá, je mrtvý text.
 */
class RegistryDocKindsLabelsTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../../../modules/base/registry/config';
    private const NAMES = ['name', 'name:cs', 'name:en'];

    /** @return array<string, list<string>> druh → pole */
    private static function kindFields(): array
    {
        $out = [];
        foreach (JsoncParser::parseFile(self::CONFIG_DIR . '/docKinds.jsonc') as $kind => $def) {
            $out[(string) $kind] = array_map('strval', $def['fields'] ?? []);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function fieldLabels(): array
    {
        return JsoncParser::parseFile(self::CONFIG_DIR . '/viewerDetailLabels.jsonc')['fields'] ?? [];
    }

    public function testDocKindsDeclareFields(): void
    {
        $fields = array_merge(...array_values(self::kindFields()));
        $this->assertNotEmpty($fields, 'docKinds.jsonc: žádný druh nemá pole — test by nic nehlídal');
    }

    public function testEveryFieldOfEveryKindHasLabel(): void
    {
        $labels = self::fieldLabels();
        foreach (self::kindFields() as $kind => $fields) {
            foreach ($fields as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $labels,
                    "Druh {$kind}, pole {$field}: chybí popisek ve viewerDetailLabels.jsonc → fields",
                );
                foreach (self::NAMES as $name) {
                    $this->assertIsString(
                        $labels[$field][$name] ?? null,
                        "Druh {$kind}, pole {$field}: popisek nemá {$name}",
                    );
                    $this->assertNotSame(
                        '',
                        trim($labels[$field][$name]),
                        "Druh {$kind}, pole {$field}: prázdný {$name}",
                    );
                }
            }
        }
    }

    public function testNoLabelWithoutField(): void
    {
        $known = array_unique(array_merge(...array_values(self::kindFields())));
        foreach (array_keys(self::fieldLabels()) as $field) {
            $this->assertContains(
                $field,
                $known,
                "viewerDetailLabels.jsonc → fields.{$field}: pole nemá žádný druh v docKinds.jsonc",
            );
        }
    }
}
