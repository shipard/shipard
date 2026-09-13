<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Porovnání řádků kontrolního / souhrnného hlášení: původní podané XML
 * proti XML, které writer vyrobil nad snapshotem importovaného podání
 * (#55 D33). Řádky hlášení se při importu nepřepisují — rozdíly jsou jen
 * historický záznam ve zprávách podání.
 *
 * Na rozdíl od `EpoXmlDiff` (multimnožina řádků podle obsahu) se řádky
 * párují **podle klíče** z mapování: sekce + ev. číslo + DIČ (u EU
 * i stát) + kód předmětu plnění, u souhrnného hlášení stát + DIČ + kód
 * plnění. Sekce bez klíče (souhrnné A.5 / B.3) mají jediný řádek. Díky
 * tomu je z výsledku vidět, **který** řádek se liší a v jakém poli, ne jen
 * „jeden chybí a jeden přebývá".
 *
 * Hodnoty se normalizují jako v `EpoXmlDiff` (nula ≡ chybějící atribut).
 */
final class EpoXmlLineComparer
{
    public const KIND_VALUE   = 'value';
    public const KIND_MISSING = 'missing';
    public const KIND_EXTRA   = 'extra';

    public function __construct(private readonly VatXmlMapping $mapping) {}

    /**
     * @return list<array{section: string, key: string, field: string, composed: ?string, filed: ?string, kind: string}>
     *         `missing` = řádek jen v podaném XML, `extra` = jen v sestaveném;
     *         u nich nesou `filed` / `composed` popis celého řádku
     */
    public function compare(EpoXmlDocument $filed, EpoXmlDocument $composed): array
    {
        $differences = [];
        foreach ($this->lineDefinitions() as $section => $definition) {
            $filedRows    = $this->index($filed->sentences($definition['veta']), $definition['key']);
            $composedRows = $this->index($composed->sentences($definition['veta']), $definition['key']);

            foreach (array_unique([...array_keys($filedRows), ...array_keys($composedRows)]) as $key) {
                $f = $filedRows[$key] ?? null;
                $c = $composedRows[$key] ?? null;
                if ($f === null) {
                    $differences[] = [
                        'section' => $section, 'key' => $key, 'field' => '',
                        'composed' => self::describe($c ?? []), 'filed' => null, 'kind' => self::KIND_EXTRA,
                    ];
                    continue;
                }
                if ($c === null) {
                    $differences[] = [
                        'section' => $section, 'key' => $key, 'field' => '',
                        'composed' => null, 'filed' => self::describe($f), 'kind' => self::KIND_MISSING,
                    ];
                    continue;
                }
                foreach ($definition['fields'] as $attribute) {
                    $filedValue    = $f[$attribute] ?? '0';
                    $composedValue = $c[$attribute] ?? '0';
                    if ($filedValue === $composedValue) {
                        continue;
                    }
                    $differences[] = [
                        'section' => $section, 'key' => $key, 'field' => $attribute,
                        'composed' => $composedValue, 'filed' => $filedValue, 'kind' => self::KIND_VALUE,
                    ];
                }
            }
        }
        return $differences;
    }

    /**
     * Sekce → věta, klíčové atributy, porovnávaná pole — z mapování KH
     * (`sections`) nebo SH (`row`).
     *
     * @return array<string, array{veta: string, key: list<string>, fields: list<string>}>
     */
    private function lineDefinitions(): array
    {
        $definitions = [];
        foreach ($this->mapping->sections() as $section => $definition) {
            $key = [];
            if (isset($definition['vatId'])) {
                if (isset($definition['vatId']['countryAttr'])) {
                    $key[] = (string) $definition['vatId']['countryAttr'];
                }
                $key[] = (string) $definition['vatId']['attr'];
            }
            if (isset($definition['evidNumber'])) {
                $key[] = (string) $definition['evidNumber'];
            }
            if (isset($definition['kodPredPl'])) {
                $key[] = (string) $definition['kodPredPl'];
            }
            $fields = array_map(strval(...), array_values($definition['bands'] ?? []));
            if (isset($definition['date'])) {
                $fields[] = (string) $definition['date'];
            }
            $definitions[(string) $section] = ['veta' => (string) $definition['veta'], 'key' => $key, 'fields' => $fields];
        }

        $row = $this->mapping->row();
        if ($row !== null) {
            $definitions['R'] = [
                'veta'   => (string) $row['veta'],
                'key'    => [(string) $row['vatId']['countryAttr'], (string) $row['vatId']['attr'], (string) $row['code']],
                'fields' => [(string) $row['count'], (string) $row['value']],
            ];
        }
        return $definitions;
    }

    /**
     * Řádky věty podle klíče (hodnoty klíče surové, ostatní normalizované).
     * Duplicitní klíč (dva doklady se stejným ev. číslem) dostane pořadí,
     * aby se neztratil.
     *
     * @param list<array<string, string>> $sentences
     * @param list<string> $keyAttributes
     * @return array<string, array<string, string>>
     */
    private function index(array $sentences, array $keyAttributes): array
    {
        $rows = [];
        foreach ($sentences as $attributes) {
            $normalized = [];
            foreach ($attributes as $attribute => $value) {
                $normalized[(string) $attribute] = EpoXmlDocument::normalizeValue($value);
            }
            $parts = [];
            foreach ($keyAttributes as $attribute) {
                $parts[] = trim((string) ($attributes[$attribute] ?? ''));
            }
            $key = implode('|', $parts);

            $candidate = $key;
            for ($n = 2; isset($rows[$candidate]); $n++) {
                $candidate = "{$key}#{$n}";
            }
            $rows[$candidate] = $normalized;
        }
        return $rows;
    }

    /** @param array<string, string> $row */
    private static function describe(array $row): string
    {
        $parts = [];
        foreach ($row as $attribute => $value) {
            if ($value === '0') {
                continue;
            }
            $parts[] = "{$attribute}=\"{$value}\"";
        }
        return implode(' ', $parts);
    }
}
