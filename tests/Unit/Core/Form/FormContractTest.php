<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\MultiLookupBinding;
use Shipard\Core\Form\TabBuilder;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Kontrakt formulářů bez HTML (#150 D82, obdoba D30 v detailu —
 * `DetailContractTest`): prvek `html` neexistuje, `TabBuilder::html()` také
 * ne a žádný zdroj formuláře v `src/` a `modules/` ho nevolá ani nestaví
 * `FormElement` typu `html`; žádná jsonc definice formuláře nemá
 * `"type": "html"`. Jednoduchý obsah nese prvek `content` (bloky detailu),
 * složitější pojmenovaná komponenta.
 *
 * Kontrola zdrojů se dívá jen do **souborů formulářů** (třída dědí formulář,
 * nebo soubor pracuje s `TabBuilder` / `FormElement`) — `->html(` jinde je
 * něco jiného (`MailComposer`: tělo e-mailu).
 *
 * **Prvek `multilookup`** (#176 D2, D10): každá deklarace v JSONC formuláři
 * i v PHP formuláři (`TabBuilder::multiLookup()`) musí jít navázat na child
 * tabulku rodiče (`MultiLookupBinding`) a tabulka lookupu musí mít
 * registrovaný `TableLookup`. PHP formuláře se čtou ze zdroje: sloupec,
 * tabulka a `refColumn` proto musí být literál nebo konstanta třídy
 * a třída s voláním musí být (sama nebo potomkem) registrovaná ve `forms`
 * některého modulu — z registrace se bere tabulka rodiče.
 */
class FormContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /** Soubor formuláře: dědí třídu formuláře, nebo staví prvky přes TabBuilder / FormElement. */
    private const FORM_SOURCE = '/\bextends\s+\\\\?[\w\\\\]*Form(?:Base)?\b|\bTabBuilder\b|\bFormElement\b/';

    private const HTML_CALL = '/->\s*html\s*\(/';
    private const HTML_ELEMENT = '/new\s+FormElement\s*\(\s*(?:type\s*:\s*)?[\'"]html[\'"]/';

    /** @return array<string, string> cesta od kořene repozitáře → zdroj */
    private static function phpSources(): array
    {
        $root = (string) realpath(self::ROOT);
        $out = [];
        foreach ([$root . '/src', ...(glob($root . '/modules/*/*/src', GLOB_ONLYDIR) ?: [])] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $out[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($out);
        return $out;
    }

    private static function isFormSource(string $source): bool
    {
        return preg_match(self::FORM_SOURCE, $source) === 1;
    }

    public function testHtmlElementDoesNotExist(): void
    {
        $this->assertNotContains('html', FormElement::ALLOWED_TYPES, 'Formulář HTML se vzhledem neposílá (#150 D82)');
        $this->assertContains('content', FormElement::ALLOWED_TYPES);
        $this->assertFalse(method_exists(TabBuilder::class, 'html'), 'TabBuilder::html() zanikl — použij content() nebo component()');
        $this->assertFalse(property_exists(FormElement::class, 'content'), 'FormElement nenese HTML obsah');
    }

    public function testNoFormSourceUsesHtmlElement(): void
    {
        $hits = [];
        $forms = 0;
        foreach (self::phpSources() as $path => $source) {
            if (!self::isFormSource($source)) {
                continue;
            }
            $forms++;
            foreach (explode("\n", $source) as $index => $line) {
                if (preg_match(self::HTML_CALL, $line) === 1) {
                    $hits[] = $path . ':' . ($index + 1);
                }
            }
            if (preg_match(self::HTML_ELEMENT, $source) === 1) {
                $hits[] = $path . ': FormElement typu html';
            }
        }
        $this->assertGreaterThan(30, $forms, 'Kontrola našla zdroje formulářů');
        $this->assertSame([], $hits, 'Formulář HTML neposílá (#150 D82): místo html() prvek content() s bloky nebo component()');
    }

    /** Tělo e-mailu (`MailComposer`) není formulářový prvek — kontrola ho nesmí hlásit. */
    public function testMailBodyHtmlIsNotAFormSource(): void
    {
        $sources = self::phpSources();
        $this->assertArrayHasKey('src/Core/Mail/MailComposer.php', $sources);
        $composer = $sources['src/Core/Mail/MailComposer.php'];

        $this->assertSame(1, preg_match(self::HTML_CALL, $composer), 'MailComposer volá ->html() na e-mailu');
        $this->assertFalse(self::isFormSource($composer), 'MailComposer se nepočítá mezi zdroje formulářů');
    }

    public function testNoJsoncFormDeclaresHtmlElement(): void
    {
        $root = (string) realpath(self::ROOT);
        $files = glob($root . '/modules/*/*/forms/*.jsonc') ?: [];
        $this->assertNotEmpty($files);

        $hits = [];
        foreach ($files as $file) {
            if (preg_match('/"type"\s*:\s*"html"/', (string) file_get_contents($file)) === 1) {
                $hits[] = substr($file, strlen($root) + 1);
            }
        }
        $this->assertSame([], $hits, 'Definice formuláře nemá prvek html (#150 D82)');
    }

    // ── Prvek `multilookup` (#176) ──────────────────────────────────────────

    private static function fixtureModulesRoot(): string
    {
        return (string) realpath(self::ROOT . '/tests/Fixtures/modules');
    }

    private static function mainModulesRoot(): string
    {
        return (string) realpath(self::ROOT . '/modules');
    }

    /**
     * Deklarace prvků `multilookup` pod kořenem modulů a jejich porušení.
     *
     * @return array{declarations: list<string>, violations: list<string>}
     *     deklarace jako `rodič.klíč → child.odkaz [pořadí]`
     */
    private static function multiLookupReport(string $modulesRoot): array
    {
        $tables = [];
        foreach (glob($modulesRoot . '/*/*/tables/*.jsonc') ?: [] as $path) {
            $tables[basename($path, '.jsonc')] = TableDefinition::fromArray(JsoncParser::parseFile($path));
        }

        $formClasses = [];   // třída → seznam tabulek, pro které je registrovaná
        $lookupTables = [];
        foreach (glob($modulesRoot . '/*/*/module.jsonc') ?: [] as $path) {
            $module = JsoncParser::parseFile($path);
            foreach ($module['forms'] ?? [] as $registration) {
                $classes = array_filter([
                    $registration['class'] ?? null,
                    $registration['defaultClass'] ?? null,
                    ...array_values($registration['classes'] ?? []),
                ], 'is_string');
                foreach ($classes as $class) {
                    $formClasses[ltrim($class, '\\')][] = (string) $registration['table'];
                }
            }
            foreach ($module['lookups'] ?? [] as $registration) {
                $lookupTables[(string) ($registration['table'] ?? '')] = true;
            }
        }

        // [zdroj, tabulka rodiče, klíč, tabulka lookupu, refColumn]
        $found = [];
        $violations = [];

        foreach (glob($modulesRoot . '/*/*/forms/*.jsonc') ?: [] as $path) {
            $source = substr($path, strlen(dirname($modulesRoot, 1)) + 1);
            foreach (self::jsoncMultiLookups(JsoncParser::parseFile($path)) as $element) {
                $found[] = [
                    $source,
                    basename($path, '.jsonc'),
                    (string) ($element['column'] ?? ''),
                    (string) ($element['lookup']['table'] ?? ''),
                    isset($element['lookup']['refColumn']) ? (string) $element['lookup']['refColumn'] : null,
                ];
            }
        }

        foreach (glob($modulesRoot . '/*/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                if (!str_contains($code, 'multiLookup(')) {
                    continue;
                }
                $source = substr($file->getPathname(), strlen(dirname($modulesRoot, 1)) + 1);
                $class = self::sourceClass($code);
                $parentTables = [];
                foreach ($formClasses as $registered => $registeredTables) {
                    if ($class !== null && class_exists($registered) && is_a($registered, $class, true)) {
                        $parentTables = [...$parentTables, ...$registeredTables];
                    }
                }
                $parentTables = array_values(array_unique($parentTables));
                foreach (self::phpMultiLookupCalls($code) as $call) {
                    if ($call === null) {
                        $violations[] = "{$source}: multiLookup() musí mít sloupec, tabulku a refColumn literálem nebo konstantou třídy";
                        continue;
                    }
                    if ($parentTables === []) {
                        $violations[] = "{$source}: multiLookup('{$call[0]}') je ve třídě, která není registrovaná ve forms žádného modulu";
                        continue;
                    }
                    foreach ($parentTables as $parentTable) {
                        $found[] = [$source, $parentTable, ...$call];
                    }
                }
            }
        }

        $declarations = [];
        foreach ($found as [$source, $parentTable, $column, $lookupTable, $refColumn]) {
            $parentDef = $tables[$parentTable] ?? null;
            if ($parentDef === null) {
                $violations[] = "{$source}: tabulka '{$parentTable}' nemá definici";
                continue;
            }
            try {
                $binding = MultiLookupBinding::resolve($column, $lookupTable, $refColumn, $parentTable, $parentDef, $tables);
            } catch (\LogicException $e) {
                $violations[] = "{$source}: " . $e->getMessage();
                continue;
            }
            if (!isset($lookupTables[$lookupTable])) {
                $violations[] = "{$source}: multilookup '{$column}' — tabulka '{$lookupTable}' nemá registrovaný TableLookup";
            }
            $declarations[] = sprintf(
                '%s.%s → %s.%s [%s]',
                $parentTable, $column, $binding->childTable, $binding->refColumn, $binding->orderColumn ?? '-',
            );
        }

        $declarations = array_values(array_unique($declarations));
        sort($declarations);
        return ['declarations' => $declarations, 'violations' => $violations];
    }

    /**
     * @return list<array<string, mixed>> prvky `multilookup` kdekoli v definici
     */
    private static function jsoncMultiLookups(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $out = ($node['type'] ?? null) === 'multilookup' ? [$node] : [];
        foreach ($node as $child) {
            $out = [...$out, ...self::jsoncMultiLookups($child)];
        }
        return $out;
    }

    /** Plné jméno první třídy ve zdroji, nebo `null`. */
    private static function sourceClass(string $code): ?string
    {
        if (preg_match('/^\s*(?:abstract\s+|final\s+)*class\s+(\w+)/m', $code, $class) !== 1) {
            return null;
        }
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $ns) === 1 ? $ns[1] . '\\' : '';
        return $namespace . $class[1];
    }

    /**
     * Volání `multiLookup(` ve zdroji jako `[sloupec, tabulka, ?refColumn]`;
     * `null` = argument, který ze zdroje nejde přečíst (proměnná, výraz).
     * Definice metody (`function multiLookup(`) se nepočítá.
     *
     * @return list<array{string, string, ?string}|null>
     */
    private static function phpMultiLookupCalls(string $code): array
    {
        $calls = [];
        $offset = 0;
        while (($pos = strpos($code, 'multiLookup(', $offset)) !== false) {
            $offset = $pos + strlen('multiLookup(');
            if (preg_match('/function\s+$/', substr($code, max(0, $pos - 20), min(20, $pos))) === 1) {
                continue;
            }
            // Text argumentů až po párovou závorku.
            $depth = 1;
            $end = $offset;
            for ($length = strlen($code); $end < $length && $depth > 0; $end++) {
                $depth += match ($code[$end]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
            }
            $args = substr($code, $offset, $end - $offset - 1);

            $value = '(?:\'([^\']+)\'|([\w\\\\]+::\w+))';
            if (preg_match('/^\s*(?:column:\s*)?' . $value . '\s*,\s*(?:table:\s*)?' . $value . '/', $args, $m) !== 1) {
                $calls[] = null;
                continue;
            }
            $column = self::literalOrConstant($m[1], $m[2] ?? '', $code);
            $table = self::literalOrConstant($m[3] ?? '', $m[4] ?? '', $code);
            $refColumn = null;
            if (preg_match('/\brefColumn:\s*(.+?)\s*(?:,|$)/s', $args, $ref) === 1 && trim($ref[1]) !== 'null') {
                $refColumn = preg_match('/^' . $value . '$/', trim($ref[1]), $r) === 1
                    ? self::literalOrConstant($r[1], $r[2] ?? '', $code)
                    : null;
                if ($refColumn === null) {
                    $calls[] = null;
                    continue;
                }
            }
            $calls[] = $column === null || $table === null ? null : [$column, $table, $refColumn];
        }
        return $calls;
    }

    /** Literál, nebo hodnota konstanty třídy (`Třída::KONSTANTA`, i `self::` / `static::`). */
    private static function literalOrConstant(string $literal, string $constant, string $code): ?string
    {
        if ($literal !== '') {
            return $literal;
        }
        [$class, $name] = explode('::', $constant);
        if ($class === 'self' || $class === 'static') {
            $class = (string) self::sourceClass($code);
        } elseif (!str_contains($class, '\\')) {
            $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $ns) === 1 ? $ns[1] . '\\' : '';
            $class = preg_match('/^use\s+([\w\\\\]+\\\\' . preg_quote($class, '/') . ')\s*;/m', $code, $use) === 1
                ? $use[1]
                : $namespace . $class;
        }
        $fqn = ltrim($class, '\\') . '::' . $name;
        return defined($fqn) && is_string(constant($fqn)) ? constant($fqn) : null;
    }

    public function testEveryMultiLookupBindsToChildTable(): void
    {
        $report = self::multiLookupReport(self::mainModulesRoot());

        $this->assertSame([], $report['violations'], 'Prvek multilookup musí jít navázat na child tabulku rodiče (#176 D2)');
    }

    /** Kontrola deklarace prvku opravdu najde — v JSONC i v PHP formuláři fixturového modulu. */
    public function testMultiLookupScanFindsFixtureDeclarations(): void
    {
        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesRoot(), self::fixtureModulesRoot()]));
        try {
            $report = self::multiLookupReport(self::fixtureModulesRoot());
        } finally {
            ModuleClassLoader::register(new ModulePathResolver([self::mainModulesRoot()]));
        }

        $this->assertSame(
            ['test_multilookup_parents.targets → test_multilookup_links.target [order_pos]'],
            $report['declarations'],
            'JSONC i PHP formulář fixturového modulu dávají tutéž vazbu',
        );
        // Fixturová záložka z modulu s prvkem (záměrně mimo kontrakt) není
        // formulář žádné tabulky — kontrola ji musí ohlásit.
        $this->assertSame(
            ["modules/test/uiExtensions/src/MultiLookupFormTab.php: multiLookup('links') je ve třídě, která není registrovaná ve forms žádného modulu"],
            $report['violations'],
        );
    }

    public function testMultiLookupCallParser(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Shipard\Module\Test\MultiLookup;

        class SampleForm
        {
            public const KEY = 'places';

            public function multiLookup(string $column): void
            {
            }

            public function build($tab, $table): void
            {
                $tab->multiLookup('targets', table: TargetsLookup::TABLE, filter: ['kind' => strtolower('A')], refColumn: 'target');
                $tab->multiLookup(self::KEY, 'base_places_places', label: 'Places');
                $tab->multiLookup('dynamic', $table);
                $tab->multiLookup('bad_ref', 'base_places_places', refColumn: $table);
            }
        }
        PHP;

        ModuleClassLoader::register(new ModulePathResolver([self::mainModulesRoot(), self::fixtureModulesRoot()]));
        try {
            // Konstanta `self::KEY` se čte z načtené třídy — ukázková třída
            // neexistuje, takže se nevyhodnotí a volání je nečitelné.
            $calls = self::phpMultiLookupCalls($code);
        } finally {
            ModuleClassLoader::register(new ModulePathResolver([self::mainModulesRoot()]));
        }

        $this->assertSame(
            [
                ['targets', 'test_multilookup_targets', 'target'],
                null,
                null,
                null,
            ],
            $calls,
            'definice metody se nepočítá; proměnná a nenačtená konstanta jsou nečitelné',
        );
    }
}
