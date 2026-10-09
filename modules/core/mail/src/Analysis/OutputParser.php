<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Výstup modelu → objekt (tasks/mail-analysis-inprocess.md D18, přenos
 * `ai_analyzer/schema.py`): JSON přímo, jinak z markdown bloku
 * ```` ```json ```` (model občas obalí výstup i přes zákaz v promptu),
 * validace proti `output_schema` profilu přes `opis/json-schema`,
 * na nejvyšší úrovni musí být objekt.
 *
 * Formáty (`date-time`, `date`) se nekontrolují — démon (Python
 * `jsonschema.validate` bez format checkeru) je nekontroloval a server
 * `source.extractedAt` stejně přepíše (D12). Z chyb validace se vybírá
 * ta s nejhlubší cestou (jako `best_match` v Pythonu — u `oneOf`
 * dokumentu tak vyhraje konkrétní pole, ne „neodpovídá žádné větvi“)
 * a zpráva má textový tvar jsonschema, který parsuje
 * {@see \Shipard\Module\Core\Mail\AnalysisErrorPresenter}.
 */
final class OutputParser
{
    private const FENCE_PATTERN = '/```(?:json)?\s*([\s\S]*?)\s*```/m';

    /**
     * @param array<string, mixed>|\stdClass $schema `output_schema` profilu
     *        (dekódované JSON — `\stdClass` zachová prázdné objekty).
     * @return array<string, mixed>
     * @throws SchemaValidationException
     */
    public function parse(string $rawText, array|\stdClass $schema): array
    {
        $data = self::parseJson($rawText);
        $this->validate($data, $schema);
        if (!$data instanceof \stdClass) {
            throw new SchemaValidationException('output JSON must be an object at the top level');
        }

        return json_decode((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
    }

    private static function parseJson(string $rawText): mixed
    {
        $stripped = trim($rawText);
        try {
            return json_decode($stripped, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // zkusit markdown blok níže
        }
        if (preg_match(self::FENCE_PATTERN, $stripped, $m)) {
            try {
                return json_decode(trim($m[1]), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new SchemaValidationException('fenced JSON is invalid: ' . $e->getMessage());
            }
        }
        throw new SchemaValidationException('output is not valid JSON');
    }

    /**
     * @param array<string, mixed>|\stdClass $schema
     */
    private function validate(mixed $data, array|\stdClass $schema): void
    {
        $schemaObject = $schema instanceof \stdClass
            ? $schema
            : ($schema === [] ? new \stdClass() : json_decode((string) json_encode($schema, JSON_UNESCAPED_UNICODE)));
        if (!$schemaObject instanceof \stdClass) {
            throw new SchemaValidationException('output_schema of the profile is not a JSON object');
        }

        $validator = new Validator();
        $validator->parser()->setOption('allowFormats', false);
        $result = $validator->validate($data, $schemaObject);
        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        $leaf = $error !== null ? self::deepestLeaf($error) : null;
        if ($leaf === null) {
            throw new SchemaValidationException('output does not match schema');
        }
        throw new SchemaValidationException(sprintf(
            'output does not match schema: %s at %s',
            self::pythonMessage($leaf),
            self::pythonList($leaf->data()->fullPath()),
        ));
    }

    /** Listová chyba s nejdelší cestou v dokumentu; při shodě první nalezená. */
    private static function deepestLeaf(ValidationError $error): ValidationError
    {
        $best = null;
        $bestDepth = -1;
        $stack = [$error];
        while ($stack !== []) {
            $current = array_shift($stack);
            $subErrors = $current->subErrors();
            if ($subErrors !== []) {
                array_unshift($stack, ...$subErrors);
                continue;
            }
            $depth = count($current->data()->fullPath());
            if ($depth > $bestDepth) {
                $best = $current;
                $bestDepth = $depth;
            }
        }
        return $best ?? $error;
    }

    /** Hláška ve tvaru Python `jsonschema` per klíčové slovo. */
    private static function pythonMessage(ValidationError $error): string
    {
        $args = $error->args();
        $value = $error->data()->value();

        switch ($error->keyword()) {
            case 'additionalProperties':
                $keys = array_map(static fn($k): string => self::pythonRepr((string) $k), (array) ($args['properties'] ?? []));
                return sprintf(
                    'Additional properties are not allowed (%s %s unexpected)',
                    implode(', ', $keys),
                    count($keys) > 1 ? 'were' : 'was',
                );
            case 'maxLength':
                return self::pythonRepr($value) . ' is too long';
            case 'minLength':
                return self::pythonRepr($value) . ' is too short';
            case 'enum':
                return self::pythonRepr($value) . ' is not one of ' . self::pythonRepr(self::schemaKeyword($error, 'enum'));
            case 'const':
                return self::pythonRepr($args['const'] ?? self::schemaKeyword($error, 'const')) . ' was expected';
            case 'required':
                $missing = (array) ($args['missing'] ?? []);
                return self::pythonRepr((string) ($missing[0] ?? '')) . ' is a required property';
            case 'type':
                $expected = $args['expected'] ?? '';
                $types = is_array($expected) ? $expected : [$expected];
                return self::pythonRepr($value) . ' is not of type ' . implode(', ', array_map(
                    static fn($t): string => self::pythonRepr((string) $t),
                    $types,
                ));
            case 'minimum':
                return self::pythonRepr($value) . ' is less than the minimum of '
                    . self::pythonRepr(self::schemaKeyword($error, 'minimum') ?? $args['min'] ?? null);
            case 'maximum':
                return self::pythonRepr($value) . ' is greater than the maximum of '
                    . self::pythonRepr(self::schemaKeyword($error, 'maximum') ?? $args['max'] ?? null);
            case 'pattern':
                return self::pythonRepr($value) . ' does not match ' . self::pythonRepr($args['pattern'] ?? '');
            default:
                return $error->keyword() . ': ' . self::formatMessage($error);
        }
    }

    /** Hodnota klíčového slova ze schématu chyby (enum, const), nebo null. */
    private static function schemaKeyword(ValidationError $error, string $keyword): mixed
    {
        try {
            $schema = $error->schema()->info()->data();
            return is_object($schema) ? ($schema->{$keyword} ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function formatMessage(ValidationError $error): string
    {
        $message = $error->message();
        foreach ($error->args() as $key => $value) {
            $message = str_replace('{' . $key . '}', is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE), $message);
        }
        return $message;
    }

    /** Python `list` repr cesty: `['document', 'rows', 0, 'vat']`. */
    private static function pythonList(array $path): string
    {
        return '[' . implode(', ', array_map(
            static fn($segment): string => is_int($segment) ? (string) $segment : self::pythonRepr((string) $segment),
            $path,
        )) . ']';
    }

    /** Přibližný Python `repr()` — řetězce v apostrofech, None/True/False, seznamy a slovníky. */
    private static function pythonRepr(mixed $value): string
    {
        if ($value === null) {
            return 'None';
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? sprintf('%.1f', $value) : (string) $value;
        }
        if (is_string($value)) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }
        if (is_array($value)) {
            if ($value === [] || array_is_list($value)) {
                return '[' . implode(', ', array_map([self::class, 'pythonRepr'], $value)) . ']';
            }
            $value = (object) $value;
        }
        if (is_object($value)) {
            $pairs = [];
            foreach (get_object_vars($value) as $k => $v) {
                $pairs[] = self::pythonRepr((string) $k) . ': ' . self::pythonRepr($v);
            }
            return '{' . implode(', ', $pairs) . '}';
        }
        return (string) json_encode($value);
    }
}
