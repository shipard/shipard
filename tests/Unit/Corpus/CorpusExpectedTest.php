<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Isdoc\IsdocSchemaValidator;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Strážce korpusu testovacích dokladů (tasks/testing-phase1.md, docs/test-corpus.md).
 *
 * Očekávané výsledky `tests/corpus/<země>/expected/<profil>/NNNN-<slug>.json`
 * jsou papírová projekce canonicalu shpd.docs.document.v1: musí projít
 * schématem, mít přesně klíče projekce `tests/corpus/projection.jsonc`
 * (v jejím pořadí, nic navíc, nic nechybí), hodnoty v kanonickém tvaru
 * (tvary D15 jako regexy / kontroly projekce), aritmeticky sedící
 * rekapitulaci a součty (doklad bez DPH: prázdná vatRecap), štítky
 * z taxonomie, a ke každému existuje PDF.
 * Matice datový soubor × profil (bez `skipProfiles`) musí být úplná bez
 * sirotků. Projekce sama musí být podmnožinou schématu ve stejném pořadí.
 * Datové sady `tests/datasets/<země>/<profil>/` (F2, tasks/testing-phase2.md)
 * musí odpovídat korpusu: zpráva per buňka s `canonicalJson`, který je po
 * odebrání doplněných polí (`source`, `attachments`, `tagSource`,
 * `tagConfidence`) shodný s očekáváním, PDF v sidecaru = PDF korpusu.
 *
 * Oprava: očekávaný výsledek se needituje ručně — oprav datový soubor nebo
 * generátor (tools/invoice-generator/) a spusť `python3 tests/corpus/cz/build.py`;
 * změna projekce = změna projection.jsonc + rebuild + aktualizace
 * docs/test-corpus.md. Jeden doklad:
 * `vendor/bin/phpunit --filter 'CorpusExpectedTest.*0101-domestic-goods'`.
 */
final class CorpusExpectedTest extends TestCase
{
    private const PROFILES = ['vat-payer', 'non-payer', 'association'];
    private const CHECKS = ['number', 'amount', 'index', 'tag'];
    private const TOLERANCE_CENTS = 1;

    /** @var array<string, mixed>|null */
    private static ?array $projection = null;
    /** @var array<string, mixed>|null */
    private static ?array $taxonomy = null;
    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    // ── Zdroje ──────────────────────────────────────────────────────────────

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function corpusDir(): string
    {
        return self::repoRoot() . '/tests/corpus';
    }

    /** @return array<string, mixed> */
    private static function projection(): array
    {
        return self::$projection ??= JsoncParser::parseFile(self::corpusDir() . '/projection.jsonc');
    }

