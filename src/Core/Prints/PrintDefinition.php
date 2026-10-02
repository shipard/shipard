<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Render\PdfOptions;

/**
 * Deklarace tisku z JSONC (#90 D2, D4) — tisk nad jedním záznamem tabulky.
 * Určuje, pro které záznamy je tisk dostupný (`table`, volitelný `filter`,
 * povinné `docStates`), kdo skládá data (`builder`) a čím se kreslí
 * (`template`, `catalogs`, `paper`). Volitelné `watermarks` určí vodoznak
 * podle stavu záznamu (D23) — stornovaný doklad jde vytisknout se „STORNO“.
 *
 * Vstupní pole je už lokalizované (`ConfigLocalizer` vyřešil `name:cs`
 * varianty před voláním `fromArray()` — vzor `ReportDefinition`).
 */
final class PrintDefinition
{
    /** Tisk ven z firmy (lze odeslat, musí být neměnný) vs. interní. */
    public const AUDIENCES = ['external', 'internal'];

    /** Strany okraje stránky v `paper.margins`. */
    public const MARGIN_SIDES = ['top', 'right', 'bottom', 'left'];

    /** Cesta šablony / katalogu: `@<modul>/<adresář>[/<adresář>…]`. */
    private const TEMPLATE_PATH = '#^@[a-z][a-z0-9]*\.[a-z][a-zA-Z0-9]*(/[A-Za-z0-9_-]+)+$#';

