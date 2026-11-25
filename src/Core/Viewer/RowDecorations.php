<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Module\ModuleUiExtensions;

/**
 * Štítky řádku seznamu z modulů (`rowDecorators`, #172 D18) — přidání spanů
 * na konec pole `t2` vykreslených řádků (layout `list`). Každý dekorátor
 * se volá **jednou pro celou stránku řádků**, v pořadí registru.
 *
 * Výsledek dekorátoru se kontroluje: mapa id řádku → seznam spanů
 * `{text, class?, badge?}`. Porušení kontraktu je selhání poskytovatele
 * jako výjimka — zaloguje se a seznam se vykreslí bez něj
 * (`ModuleUiExtensions::call()`).
 *
 * `t2` řádku smí být řetězec, objekt `{text, …}` nebo pole spanů
 * (docs/frontend.md → Formát řádku); před přidáním se převede na pole
 * a pořadí původních spanů zůstává. Řádek, kterému žádný dekorátor nic
 * nepřidal, se nemění.
 */
final class RowDecorations
{
    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $rawRows řádky stránky ze `selectRows()`
     * @param list<array<string, mixed>> $rows tytéž řádky po `renderRow()`, ve stejném pořadí
     * @return list<array<string, mixed>>
     */
    public static function apply(
        ModuleUiExtensions $extensions,
        ModuleExtensionContext $context,
        array $rawRows,
        array $rows,
    ): array {
        $registrations = $extensions->for(ModuleUiExtensions::ROW_DECORATORS, $context->table);
        if ($registrations === [] || $rows === []) {
            return $rows;
        }

        foreach ($registrations as $extension) {
            $spansById = $extensions->call(
                $extension,
                static fn (RowDecorator $provider): array
                    => self::checked($provider->decorate($context->table, $rawRows, $context)),
            );
            if ($spansById === null || $spansById === []) {
                continue;
            }

            foreach ($rows as $index => $row) {
                $id = $rawRows[$index]['id'] ?? $row['id'] ?? null;
                if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                    continue;
                }
                $spans = $spansById[(int) $id] ?? [];
                if ($spans === []) {
                    continue;
                }
                $rows[$index]['t2'] = [...self::spans($rows[$index]['t2'] ?? null), ...$spans];
            }
        }

        return $rows;
    }

    /**
     * Pole `t2` jako seznam spanů — stejné čtení jako frontend
     * (`viewerSpans.js`): řetězec = jeden span, objekt s `text` = jeden
     * span, pole = spany; cokoli jiného = žádný.
     *
     * @return list<mixed>
     */
    private static function spans(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [['text' => $value]];
        }
        if (!is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            return $value;
        }
        return isset($value['text']) ? [$value] : [];
    }

    /**
     * @param array<mixed> $result
     * @return array<int, list<array{text: string, class?: string, badge?: string}>>
     */
    private static function checked(array $result): array
    {
        $checked = [];
        foreach ($result as $id => $spans) {
            if (!is_int($id)) {
                throw new \UnexpectedValueException("decorate() must return a map keyed by row id, got key '{$id}'");
            }
            if (!is_array($spans) || !array_is_list($spans)) {
                throw new \UnexpectedValueException("decorate()[{$id}] must be a list of spans");
            }
            foreach ($spans as $idx => $span) {
                $where = "decorate()[{$id}][{$idx}]";
                if (!is_array($span)) {
                    throw new \UnexpectedValueException("{$where} must be an array");
                }
                $text = $span['text'] ?? null;
                if (!is_string($text) || $text === '') {
                    throw new \UnexpectedValueException("{$where}.text must be a non-empty string");
                }
                $clean = ['text' => $text];
                foreach (['class', 'badge'] as $key) {
                    if (!array_key_exists($key, $span) || $span[$key] === null) {
                        continue;
                    }
                    if (!is_string($span[$key]) || $span[$key] === '') {
                        throw new \UnexpectedValueException("{$where}.{$key} must be a non-empty string");
                    }
                    $clean[$key] = $span[$key];
                }
                $unknown = array_diff(array_keys($span), ['text', 'class', 'badge']);
                if ($unknown !== []) {
                    throw new \UnexpectedValueException("{$where} has unknown key '" . reset($unknown) . "'");
                }
                $checked[$id][] = $clean;
            }
        }
        return $checked;
    }
}
