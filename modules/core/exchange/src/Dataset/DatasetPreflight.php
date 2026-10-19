<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Item\ItemValidator;
use Shipard\Module\Core\Exchange\Person\PersonValidator;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Kontrola sady **před** resetem DS: manifest, každý soubor proti svému
 * schématu, sémantické validátory (bez DB), přítomnost příloh v sidecar
 * složkách, soulad `counts` s počtem souborů.
 *
 * Chyby blokují seed (nic se nesmaže ani nezapíše), varování se jen vypíší.
 */
final class DatasetPreflight
{
    private readonly SchemaValidator $exchange;
    private readonly SchemaValidator $mail;
    private readonly SchemaValidator $registry;

    public function __construct(?string $modulesDir = null)
    {
        $modulesDir ??= dirname(__DIR__, 4);
        $this->exchange = new SchemaValidator(new SchemaLoader($modulesDir . '/core/exchange/schemas'));
        $this->mail = new SchemaValidator(new SchemaLoader($modulesDir . '/core/mail/schemas'));
        $this->registry = new SchemaValidator(new SchemaLoader($modulesDir . '/base/registry/schemas'));
    }

    /**
     * @param string|null $dsCountry země cílového DS (`DataSourceConfig::getCountry()`);
     *        null / '' = neověřovat. Sada s `country` jiné země je chyba —
     *        nic se nezmění (#103 D24).
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(DatasetReader $reader, ?string $dsCountry = null): array
    {
        $errors = [];
        $warnings = [];

        try {
            $manifest = $reader->getManifest();
        } catch (DatasetException $e) {
            return ['errors' => [$e->getMessage()], 'warnings' => []];
        }

        if ($manifest->country !== null && $dsCountry !== null && $dsCountry !== ''
            && $manifest->country !== strtolower($dsCountry)) {
            $errors[] = "manifest country '{$manifest->country}' ≠ země zdroje dat '" . strtolower($dsCountry)
                . "' — sada patří jiné zemi";
        }

        $sections = [
            'setup'    => [$this->exchange, 'shpd.dataset.setup', null],
            // Kurzovní lístky (#121 D10) — sémantiku (typ, domácí měna) hlídá importer při seedu.
            'exchangeRates' => [$this->exchange, 'shpd.exchangeRates.list', null],
            'persons'  => [$this->exchange, 'shpd.persons.person', new PersonValidator()],
            'items'    => [$this->exchange, 'shpd.items.item', new ItemValidator()],
            'docs'     => [$this->exchange, 'shpd.docs.document', new DocumentValidator()],
            'registry' => [$this->registry, 'shpd.dataset.registryDocument', null],
            'mail'     => [$this->mail, 'shpd.mail.incomingMessage', null],
        ];

        foreach ($sections as $section => [$validator, $formatId, $semantic]) {
            $files = $reader->listFiles($section);
            foreach ($files as $rel) {
                try {
                    $data = $reader->readJsonc($rel);
                    // Schéma proti objektové formě — `{}` (kindFields, bloby)
                    // se nesmí posuzovat jako prázdné pole.
                    $objects = $reader->readJsoncObjects($rel);
                } catch (DatasetException $e) {
                    $errors[] = $e->getMessage();
                    continue;
                }
                $issues = $validator->validate($objects, $formatId, '1');
                if ($issues !== []) {
                    $errors[] = "{$rel}: " . self::describe($issues);
                    continue;
                }
                if ($semantic !== null) {
                    $semErrors = array_values(array_filter(
                        $semantic->validate($data),
                        static fn(array $i): bool => ($i['severity'] ?? '') === 'error',
                    ));
                    if ($semErrors !== []) {
                        $errors[] = "{$rel}: " . self::describe($semErrors);
                        continue;
                    }
                }
                foreach ($this->missingAttachments($reader, $rel, $data) as $missing) {
                    $warnings[] = "{$rel}: příloha '{$missing}' v sadě chybí";
                }
            }

            $expected = $manifest->counts[$section] ?? null;
            if ($expected !== null && $expected !== count($files)) {
                $warnings[] = "manifest counts.{$section} = {$expected}, v sadě je souborů " . count($files);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function missingAttachments(DatasetReader $reader, string $rel, array $data): array
    {
        $attachments = $data['attachments'] ?? null;
        if (!is_array($attachments)) {
            return [];
        }
        $dir = SeedContext::sidecarDir($rel);
        $missing = [];
        foreach ($attachments as $att) {
            $file = is_array($att) ? ($att['file'] ?? null) : null;
            if (!is_string($file) || $file === '') {
                continue;
            }
            try {
                if (!$reader->fileExists($dir . '/' . $file)) {
                    $missing[] = $file;
                }
            } catch (DatasetException) {
                $missing[] = $file;
            }
        }
        return $missing;
    }

    /**
     * @param list<array{path?: string, code?: string, message?: string}> $issues
     */
    private static function describe(array $issues): string
    {
        $parts = [];
        foreach (array_slice($issues, 0, 3) as $i) {
            $path = (string) ($i['path'] ?? '');
            $parts[] = ($path !== '' ? $path . ' ' : '') . ($i['code'] ?? '') . ' — ' . ($i['message'] ?? '');
        }
        $more = count($issues) > 3 ? ' (+' . (count($issues) - 3) . ')' : '';
        return implode('; ', $parts) . $more;
    }
}
