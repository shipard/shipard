<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Obecná validace vstupu akce záznamu podle polí dialogu z `describe()`
 * (#172 D13, D14) — čistá funkce, bez HTTP a bez databáze:
 *
 *  - pole, které dialog nedeklaruje (i jakákoli hodnota u akce bez dialogu
 *    a hodnota pro `notice`), vstup odmítá celé — nic se tiše nezahazuje;
 *  - povinnost, typ (číslo a jeho meze, datum, datum a čas v místním čase
 *    serveru, checkbox jako `bool`, `select` jen z nabídky), soubor
 *    přítomný a do `maxBytes`;
 *  - hodnoty převádí podle typu pole (viz {@see RecordActionInput}).
 *
 * Chyba pole nese `code` (překládá frontend) a anglický text. Hodnoty se
 * do chyb nikdy nepíšou — heslo ani jméno souboru se tak nedostane do
 * odpovědi ani do logu.
 */
final class RecordActionInputValidator
{
    public const string REQUIRED = 'required';
    public const string INVALID_VALUE = 'invalid_value';
    public const string INVALID_NUMBER = 'invalid_number';
    public const string OUT_OF_RANGE = 'out_of_range';
    public const string INVALID_DATE = 'invalid_date';
    public const string NOT_IN_OPTIONS = 'not_in_options';
    public const string FILE_TOO_LARGE = 'file_too_large';
    public const string FILE_UPLOAD_FAILED = 'file_upload_failed';

    private const array MESSAGES = [
        self::REQUIRED => 'This field is required',
        self::INVALID_VALUE => 'The value is not valid',
        self::INVALID_NUMBER => 'The value is not a number',
        self::OUT_OF_RANGE => 'The number is out of the allowed range',
        self::INVALID_DATE => 'The value is not a valid date',
        self::NOT_IN_OPTIONS => 'The value is not one of the options',
        self::FILE_TOO_LARGE => 'The file is too large',
        self::FILE_UPLOAD_FAILED => 'The file upload failed',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<RecordActionField> $fields pole dialogu (akce bez dialogu: `[]`)
     * @param array<array-key, mixed> $values surové hodnoty z požadavku `{<id pole>: hodnota}`
     * @param array<array-key, array{path: string, name: string, size: int, error: int, mimeType: string}> $files
     *        nahrané soubory `{<id pole>: popis}`; `error` je kód `UPLOAD_ERR_*`
     */
    public static function validate(array $fields, array $values, array $files = []): RecordActionInputCheck
    {
        $valueFields = [];
        $fileFields = [];
        foreach ($fields as $field) {
            if ($field->type === RecordActionField::FILE) {
                $fileFields[$field->id] = $field;
            } elseif ($field->hasValue()) {
                $valueFields[$field->id] = $field;
            }
        }

        foreach (array_keys($values) as $key) {
            if (!isset($valueFields[$key])) {
                return RecordActionInputCheck::unknownField((string) $key);
            }
        }
        foreach (array_keys($files) as $key) {
            if (!isset($fileFields[$key])) {
                return RecordActionInputCheck::unknownField((string) $key);
            }
        }

        $errors = [];
        $outValues = [];
        $outFiles = [];
        foreach ($fields as $field) {
            if (!$field->hasValue()) {
                continue;
            }

            if ($field->type === RecordActionField::FILE) {
                $result = self::file($field, $files[$field->id] ?? null);
                if ($result instanceof RecordActionFile) {
                    $outFiles[$field->id] = $result;
                } elseif ($result !== null) {
                    $errors[] = self::error($field, $result);
                } elseif ($field->required) {
                    $errors[] = self::error($field, self::REQUIRED);
                }
                continue;
            }

            [$value, $code] = self::value($field, $values[$field->id] ?? null);
            if ($code === null && $value === null && $field->required) {
                $code = self::REQUIRED;
            }
            if ($code !== null) {
                $errors[] = self::error($field, $code);
                continue;
            }
            $outValues[$field->id] = $value;
        }

        return $errors !== []
            ? RecordActionInputCheck::invalid($errors)
            : RecordActionInputCheck::valid(new RecordActionInput($outValues, $outFiles));
    }

    /**
     * Převod surové hodnoty podle typu pole.
     *
     * @return array{0: mixed, 1: ?string} [hodnota, kód chyby]; nevyplněné = `[null, null]`
     */
    private static function value(RecordActionField $field, mixed $raw): array
    {
        if ($field->type === RecordActionField::CHECKBOX) {
            return match (true) {
                $raw === null => [false, null],
                is_bool($raw) => [$raw, null],
                default => [null, self::INVALID_VALUE],
            };
        }

        if ($raw === null || $raw === '') {
            return [null, null];
        }

        switch ($field->type) {
            case RecordActionField::TEXT:
            case RecordActionField::TEXTAREA:
                if (!is_string($raw)) {
                    return [null, self::INVALID_VALUE];
                }
                // Jen mezery = nevyplněno; jinak hodnota, jak ji uživatel zadal.
                return [trim($raw) === '' ? null : $raw, null];

            case RecordActionField::PASSWORD:
                // Heslo se neořezává — mezera může být jeho součást.
                return is_string($raw) ? [$raw, null] : [null, self::INVALID_VALUE];

            case RecordActionField::NUMBER:
                return self::number($field, $raw);

            case RecordActionField::DATE:
                $date = is_string($raw) ? RecordActionField::parseDate($raw) : null;
                return $date !== null ? [$date, null] : [null, self::INVALID_DATE];

            case RecordActionField::DATETIME:
                $dateTime = is_string($raw) ? RecordActionField::parseDateTime($raw) : null;
                return $dateTime !== null ? [$dateTime, null] : [null, self::INVALID_DATE];

            case RecordActionField::SELECT:
                if (!is_string($raw) && !is_int($raw)) {
                    return [null, self::INVALID_VALUE];
                }
                $option = $field->optionValue($raw);
                return $option !== null ? [$option, null] : [null, self::NOT_IN_OPTIONS];
        }

        return [null, self::INVALID_VALUE];
    }

    /** @return array{0: int|float|null, 1: ?string} */
    private static function number(RecordActionField $field, mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [null, null];
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $raw) !== 1) {
                return [null, self::INVALID_NUMBER];
            }
            $raw = str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }
        if ((!is_int($raw) && !is_float($raw)) || (is_float($raw) && !is_finite($raw))) {
            return [null, self::INVALID_NUMBER];
        }
        if (($field->min !== null && $raw < $field->min) || ($field->max !== null && $raw > $field->max)) {
            return [null, self::OUT_OF_RANGE];
        }
        return [$raw, null];
    }

    /**
     * @param array{path: string, name: string, size: int, error: int, mimeType: string}|null $upload
     * @return RecordActionFile|string|null soubor, kód chyby, nebo `null` = nenahráno
     */
    private static function file(RecordActionField $field, ?array $upload): RecordActionFile|string|null
    {
        if ($upload === null || $upload['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            return self::FILE_TOO_LARGE;
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || $upload['path'] === '') {
            return self::FILE_UPLOAD_FAILED;
        }
        if ($upload['size'] > (int) $field->maxBytes) {
            return self::FILE_TOO_LARGE;
        }
        return new RecordActionFile($upload['path'], $upload['name'], $upload['size'], $upload['mimeType']);
    }

    /** @return array{field: string, code: string, message: string} */
    private static function error(RecordActionField $field, string $code): array
    {
        return ['field' => $field->id, 'code' => $code, 'message' => self::MESSAGES[$code]];
    }
}
