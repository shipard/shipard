<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

use Shipard\Core\Utils\JsoncParser;

/**
 * Papírová projekce canonicalu `shpd.docs.document.v1`
 * (`tests/corpus/projection.jsonc`, docs/test-corpus.md § 8) — tentýž
 * soubor čte generátor korpusu (`invoicegen/expected.py`), strážce
 * `CorpusExpectedTest` a porovnávač (#103 D27).
 *
 * `nullableNodes` = uzly, které smí být `null` jako celek;
 * `informativeNodes` = uzly (i s potomky), které evaluace AI porovnává
 * a vykazuje zvlášť, ale nezapočítává do shody polí ani čistých dokladů
 * (#103 D114, {@see isInformativeNode()}).
 *
 * Čtení (dispatch jen podle typu JSON, pořadí klíčů je významné):

 *   objekt bez `$ref`     strom klíčů — očekávání má přesně tyto klíče
 *   `{ "$ref": "Party" }` odkaz do `$defs`
 *   `[ uzel ]`            pole; každý prvek proti uzlu
 *   `"jméno"`             list; tvar hodnoty ze `shapes`
 *   `null`                list bez tvaru
 *
 * {@see project()} ořeže dokument na klíče projekce v jejím pořadí
 * a chybějící klíče doplní jako `null` — paralela `apply_projection`
 * v Pythonu, jen bez hlášení problémů (porovnávač je vykáže jako rozdíl).
 */
final class Projection
{
    public const FORMAT = 'shpd.corpus.projection';
    public const VERSION = '1';

    private const CHECKS = ['number', 'amount', 'index', 'tag'];