    /**
     * @param array<string, list<int|string>> $filter Sloupec → povolené hodnoty;
     *        prázdné = tisk platí pro všechny záznamy tabulky.
     * @param list<int> $docStates Stavy záznamu, ve kterých je tisk dostupný.
     * @param list<string> $catalogs Sdílené adresáře tisku (`@<modul>/<adresář>`)
     *        — typicky layout. Jejich katalog překladů se slévá před katalogem
     *        šablony; renderer z nich bere i assety a výchozí záhlaví a zápatí.
     * @param array<string, string> $margins Okraje stránky (CSS délky) podle
     *        strany; chybějící strana = výchozí okraj render profilu.
     * @param array<int, string> $watermarks Stav záznamu → klíč katalogu
     *        s textem vodoznaku. Layout tisku ho musí vykreslit
     *        (`meta.watermark`).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $table,
        public readonly array $filter,
        public readonly array $docStates,
        public readonly string $audience,
        public readonly string $builderClass,
        public readonly string $template,
        public readonly array $catalogs,
        public readonly string $paperFormat,
        public readonly string $orientation,
        public readonly int $order,
        public readonly string $moduleId,
        public readonly array $margins = [],
        public readonly array $watermarks = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $moduleId): self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z][a-zA-Z0-9.]*$/', $id)) {
            throw new \InvalidArgumentException(
                "Module '{$moduleId}': print declaration missing or invalid 'id'",
            );
        }

        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException("Print '{$id}': missing 'name'");
        }

        $table = $data['table'] ?? null;
        if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Print '{$id}': missing or invalid 'table'");
        }

        $filter = [];
        $rawFilter = $data['filter'] ?? [];
        if (!is_array($rawFilter)) {
            throw new \InvalidArgumentException("Print '{$id}': 'filter' must be an object");
        }
        foreach ($rawFilter as $column => $values) {
            if (!is_string($column) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $column)) {
                throw new \InvalidArgumentException("Print '{$id}': invalid filter column");
            }
            if (!is_array($values) || $values === []) {
                throw new \InvalidArgumentException(
                    "Print '{$id}': filter '{$column}' must be a non-empty array of values",
                );
            }
            foreach ($values as $value) {
                if (!is_int($value) && !is_string($value)) {
                    throw new \InvalidArgumentException(
                        "Print '{$id}': filter '{$column}' values must be strings or integers",
                    );
                }
            }
            $filter[$column] = array_values($values);
        }

        $docStates = $data['docStates'] ?? null;
        if (!is_array($docStates) || $docStates === []) {
            throw new \InvalidArgumentException("Print '{$id}': 'docStates' must be a non-empty array of integers");
        }
        foreach ($docStates as $state) {
            if (!is_int($state)) {
                throw new \InvalidArgumentException(
                    "Print '{$id}': 'docStates' must be a non-empty array of integers",
                );
            }
        }

        $watermarks = [];
        $rawWatermarks = $data['watermarks'] ?? [];
        if (!is_array($rawWatermarks)) {
            throw new \InvalidArgumentException("Print '{$id}': 'watermarks' must be an object");
        }
        foreach ($rawWatermarks as $state => $key) {
            // Klíče JSON objektu jsou řetězce; PHP z číselných dělá int.
            if ((!is_int($state) && !ctype_digit((string) $state)) || !in_array((int) $state, $docStates, true)) {
                throw new \InvalidArgumentException(
                    "Print '{$id}': 'watermarks' keys must be states listed in 'docStates'",
                );
            }
            if (!is_string($key) || trim($key) === '') {
                throw new \InvalidArgumentException(
                    "Print '{$id}': 'watermarks' values must be non-empty catalog keys",
                );
            }
            $watermarks[(int) $state] = $key;
        }

        $audience = $data['audience'] ?? 'external';
        if (!is_string($audience) || !in_array($audience, self::AUDIENCES, true)) {
            throw new \InvalidArgumentException(
                "Print '{$id}': 'audience' must be one of " . implode('|', self::AUDIENCES),
            );
        }

        $builder = $data['builder'] ?? null;
        if (!is_string($builder) || $builder === '') {
            throw new \InvalidArgumentException("Print '{$id}': missing 'builder' class");
        }

        $template = $data['template'] ?? null;
        if (!is_string($template) || !preg_match(self::TEMPLATE_PATH, $template)) {
            throw new \InvalidArgumentException(
                "Print '{$id}': 'template' must be a path like '@<module>/<dir>'",
            );
        }

        $catalogs = $data['catalogs'] ?? [];
        if (!is_array($catalogs)) {
            throw new \InvalidArgumentException("Print '{$id}': 'catalogs' must be an array");
        }
        foreach ($catalogs as $catalog) {
            if (!is_string($catalog) || !preg_match(self::TEMPLATE_PATH, $catalog)) {
                throw new \InvalidArgumentException(
                    "Print '{$id}': 'catalogs' entries must be paths like '@<module>/<dir>'",
                );
            }
        }

        $paper = $data['paper'] ?? [];
        if (!is_array($paper)) {
            throw new \InvalidArgumentException("Print '{$id}': 'paper' must be an object");
        }
        $paperFormat = $paper['format'] ?? 'A4';
        if (!is_string($paperFormat) || !in_array($paperFormat, PdfOptions::PAPER_FORMATS, true)) {
            throw new \InvalidArgumentException(
                "Print '{$id}': paper 'format' must be one of " . implode('|', PdfOptions::PAPER_FORMATS),
            );
        }
        $orientation = $paper['orientation'] ?? 'portrait';
        if (!is_string($orientation) || !in_array($orientation, PdfOptions::ORIENTATIONS, true)) {
            throw new \InvalidArgumentException(
                "Print '{$id}': paper 'orientation' must be one of " . implode('|', PdfOptions::ORIENTATIONS),
            );
        }

        $margins = [];
        $rawMargins = $paper['margins'] ?? [];
        if (!is_array($rawMargins)) {
            throw new \InvalidArgumentException("Print '{$id}': paper 'margins' must be an object");
        }
        foreach ($rawMargins as $side => $length) {
            if (!in_array($side, self::MARGIN_SIDES, true)
                || !is_string($length)
                || !preg_match('/^\d+(\.\d+)?(mm|cm|in|pt|px)$/', $length)
            ) {
                throw new \InvalidArgumentException(
                    "Print '{$id}': paper 'margins' must map " . implode('|', self::MARGIN_SIDES)
                    . " to CSS lengths like '2.5cm'",
                );
            }
            $margins[$side] = $length;
        }

        $order = $data['order'] ?? 1000;
        if (!is_int($order)) {
            throw new \InvalidArgumentException("Print '{$id}': 'order' must be an integer");
        }

        return new self(
            id: $id,
            name: $name,
            table: $table,
            filter: $filter,
            docStates: array_values($docStates),
            audience: $audience,
            builderClass: $builder,
            template: $template,
            catalogs: array_values($catalogs),
            paperFormat: $paperFormat,
            orientation: $orientation,
            order: $order,
            moduleId: $moduleId,
            margins: $margins,
            watermarks: $watermarks,
        );
    }

    /**
     * Je tisk dostupný pro záznam? Sedí `filter` (typ záznamu) i `docStates`.
     * Tabulku neporovnává — tu volající zná z kontextu.
     *
     * @param array<string, mixed> $record
     */
    public function matches(array $record): bool
    {
        return $this->matchesFilter($record) && $this->matchesDocState($record);
    }

    /** @param array<string, mixed> $record */
    public function matchesFilter(array $record): bool
    {
        foreach ($this->filter as $column => $allowed) {
            if (!array_key_exists($column, $record)) {
                return false;
            }
            // Volné porovnání přes řetězec: DB vrací enumInt jako int, JSONC
            // deklarace může nést číslo i text.
            $value = (string) $record[$column];
            $hit = false;
            foreach ($allowed as $candidate) {
                if ((string) $candidate === $value) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $record */
    public function matchesDocState(array $record): bool
    {
        if (!isset($record['docState'])) {
            return false;
        }
        return in_array((int) $record['docState'], $this->docStates, true);
    }
}