    /** @return array<string, mixed> */
    private static function taxonomy(): array
    {
        return self::$taxonomy ??= JsoncParser::parseFile(
            self::repoRoot() . '/modules/core/exchange/config/contentTags.jsonc',
        );
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return self::$schema ??= json_decode(
            (string) file_get_contents(self::repoRoot() . '/modules/core/exchange/schemas/shpd.docs.document.v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function expectedFiles(): array
    {
        $files = glob(self::corpusDir() . '/*/expected/*/*.json') ?: [];
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            $stem = basename($file, '.json');
            $profile = basename(dirname($file));
            $country = basename(dirname($file, 3));
            $cases["{$country}/{$profile}/{$stem}"] = [$country, $profile, $stem];
        }
        return $cases;
    }

    private static function expectedPath(string $country, string $profile, string $stem): string
    {
        return self::corpusDir() . "/{$country}/expected/{$profile}/{$stem}.json";
    }

    private static function expectedRaw(string $country, string $profile, string $stem): string
    {
        return (string) file_get_contents(self::expectedPath($country, $profile, $stem));
    }

    /** @return array<string, mixed> */
    private static function expectedDoc(string $country, string $profile, string $stem): array
    {
        return json_decode(self::expectedRaw($country, $profile, $stem), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── Projekce ────────────────────────────────────────────────────────────

    public function testProjectionIsWellFormedAndMatchesSchema(): void
    {
        $projection = self::projection();
        $problems = [];
        $this->assertSame('shpd.corpus.projection', $projection['format'] ?? null);
        $this->assertSame('1', $projection['formatVersion'] ?? null);
        foreach (['document', '$defs', 'shapes', 'nullableNodes', 'informativeNodes'] as $key) {

            $this->assertArrayHasKey($key, $projection);
        }

        foreach ($projection['shapes'] as $name => $shape) {
            if (!is_array($shape) || count($shape) !== 1) {
                $problems[] = "tvar {$name}: právě jeden klíč regex|const|check|byCountry";
                continue;
            }
            $kind = array_key_first($shape);
            if ($kind === 'regex') {
                if (str_contains($shape['regex'], '~') || @preg_match('~' . $shape['regex'] . '~uD', '') === false) {
                    $problems[] = "tvar {$name}: regex se nezkompiluje nebo obsahuje '~'";
                }
            } elseif ($kind === 'check') {
                if (!in_array($shape['check'], self::CHECKS, true)) {
                    $problems[] = "tvar {$name}: neznámá kontrola {$shape['check']}";
                }
            } elseif ($kind === 'byCountry') {
                if (!isset($shape['byCountry']['*'])) {
                    $problems[] = "tvar {$name}: byCountry bez '*'";
                }
                foreach ($shape['byCountry'] as $target) {
                    if (!isset($projection['shapes'][$target]) || isset($projection['shapes'][$target]['byCountry'])) {
                        $problems[] = "tvar {$name}: byCountry odkazuje na neznámý nebo vnořený tvar {$target}";
                    }
                }
            } elseif ($kind !== 'const') {
                $problems[] = "tvar {$name}: neznámý druh {$kind}";
            }
        }

        $this->checkSpecTree($projection['document'], 'document', $projection, $problems, 0);
        foreach ($projection['$defs'] as $def => $spec) {
            $this->checkSpecTree($spec, '$defs.' . $def, $projection, $problems, 0);
        }
        $this->checkProjectionAgainstSchema($projection['document'], self::schema()['properties'], '', $projection, $problems);

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /** @param list<string> $problems */
    private function checkSpecTree(mixed $spec, string $path, array $projection, array &$problems, int $depth): void
    {
        if ($depth > 10) {
            $problems[] = "{$path}: příliš hluboké vnoření (cyklický \$ref?)";
            return;
        }
        if (is_array($spec) && array_key_exists('$ref', $spec)) {
            if (count($spec) !== 1 || !isset($projection['$defs'][$spec['$ref']])) {
                $problems[] = "{$path}: \$ref musí být jediný klíč a cíl musí existovat";
            }
            return;
        }
        if (is_array($spec) && array_is_list($spec)) {
            if (count($spec) !== 1) {
                $problems[] = "{$path}: pole projekce má právě jeden prvek";
                return;
            }
            $this->checkSpecTree($spec[0], $path . '[]', $projection, $problems, $depth + 1);
            return;
        }
        if (is_array($spec)) {
            if ($spec === []) {
                $problems[] = "{$path}: prázdný objekt projekce";
            }
            foreach ($spec as $key => $sub) {
                $this->checkSpecTree($sub, "{$path}.{$key}", $projection, $problems, $depth + 1);
            }
            return;
        }
        if ($spec !== null && !isset($projection['shapes'][$spec])) {
            $problems[] = "{$path}: neznámý tvar " . json_encode($spec);
        }
    }

    /** @param array<string, mixed> $schemaProps @param list<string> $problems */
    private function checkProjectionAgainstSchema(array $node, array $schemaProps, string $path, array $projection, array &$problems): void
    {
        $keys = array_keys($node);
        $missing = array_diff($keys, array_keys($schemaProps));
        if ($missing !== []) {
            $problems[] = ($path ?: '/') . ": klíče projekce mimo schéma " . implode(', ', $missing);
            return;
        }
        $schemaOrder = array_values(array_intersect(array_keys($schemaProps), $keys));
        if ($keys !== $schemaOrder) {
            $problems[] = ($path ?: '/') . ': pořadí projekce [' . implode(',', $keys) . '] ≠ schéma [' . implode(',', $schemaOrder) . ']';
        }
        foreach ($node as $key => $spec) {
            $sub = $schemaProps[$key];
            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
            if ($key === '_resolve') {
                continue;
            }
            if (is_array($spec) && array_key_exists('$ref', $spec)) {
                $spec = $projection['$defs'][$spec['$ref']];
            }
            if (is_array($spec) && array_is_list($spec)) {
                $item = $spec[0];
                if (is_array($item) && array_key_exists('$ref', $item)) {
                    $item = $projection['$defs'][$item['$ref']];
                }
                $itemSchema = $this->resolveSchema($sub['items'] ?? []);
                if (is_array($item) && isset($itemSchema['properties'])) {
                    $this->checkProjectionAgainstSchema($item, $itemSchema['properties'], $childPath . '[]', $projection, $problems);
                }
                continue;
            }
            if (is_array($spec)) {
                $subSchema = $this->resolveSchema($sub);
                if (!isset($subSchema['properties'])) {
                    $problems[] = "{$childPath}: schéma tu nemá objekt";
                    continue;
                }
                $this->checkProjectionAgainstSchema($spec, $subSchema['properties'], $childPath, $projection, $problems);
            }
        }
    }

    /** @return array<string, mixed> */
    private function resolveSchema(array $schema): array
    {
        if (isset($schema['$ref']) && str_starts_with($schema['$ref'], '#/$defs/')) {
            return self::schema()['$defs'][substr($schema['$ref'], strlen('#/$defs/'))];
        }
        return $schema;
    }

    // ── Matice ──────────────────────────────────────────────────────────────

    public function testCorpusMatrixIsComplete(): void
    {
        $dataFiles = glob(self::corpusDir() . '/*/data/*.jsonc') ?: [];
        $this->assertNotEmpty($dataFiles, 'tests/corpus/*/data je prázdný — korpus musí mít datové soubory');
        sort($dataFiles);

        $problems = [];
        $cells = [];
        foreach ($dataFiles as $file) {
            $stem = basename($file, '.jsonc');
            $countryDir = dirname($file, 2);
            $country = basename($countryDir);
            if (preg_match('/^[0-9]{2}0[12]-[a-z0-9-]+$/', $stem) !== 1) {
                $problems[] = "{$country}/data/{$stem}.jsonc: název neodpovídá NNNN-<slug>";
            }
            $doc = JsoncParser::parseFile($file);
            $form = $doc['form'] ?? null;
            $skip = $doc['skipProfiles'] ?? [];
            foreach ($skip as $profile => $reason) {
                if (!in_array($profile, self::PROFILES, true)) {
                    $problems[] = "{$stem}: skipProfiles.{$profile}: neznámý profil";
                }
                if (!is_string($reason) || trim($reason) === '') {
                    $problems[] = "{$stem}: skipProfiles.{$profile}: chybí důvod";
                }
            }
            foreach (self::PROFILES as $profile) {
                if (isset($skip[$profile])) {
                    continue;
                }
                $cells["{$country}/{$profile}/{$stem}"] = true;
                $expected = self::expectedPath($country, $profile, $stem);
                if (!is_file($expected)) {
                    $problems[] = "{$country}/{$profile}/{$stem}: chybí očekávaný výsledek";
                }
                $pdf = "{$countryDir}/out/{$profile}/{$stem}.pdf";
                if (!is_file($pdf)) {
                    $problems[] = "{$country}/{$profile}/{$stem}: chybí PDF";
                } elseif (!str_starts_with((string) file_get_contents($pdf, false, null, 0, 5), '%PDF-')) {
                    $problems[] = "{$country}/{$profile}/{$stem}: out/*.pdf není PDF";
                }
                // Podoba pdfIsdoc má .isdoc (#119 krok 5), podoba pdf nikdy.
                $isdoc = "{$countryDir}/out/{$profile}/{$stem}.isdoc";
                if ($form === 'pdfIsdoc') {
                    if (!is_file($isdoc)) {
                        $problems[] = "{$country}/{$profile}/{$stem}: chybí ISDOC (podoba pdfIsdoc)";
                    } elseif (!str_starts_with((string) file_get_contents($isdoc, false, null, 0, 5), '<?xml')) {
                        $problems[] = "{$country}/{$profile}/{$stem}: out/*.isdoc není XML";
                    }
                } elseif (is_file($isdoc)) {
                    $problems[] = "{$country}/{$profile}/{$stem}: ISDOC u podoby {$form}";
                }
            }
        }

        foreach (glob(self::corpusDir() . '/*/identities.jsonc') ?: [] as $file) {
            $identities = JsoncParser::parseFile($file);
            $profiles = array_keys($identities['profiles'] ?? []);
            sort($profiles);
            $expectedProfiles = self::PROFILES;
            sort($expectedProfiles);
            if ($profiles !== $expectedProfiles) {
                $problems[] = basename(dirname($file)) . '/identities.jsonc: profily ' . implode(', ', $profiles)
                    . ' ≠ ' . implode(', ', $expectedProfiles);
            }
        }

        foreach ([['expected', '*.json'], ['out', '*.pdf'], ['out', '*.isdoc']] as [$dir, $pattern]) {
            foreach (glob(self::corpusDir() . "/*/{$dir}/*/{$pattern}") ?: [] as $file) {
                $key = basename(dirname($file, 3)) . '/' . basename(dirname($file)) . '/' . pathinfo($file, PATHINFO_FILENAME);
                if (!isset($cells[$key])) {
                    $problems[] = "{$dir}: sirotek {$key} bez buňky matice";
                }
            }
            foreach (glob(self::corpusDir() . "/*/{$dir}/*", GLOB_ONLYDIR) ?: [] as $profileDir) {
                if (!in_array(basename($profileDir), self::PROFILES, true)) {
                    $problems[] = "{$dir}: neznámý profil " . basename($profileDir);
                }
            }
        }

        $this->assertGreaterThan(0, count($cells));
        $this->assertSame([], $problems, count($cells) . " buněk\n" . implode("\n", $problems));
    }

    /**
     * Sady jsou generované z korpusu (build.py) — tenhle test chytí
     * zapomenutý rebuild: každá buňka má zprávu, žádná zpráva není navíc,
     * ideální analýza = očekávání + doplněná pole, PDF stejné; podoba
     * `pdfIsdoc` má ISDOC korpusu jako druhou přílohu (`order` 2, text/xml),
     * podoba `pdf` jen PDF (#103 D75).
     */
    public function testDatasetsMatchCorpus(): void
    {
        $datasetsDir = self::repoRoot() . '/tests/datasets';
        $problems = [];
        $seen = [];
        foreach (glob($datasetsDir . '/*/*/mail/*.jsonc') ?: [] as $file) {
            $profile = basename(dirname($file, 2));
            $country = basename(dirname($file, 3));
            $label = "{$country}/{$profile}/" . basename($file);
            $message = JsoncParser::parseFile($file);
            $attachments = $message['attachments'] ?? [];
            $pdfName = (string) ($attachments[0]['file'] ?? '');
            $stem = basename($pdfName, '.pdf');
            if ($pdfName === '' || !str_ends_with($pdfName, '.pdf') || count($attachments) < 1 || count($attachments) > 2) {
                $problems[] = "{$label}: zpráva musí mít PDF jako první přílohu a nejvýš jednu další";
                continue;
            }
            $key = "{$country}/{$profile}/{$stem}";
            if (isset($seen[$key])) {
                $problems[] = "{$label}: druhá zpráva pro buňku {$key}";
            }
            $seen[$key] = true;
            if (!is_file(self::expectedPath($country, $profile, $stem))) {
                $problems[] = "{$label}: zpráva bez buňky matice {$key}";
                continue;
            }
            $dataFile = self::corpusDir() . "/{$country}/data/{$stem}.jsonc";
            $form = is_file($dataFile) ? (JsoncParser::parseFile($dataFile)['form'] ?? null) : null;
            $sidecarDir = substr($file, 0, -strlen('.jsonc')) . '.files/';
            if ($form === 'pdfIsdoc') {
                $isdoc = $attachments[1] ?? null;
                $corpusIsdoc = self::corpusDir() . "/{$country}/out/{$profile}/{$stem}.isdoc";
                if ($isdoc === null || ($isdoc['file'] ?? null) !== "{$stem}.isdoc" || ($isdoc['name'] ?? null) !== "{$stem}.isdoc"
                    || ($isdoc['mimeType'] ?? null) !== 'text/xml' || ($isdoc['order'] ?? null) !== 2) {
                    $problems[] = "{$label}: podoba pdfIsdoc čeká druhou přílohu {$stem}.isdoc (text/xml, order 2)";
                } elseif (!is_file($sidecarDir . $isdoc['file']) || !is_file($corpusIsdoc)
                    || sha1_file($sidecarDir . $isdoc['file']) !== sha1_file($corpusIsdoc)) {
                    $problems[] = "{$label}: ISDOC v sidecaru ≠ out/{$profile}/{$stem}.isdoc";
                }
            } elseif (count($attachments) !== 1) {
                $problems[] = "{$label}: podoba {$form} má mít jen PDF, zpráva má " . count($attachments) . ' přílohy';
            }
            $canonical = $message['analyses'][0]['canonicalJson'] ?? null;
            if (!is_array($canonical) || ($message['analyses'][0]['status'] ?? null) !== 2
                || array_key_exists('resolution', $message['analyses'][0])) {
                $problems[] = "{$label}: chybí otevřený úspěšný návrh (analyses[0])";
                continue;
            }
            if (count($canonical['attachments'] ?? []) !== 1 || ($canonical['attachments'][0]['ref'] ?? null) !== 'att:1') {
                $problems[] = "{$label}: ideální analýza nese jen PDF (att:1) — ISDOC se do ní nepřidává";
            }
            unset($canonical['source'], $canonical['attachments']);
            unset($canonical['_resolve']['contentTag']['tagSource'], $canonical['_resolve']['contentTag']['tagConfidence']);
            if ($canonical != self::expectedDoc($country, $profile, $stem)) {
                $problems[] = "{$label}: canonicalJson po odebrání doplněných polí ≠ expected/{$profile}/{$stem}.json";
            }
            $sidecar = $sidecarDir . $pdfName;
            $corpusPdf = self::corpusDir() . "/{$country}/out/{$profile}/{$stem}.pdf";
            if (!is_file($sidecar) || !is_file($corpusPdf) || sha1_file($sidecar) !== sha1_file($corpusPdf)) {
                $problems[] = "{$label}: PDF v sidecaru ≠ out/{$profile}/{$stem}.pdf";
            }
            if (($message['docState'] ?? null) !== 20 || ($message['analysisState'] ?? null) !== 30) {
                $problems[] = "{$label}: zpráva má být K řešení (20) a Analyzováno (30)";
            }
        }
        foreach (array_keys(self::expectedFiles()) as $key) {
            if (!isset($seen[$key])) {
                $problems[] = "{$key}: buňka bez zprávy v tests/datasets — spusť python3 tests/corpus/cz/build.py";
            }
        }

        $this->assertNotEmpty($seen, 'tests/datasets/*/*/mail je prázdný — spusť python3 tests/corpus/cz/build.py');
        $this->assertSame([], $problems, count($seen) . " zpráv\n" . implode("\n", $problems));
    }

    // ── Per soubor ──────────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function isdocFiles(): iterable
    {
        foreach (glob(self::corpusDir() . '/*/out/*/*.isdoc') ?: [] as $file) {
            $key = basename(dirname($file, 3)) . '/' . basename(dirname($file)) . '/' . basename($file, '.isdoc');
            yield $key => [$file];
        }
    }

    /** Každý .isdoc korpusu projde XSD 6.0.1 i 6.0.2 (#119 D6). */
    #[DataProvider('isdocFiles')]
    public function testIsdocFilePassesBothSchemas(string $file): void
    {
        $errors = (new IsdocSchemaValidator())->validateAll((string) file_get_contents($file));
        $this->assertSame([], $errors, basename($file));
    }

    #[DataProvider('expectedFiles')]
    public function testExpectedFileIsValidCanonical(string $country, string $profile, string $stem): void
    {
        $payload = json_decode(self::expectedRaw($country, $profile, $stem), false, 512, JSON_THROW_ON_ERROR);
        $issues = (new SchemaValidator(SchemaLoader::default()))
            ->validate($payload, DocumentApplier::FORMAT_ID, DocumentApplier::FORMAT_VERSION);
        $this->assertSame([], $issues, json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    #[DataProvider('expectedFiles')]
    public function testExpectedFileMatchesProjection(string $country, string $profile, string $stem): void
    {
        $raw = self::expectedRaw($country, $profile, $stem);
        $problems = [];
        if (!str_ends_with($raw, "\n")) {
            $problems[] = 'soubor nekončí \n';
        }
        if (str_contains($raw, "\t")) {
            $problems[] = 'soubor obsahuje tabulátor';
        }
        if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $raw) === 1) {
            $problems[] = 'soubor escapuje UTF-8 (\uXXXX)';
        }
        foreach (explode("\n", rtrim($raw, "\n")) as $n => $line) {
            if (preg_match('/^( {2})*\S/', $line) !== 1) {
                $problems[] = 'řádek ' . ($n + 1) . ': odsazení není násobek 2 mezer';
                break;
            }
        }

        $projection = self::projection();
        $ctx = [
            'shapes' => $projection['shapes'],
            'defs' => $projection['$defs'],
            'nullable' => $projection['nullableNodes'],
            'taxonomy' => self::taxonomy(),
            'problems' => $problems,
        ];
        $this->walk($projection['document'], self::expectedDoc($country, $profile, $stem), '', null, $ctx);
        $this->assertSame([], $ctx['problems'], implode("\n", $ctx['problems']));
    }

    /** @param array<string, mixed> $ctx */
    private function walk(mixed $spec, mixed $value, string $path, ?array $parent, array &$ctx): void
    {
        if (is_array($spec) && array_key_exists('$ref', $spec)) {
            $spec = $ctx['defs'][$spec['$ref']];
        }
        $node = preg_replace('/\[[0-9]+\]/', '[]', $path);
        if (is_array($spec) && array_is_list($spec)) {
            if ($value === null) {
                if (!in_array($node, $ctx['nullable'], true)) {
                    $ctx['problems'][] = "{$path}: pole nesmí být null";
                }
                return;
            }
            if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
                $ctx['problems'][] = "{$path}: očekáváno pole";
                return;
            }
            foreach ($value as $i => $item) {
                $this->walk($spec[0], $item, "{$path}[{$i}]", null, $ctx);
            }
            return;
        }
        if (is_array($spec)) {
            if ($value === null) {
                if (!in_array($node, $ctx['nullable'], true)) {
                    $ctx['problems'][] = "{$path}: objekt projekce musí být přítomný (null jen u " . implode(', ', $ctx['nullable']) . ')';
                }
                return;
            }
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                $ctx['problems'][] = "{$path}: očekáván objekt";
                return;
            }
            $want = array_keys($spec);
            $have = array_keys($value);
            $missing = array_diff($want, $have);
            $extra = array_diff($have, $want);
            if ($missing !== []) {
                $ctx['problems'][] = ($path ?: '/') . ': chybí klíče projekce ' . implode(', ', $missing);
            }
            if ($extra !== []) {
                $ctx['problems'][] = ($path ?: '/') . ': klíče mimo projekci ' . implode(', ', $extra);
            }
            if ($missing === [] && $extra === [] && $want !== $have) {
                $ctx['problems'][] = ($path ?: '/') . ': pořadí klíčů [' . implode(',', $have) . '] ≠ projekce [' . implode(',', $want) . ']';
            }
            foreach ($spec as $key => $sub) {
                if (array_key_exists($key, $value)) {
                    $this->walk($sub, $value[$key], $path === '' ? (string) $key : "{$path}.{$key}", $value, $ctx);
                }
            }
            return;
        }
        if (is_array($value)) {
            $ctx['problems'][] = "{$path}: očekáván skalár";
            return;
        }
        if ($spec !== null) {
            $this->checkShape($spec, $value, $path, $parent, $ctx);
        }
    }

    /** @param array<string, mixed> $ctx */
    private function checkShape(string $name, mixed $value, string $path, ?array $parent, array &$ctx): void
    {
        $shape = $ctx['shapes'][$name];
        if (isset($shape['byCountry'])) {
            $country = $parent['country'] ?? '*';
            $name = $shape['byCountry'][$country] ?? $shape['byCountry']['*'];
            $shape = $ctx['shapes'][$name];
        }
        $shown = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (array_key_exists('const', $shape)) {
            if ($value !== $shape['const']) {
                $ctx['problems'][] = "{$path}: {$shown} ≠ const " . json_encode($shape['const']) . " ({$name})";
            }
            return;
        }
        if ($value === null) {
            return;
        }
        if (isset($shape['regex'])) {
            if (!is_string($value) || preg_match('~' . $shape['regex'] . '~uD', $value) !== 1) {
                $ctx['problems'][] = "{$path}: {$shown} neodpovídá tvaru {$name} /{$shape['regex']}/";
            }
            return;
        }
        $ok = match ($shape['check']) {
            'number' => is_int($value) || is_float($value),
            'amount' => (is_int($value) || is_float($value)) && abs($value * 100 - round($value * 100)) < 1e-6,
            'index' => is_int($value) && $value >= 0,
            'tag' => is_string($value) && isset($ctx['taxonomy'][$value]),
        };
        if (!$ok) {
            $ctx['problems'][] = "{$path}: {$shown} neprošlo kontrolou {$name}";
        }
    }

    #[DataProvider('expectedFiles')]
    public function testExpectedFileArithmetic(string $country, string $profile, string $stem): void
    {
        $doc = self::expectedDoc($country, $profile, $stem);
        $problems = [];
        $rows = $doc['rows'] ?? null;
        if (!is_array($rows) || $rows === []) {
            $this->fail('rows chybí nebo je prázdné');
        }

        $byPct = [];
        foreach ($rows as $i => $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            $price = (float) ($row['unitPrice'] ?? 0);
            $total = self::cents($row['totalPrice'] ?? 0);
            $gross = self::cents($qty * $price);
            if (($row['priceCalcMode'] ?? null) === 'fromTotal') {
                if (abs($gross - $total) >= 100) {
                    $problems[] = "rows[{$i}]: fromTotal — totalPrice se od qty × unitPrice liší o 1,00 a víc";
                }
            } else {
                $disc = (float) ($row['discountPct'] ?? 0);
                $computed = self::cents(round($qty * $price * (1 - $disc / 100), 2));
                if (abs($computed - $total) > self::TOLERANCE_CENTS) {
                    $problems[] = "rows[{$i}]: totalPrice {$total} ≠ qty × unitPrice × sleva {$computed} (haléře)";
                }
            }
            $pct = $row['vat']['pct'] ?? null;
            $byPct[$pct === null ? 'none' : (string) $pct] = ($byPct[$pct === null ? 'none' : (string) $pct] ?? 0) + $total;
        }

        $mode = $doc['vat']['mode'] ?? null;
        $totals = $doc['totals'] ?? [];
        $amount = self::cents($totals['totalAmount'] ?? 0);
        $rounding = self::cents($totals['totalRounding'] ?? 0);
        $rowsSum = array_sum($byPct);

        if ($mode === 'none') {
            if (($doc['vatRecap'] ?? null) !== []) {
                $problems[] = 'vat.mode none: vatRecap musí být prázdné pole';
            }
            if (($totals['totalBase'] ?? null) !== null || ($totals['totalVat'] ?? null) !== null) {
                $problems[] = 'vat.mode none: totalBase a totalVat musí být null';
            }
            if ($rounding !== 0) {
                $problems[] = 'vat.mode none: totalRounding musí být 0';
            }
            if (array_keys($byPct) !== ['none']) {
                $problems[] = 'vat.mode none: řádky nesmí mít sazbu';
            }
            if ($rowsSum !== $amount) {
                $problems[] = "vat.mode none: Σ řádků {$rowsSum} ≠ totalAmount {$amount}";
            }
        } else {
            $recap = $doc['vatRecap'] ?? null;
            if (!is_array($recap) || $recap === []) {
                $this->fail('vatRecap chybí nebo je prázdná');
            }
            $recapPcts = [];
            $sumBase = $sumTax = $sumTotal = 0;
            foreach ($recap as $i => $entry) {
                $pct = (string) $entry['vatPct'];
                if (isset($recapPcts[$pct])) {
                    $problems[] = "vatRecap[{$i}]: sazba {$pct} vícekrát";
                }
                $recapPcts[$pct] = true;
                $base = self::cents($entry['base']);
                $tax = self::cents($entry['tax']);
                $total = self::cents($entry['total']);
                if (abs($base + $tax - $total) > self::TOLERANCE_CENTS) {
                    $problems[] = "vatRecap[{$i}]: base + tax ≠ total";
                }
                if (abs(self::cents(round($base / 100 * (float) $entry['vatPct'] / 100, 2)) - $tax) > self::TOLERANCE_CENTS) {
                    $problems[] = "vatRecap[{$i}]: tax ≠ base × pct";
                }
                $rowsForPct = $byPct[$pct] ?? null;
                if ($rowsForPct === null) {
                    $problems[] = "vatRecap[{$i}]: sazba {$pct} bez řádků";
                } elseif ($mode === 'fromTotal' && $rowsForPct !== $total) {
                    $problems[] = "vatRecap[{$i}]: fromTotal — Σ řádků {$rowsForPct} ≠ total {$total}";
                } elseif ($mode === 'fromBase' && $rowsForPct !== $base) {
                    $problems[] = "vatRecap[{$i}]: fromBase — Σ řádků {$rowsForPct} ≠ base {$base}";
                }
                $sumBase += $base;
                $sumTax += $tax;
                $sumTotal += $total;
            }
            foreach (array_keys($byPct) as $pct) {
                if (!isset($recapPcts[$pct])) {
                    $problems[] = "řádky se sazbou {$pct} nemají rekapitulaci";
                }
            }
            if ($sumBase !== self::cents($totals['totalBase'] ?? 0)) {
                $problems[] = 'Σ base ≠ totalBase';
            }
            if ($sumTax !== self::cents($totals['totalVat'] ?? 0)) {
                $problems[] = 'Σ tax ≠ totalVat';
            }
            if ($sumTotal + $rounding !== $amount) {
                $problems[] = "Σ total {$sumTotal} + zaokrouhlení {$rounding} ≠ totalAmount {$amount}";
            }
            if (self::cents($totals['totalBase'] ?? 0) + self::cents($totals['totalVat'] ?? 0) + $rounding !== $amount) {
                $problems[] = 'totalBase + totalVat + totalRounding ≠ totalAmount';
            }
        }

        $exceptions = $doc['_resolve']['contentTag']['rowExceptions'] ?? null;
        $docTag = $doc['_resolve']['contentTag']['tag'] ?? null;
        if (!is_array($exceptions)) {
            $problems[] = 'rowExceptions musí být pole (prázdné, když výjimky nejsou)';
        } else {
            $seen = [];
            foreach ($exceptions as $i => $exception) {
                $index = $exception['rowIndex'] ?? null;
                if (!is_int($index) || $index < 0 || $index >= count($rows)) {
                    $problems[] = "rowExceptions[{$i}]: rowIndex mimo řádky";
                } elseif (isset($seen[$index])) {
                    $problems[] = "rowExceptions[{$i}]: rowIndex {$index} vícekrát";
                }
                $seen[$index] = true;
                if (($exception['tag'] ?? null) === $docTag) {
                    $problems[] = "rowExceptions[{$i}]: výjimka shodná se štítkem dokladu";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    #[DataProvider('expectedFiles')]
    public function testExpectedFileMatchesDataFile(string $country, string $profile, string $stem): void
    {
        $dataFile = self::corpusDir() . "/{$country}/data/{$stem}.jsonc";
        $this->assertFileExists($dataFile, 'očekávání bez datového souboru');
        $data = JsoncParser::parseFile($dataFile);
        $overrides = $data['overrides'] ?? [];
        $override = $overrides[$profile] ?? [];
        if (is_string($override)) {
            $override = $overrides[$override] ?? [];
        }
        $merged = self::deepMerge($data, is_array($override) ? $override : []);
        $doc = self::expectedDoc($country, $profile, $stem);

        $problems = [];
        if (($doc['docNumber'] ?? null) !== ($merged['docNumber'] ?? null)) {
            $problems[] = 'docNumber ≠ datový soubor';
        }
        if (($doc['currency'] ?? null) !== ($merged['currency'] ?? null)) {
            $problems[] = 'currency ≠ datový soubor';
        }
        if (count($doc['rows'] ?? []) !== count($merged['rows'] ?? [])) {
            $problems[] = 'počet řádků ≠ datový soubor';
        }
        if (($doc['_resolve']['contentTag']['tag'] ?? null) !== ($merged['contentTag'] ?? null)) {
            $problems[] = 'contentTag ≠ datový soubor';
        }
        $receipt = ($merged['template'] ?? null) === 'receipt';
        $customerNull = array_key_exists('customer', $doc) && $doc['customer'] === null;
        $dueNull = array_key_exists('dueDate', $doc['dates'] ?? []) && $doc['dates']['dueDate'] === null;
        if ($receipt !== ($customerNull && $dueNull)) {
            $problems[] = 'účtenka ⇔ customer null a dueDate null';
        }
        // Podoba je jen pole `form`; název nese číslo varianty a pořadí dokladu
        // (01 / 02), ne podobu (#119 krok 1). D10: ISDOC jen mezi dvěma českými
        // subjekty s IČO — účtenka (bez odběratele) ho nedostane.
        $form = $merged['form'] ?? null;
        if (!in_array($form, ['pdf', 'pdfIsdoc'], true)) {
            $problems[] = 'neznámá podoba ' . var_export($form, true);
        } elseif ($form === 'pdfIsdoc' && (
            ($doc['supplier']['country'] ?? null) !== 'cz'
            || ($doc['supplier']['companyId'] ?? null) === null
            || $customerNull
        )) {
            $problems[] = 'podoba pdfIsdoc jen u českého dodavatele s IČO a dokladu s odběratelem (D10 #119)';
        }
        preg_match('/^([0-9]{2})(0[12])-(.+)$/', $stem, $m);
        if (($merged['variant'] ?? null) !== ($m[3] ?? null)) {
            $problems[] = 'varianta z názvu ≠ datový soubor';
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private static function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $override @return array<string, mixed> */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
