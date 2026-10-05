<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\Texts\PrintTextCompiler;
use Shipard\Core\Prints\Texts\PrintTextSlot;

/**
 * Document třída pro `core_prints_texts` — texty na tiscích (#90 D47).
 *
 * Zodpovědnosti:
 *   - povinná pole (název, umístění, text) a `valid_from ≤ valid_to`
 *   - text se zkompiluje v sandboxu uživatelských textů: syntaktická chyba
 *     nebo prvek mimo politiku se odmítne už při uložení, ne až při tisku
 *   - cílení: tisky existují a slot podporují; typ dokladu a číselná řada
 *     jen u tisků nad tabulkou, která je má (`PrintTextTargeting`)
 *   - JSON sloupce cílení: seznam → JSON, prázdný výběr → NULL
 */
class PrintTextDocument extends Document
{
    public const TABLE = 'core_prints_texts';

    /** Sloupce cílení uložené jako JSON pole. */
    private const LIST_COLUMNS = ['prints', 'doc_types', 'number_series'];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        $slot = PrintTextSlot::tryFrom((string) ($data['slot'] ?? ''));
        if ($slot === null) {
            $result->addError('slot', 'Vyber, kam se má text vložit', 'required');
        }

        $text = (string) ($data['text'] ?? '');
        if (trim($text) === '') {
            $result->addError('text', 'Text je povinný', 'required');
        } else {
            $error = PrintTextCompiler::check($text);
            if ($error !== null) {
                $result->addError('text', 'Text nejde použít: ' . $error, 'invalid_template');
            }
        }

        $from = self::dateValue($data['valid_from'] ?? null);
        $to   = self::dateValue($data['valid_to'] ?? null);
        if ($from !== null && $to !== null && $from > $to) {
            $result->addError('valid_to', 'Konec platnosti nesmí být před jejím začátkem', 'invalid_range');
        }

        $language = (string) ($data['language'] ?? '');
        if ($language !== '' && !in_array($language, PrintLanguageResolver::LANGUAGES, true)) {
            $result->addError('language', 'Tisky tento jazyk neumí', 'invalid');
        }

        $lists = [];
        foreach (self::LIST_COLUMNS as $column) {
            $lists[$column] = self::listValue($data[$column] ?? null);
            if ($lists[$column] === null) {
                $result->addError($column, 'Hodnota musí být seznam', 'invalid');
                $lists[$column] = [];
            }
        }

        $choices  = new PrintTextChoices($this->config);
        $printIds = array_map('strval', $lists['prints']);
        if ($slot !== null && $choices->knowsPrints()) {
            $this->validatePrints($result, $choices, $slot, $printIds);
            $this->validateTargeting($result, $choices, $slot, $printIds, $lists['doc_types'], $lists['number_series']);
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('language', $data) && (string) $data['language'] === '') {
            $data['language'] = null;
        }
        if (array_key_exists('order_pos', $data) && ($data['order_pos'] === null || $data['order_pos'] === '')) {
            $data['order_pos'] = 0;
        }

        // JSON sloupce nemají auto-serializaci — encode tady, prázdný výběr → NULL.
        foreach (self::LIST_COLUMNS as $column) {
            if (!array_key_exists($column, $data) || !is_array($data[$column])) {
                continue;
            }
            $values = array_values($data[$column]);
            if ($column === 'number_series') {
                $values = array_map('intval', $values);
            }
            $data[$column] = $values === []
                ? null
                : json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /** @param list<string> $printIds */
    private function validatePrints(
        ValidationResult $result,
        PrintTextChoices $choices,
        PrintTextSlot $slot,
        array $printIds,
    ): void {
        $withSlot = $choices->prints($slot->value);
        $all      = $choices->prints();
        foreach ($printIds as $printId) {
            if (isset($withSlot[$printId])) {
                continue;
            }
            $result->addError(
                'prints',
                isset($all[$printId])
                    ? "Tisk „{$all[$printId]['name']}“ toto umístění textu nepodporuje"
                    : "Neznámý tisk „{$printId}“",
                'invalid',
            );
        }
    }

    /**
     * @param list<string> $printIds
     * @param list<mixed> $docTypes
     * @param list<mixed> $series
     */
    private function validateTargeting(
        ValidationResult $result,
        PrintTextChoices $choices,
        PrintTextSlot $slot,
        array $printIds,
        array $docTypes,
        array $series,
    ): void {
        if ($docTypes === [] && $series === []) {
            return;
        }

        $targeting = $choices->targeting($printIds, $slot->value);
        if ($targeting === null) {
            // Text omezený na typ nebo řadu by u těchto tisků nikdy neplatil.
            foreach (['doc_types' => $docTypes, 'number_series' => $series] as $column => $values) {
                if ($values !== []) {
                    $result->addError(
                        $column,
                        'Typ dokladu a číselnou řadu lze omezit jen u tisků dokladů',
                        'not_applicable',
                    );
                }
            }
            return;
        }

        $knownTypes = $choices->docTypes($targeting, $printIds, $slot->value);
        foreach ($docTypes as $docType) {
            if (!is_string($docType) || !isset($knownTypes[$docType])) {
                $result->addError(
                    'doc_types',
                    'Typ dokladu „' . (is_scalar($docType) ? $docType : '?') . '“ vybrané tisky netisknou',
                    'invalid',
                );
            }
        }

        if ($series === [] || $this->db === null) {
            return;
        }
        $seriesIds = [];
        foreach ($series as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                $result->addError('number_series', 'Číselná řada musí být id záznamu', 'invalid');
                return;
            }
            $seriesIds[] = (int) $id;
        }
        $existing = [];
        foreach ($this->db->fetchAll('SELECT [id] FROM %n WHERE [id] IN %in', $targeting->seriesTable, $seriesIds) as $row) {
            $existing[(int) $row['id']] = true;
        }
        foreach ($seriesIds as $id) {
            if (!isset($existing[$id])) {
                $result->addError('number_series', "Číselná řada {$id} neexistuje", 'invalid');
            }
        }
    }

    /**
     * Seznam z payloadu (pole) nebo z databáze (JSON řetězec); prázdná
     * hodnota = prázdný seznam, cokoli jiného null.
     *
     * @return list<mixed>|null
     */
    private static function listValue(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    /** Datum jako `YYYY-MM-DD` (formulář posílá řetězec, databáze objekt), nebo null. */
    private static function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