    /**
     * @param array<string, mixed>   $document
     * @param array<string, mixed>   $defs
     * @param array<string, array<string, mixed>> $shapes
     * @param list<string>           $nullableNodes
     * @param list<string>           $informativeNodes
     */
    private function __construct(
        private readonly array $document,
        private readonly array $defs,
        private readonly array $shapes,
        private readonly array $nullableNodes,
        private readonly array $informativeNodes = [],
    ) {}


    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new ProjectionException("Projekce {$path} neexistuje.");
        }
        return self::fromArray(JsoncParser::parseFile($path));
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        if (($raw['format'] ?? null) !== self::FORMAT || (string) ($raw['formatVersion'] ?? '') !== self::VERSION) {
            throw new ProjectionException('Projekce není ' . self::FORMAT . ' v' . self::VERSION . '.');
        }
        $document = $raw['document'] ?? null;
        $defs = $raw['$defs'] ?? [];
        $shapes = $raw['shapes'] ?? null;
        if (!is_array($document) || !is_array($defs) || !is_array($shapes)) {
            throw new ProjectionException('Projekce musí mít document, $defs a shapes.');
        }
        foreach ($shapes as $name => $shape) {
            if (!is_array($shape) || count($shape) !== 1) {
                throw new ProjectionException("Tvar {$name}: právě jeden klíč regex|const|check|byCountry.");
            }
            $kind = (string) array_key_first($shape);
            switch ($kind) {
                case 'regex':
                    if (!is_string($shape['regex']) || str_contains($shape['regex'], '~')) {
                        throw new ProjectionException("Tvar {$name}: regex musí být řetězec bez '~'.");
                    }
                    if (@preg_match('~' . $shape['regex'] . '~uD', '') === false) {
                        throw new ProjectionException("Tvar {$name}: regex nejde zkompilovat.");
                    }
                    break;
                case 'check':
                    if (!in_array($shape['check'], self::CHECKS, true)) {
                        throw new ProjectionException("Tvar {$name}: neznámá kontrola {$shape['check']}.");
                    }
                    break;
                case 'byCountry':
                    $table = $shape['byCountry'];
                    if (!is_array($table) || !isset($table['*'])) {
                        throw new ProjectionException("Tvar {$name}: byCountry bez '*'.");
                    }
                    foreach ($table as $target) {
                        if (!isset($shapes[$target]) || isset($shapes[$target]['byCountry'])) {
                            throw new ProjectionException("Tvar {$name}: byCountry odkazuje na {$target}.");
                        }
                    }
                    break;
                case 'const':
                    break;
                default:
                    throw new ProjectionException("Tvar {$name}: neznámý druh {$kind}.");
            }
        }
        $nullable = $raw['nullableNodes'] ?? [];
        if (!is_array($nullable)) {
            throw new ProjectionException('nullableNodes musí být pole.');
        }
        $informative = $raw['informativeNodes'] ?? [];
        if (!is_array($informative)) {
            throw new ProjectionException('informativeNodes musí být pole.');
        }
        $self = new self($document, $defs, $shapes, array_values(array_map('strval', $nullable)), array_values(array_map('strval', $informative)));
        if ($self->informativeNodes !== []) {
            $nodePaths = $self->nodePaths();
            foreach ($self->informativeNodes as $node) {
                if (!in_array($node, $nodePaths, true)) {
                    throw new ProjectionException("informativeNodes: {$node} není uzel projekce.");
                }
            }
        }
        return $self;

    }


    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }

    /** @return array<string, array<string, mixed>> */
    public function shapes(): array
    {
        return $this->shapes;
    }

    /** @return list<string> */
    public function nullableNodes(): array
    {
        return $this->nullableNodes;
    }

    /** Uzel projekce (cesta s `[]` místo indexů) smí být `null` jako celek. */
    public function isNullableNode(string $nodePath): bool
    {
        return in_array($nodePath, $this->nullableNodes, true);
    }

    /** @return list<string> uzly informativní v evaluaci (#103 D114) */
    public function informativeNodes(): array
    {
        return $this->informativeNodes;
    }

    /**
     * Uzel projekce (cesta s `[]` místo indexů) je v evaluaci informativní
     * — on sám nebo některý jeho předek je v `informativeNodes` (#103 D114):
     * porovnává se a vykazuje zvlášť, do shody polí ani čistých dokladů nejde.
     */
    public function isInformativeNode(string $nodePath): bool
    {
        return CanonicalComparator::isIgnored($nodePath, $this->informativeNodes);
    }


    /** Cesta hodnoty → cesta uzlu projekce (`rows[3].vat.pct` → `rows[].vat.pct`). */
    public static function nodePath(string $path): string
    {
        return (string) preg_replace('/\[[0-9]+\]/', '[]', $path);
    }

    /**
     * Cesty všech uzlů projekce v jejím pořadí — objekty (`supplier.address`),
     * pole (`rows[]`) i listy (`rows[].item.ean`), `$ref` rozpuštěné. Jediný
     * zdroj cest pro strážce nad projekcí (`IsdocFieldCoverage`, pokrytí
     * ukázky promptu `PromptProjectionCoverageTest`) — žádný druhý parser.
     *
     * @return list<string>
     */
    public function nodePaths(): array
    {
        $out = [];
        $this->collectNodePaths($this->document, '', $out);
        return $out;
    }

    /** @param list<string> $out */
    private function collectNodePaths(mixed $spec, string $path, array &$out): void
    {
        $spec = $this->resolve($spec);
        if ($path !== '') {
            $out[] = $path;
        }
        if (is_array($spec) && array_is_list($spec)) {
            if (array_key_exists(0, $spec)) {
                $this->collectNodePaths($spec[0], $path . '[]', $out);
            }
            return;
        }
        if (is_array($spec)) {
            foreach ($spec as $key => $sub) {
                $this->collectNodePaths($sub, $path === '' ? (string) $key : "{$path}.{$key}", $out);
            }
        }
    }

    /** Rozpustí `$ref`; cokoli jiného vrací beze změny. */
    public function resolve(mixed $spec): mixed
    {
        if (is_array($spec) && array_key_exists('$ref', $spec)) {
            if (count($spec) !== 1) {
                throw new ProjectionException("\$ref {$spec['$ref']}: objekt smí mít jen \$ref.");
            }
            $name = (string) $spec['$ref'];
            if (!isset($this->defs[$name])) {
                throw new ProjectionException("\$ref {$name}: v \$defs není.");
            }
            return $this->defs[$name];
        }
        return $spec;
    }

    /**
     * Konkrétní tvar listu: `byCountry` se vybere podle klíče `country`
     * v témž objektu (`$parent`), ostatní beze změny.
     *
     * @param array<string, mixed>|null $parent
     * @return array{0: string, 1: array<string, mixed>} [jméno, tvar]
     */
    public function shapeOf(string $name, ?array $parent): array
    {
        $shape = $this->shapes[$name] ?? throw new ProjectionException("Tvar {$name} v projekci není.");
        if (isset($shape['byCountry'])) {
            $country = $parent['country'] ?? null;
            $country = is_string($country) ? strtolower(trim($country)) : '*';
            $name = (string) ($shape['byCountry'][$country] ?? $shape['byCountry']['*']);
            $shape = $this->shapes[$name];
        }
        return [$name, $shape];
    }

    /**
     * Ořez na projekci: klíče projekce v jejím pořadí, chybějící klíč
     * i `null` dávají `null` (chybějící objekt mimo `nullableNodes` strom
     * listů `null` jako v Pythonu), klíče mimo projekci se zahodí. Hodnota
     * jiného typu, než uzel čeká (skalár místo objektu), zůstává — porovnávač
     * ji vykáže jako rozdíl.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function project(array $doc): array
    {
        /** @var array<string, mixed> $out */
        $out = $this->projectNode($this->document, $doc, '');
        return $out;
    }

    private function projectNode(mixed $spec, mixed $value, string $path): mixed
    {
        $spec = $this->resolve($spec);
        if (is_array($spec) && array_is_list($spec)) {
            if ($value === null) {
                return null;
            }
            if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
                return $value;
            }
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = $this->projectNode($spec[0], $item, "{$path}[{$i}]");
            }
            return $out;
        }
        if (is_array($spec)) {
            if ($value === null) {
                return $this->isNullableNode(self::nodePath($path)) ? null : $this->nullTree($spec);
            }
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                return $value;
            }
            $out = [];
            foreach ($spec as $key => $sub) {
                $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
                $out[$key] = $this->projectNode($sub, $value[$key] ?? null, $childPath);
            }
            return $out;
        }
        return $value;
    }

    /** Objekt projekce se všemi listy `null` (pole uvnitř jako `null`). */
    private function nullTree(mixed $spec): mixed
    {
        $spec = $this->resolve($spec);
        if (is_array($spec) && !array_is_list($spec)) {
            $out = [];
            foreach ($spec as $key => $sub) {
                $out[$key] = $this->nullTree($sub);
            }
            return $out;
        }
        return null;
    }

    /**
     * Projde projektovaný strom ({@see project()}) v pořadí projekce a každý
     * list nahradí výsledkem `$leaf(?string $tvar, mixed $hodnota,
     * ?array $rodič, string $cesta)`. Hodnoty, které tvarem uzlu neprojdou
     * (skalár místo objektu), nechá být.
     *
     * @param array<string, mixed> $projected
     * @param callable(?string, mixed, ?array<string, mixed>, string): mixed $leaf
     * @return array<string, mixed>
     */
    public function map(array $projected, callable $leaf): array
    {
        /** @var array<string, mixed> $out */
        $out = $this->mapNode($this->document, $projected, '', null, $leaf);
        return $out;
    }

    /** @param array<string, mixed>|null $parent */
    private function mapNode(mixed $spec, mixed $value, string $path, ?array $parent, callable $leaf): mixed
    {
        $spec = $this->resolve($spec);
        if (is_array($spec) && array_is_list($spec)) {
            if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
                return $value;
            }
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = $this->mapNode($spec[0], $item, "{$path}[{$i}]", null, $leaf);
            }
            return $out;
        }
        if (is_array($spec)) {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                return $value;
            }
            $out = [];
            foreach ($spec as $key => $sub) {
                $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
                $out[$key] = $this->mapNode($sub, $value[$key] ?? null, $childPath, $value, $leaf);
            }
            return $out;
        }
        return $leaf(is_string($spec) ? $spec : null, $value, $parent, $path);
    }
}
