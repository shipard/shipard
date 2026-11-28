<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Profil účtenkové tiskárny (#177 U29, kontrakt `docs/native-bridge.md` §6):
 * šířky řádku v obou písmech, kódová stránka, funkce a ovladač, který dává
 * bajty příkazů. Šířky jsou parametry profilu, ne kódu.
 */
final class PrinterProfile
{
    public const FEATURE_CUT    = 'cut';
    public const FEATURE_DRAWER = 'drawer';
    public const FEATURE_QR     = 'qr';

    public const FEATURES = [self::FEATURE_CUT, self::FEATURE_DRAWER, self::FEATURE_QR];

    /**
     * @param list<string> $features
     * @param list<string> $models   modely, na kterých je profil ověřený
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $driver,
        public readonly int $columns,
        public readonly int $columnsSmall,
        public readonly int $dotsPerLine,
        public readonly string $codePage,
        public readonly array $features,
        public readonly array $models,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $id, array $data): self
    {
        $fail = static fn(string $what): EscPosConfigException
            => new EscPosConfigException("Printer profile '{$id}': {$what}");

        $driver = $data['driver'] ?? null;
        if (!is_string($driver) || $driver === '') {
            throw $fail("'driver' is required");
        }

        $numbers = [];
        foreach (['columns', 'columnsSmall', 'dotsPerLine'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_int($value) || $value < 1) {
                throw $fail("'{$key}' must be a positive integer");
            }
            $numbers[$key] = $value;
        }
        if ($numbers['columnsSmall'] < $numbers['columns']) {
            throw $fail("'columnsSmall' must not be lower than 'columns'");
        }

        $codePage = $data['codePage'] ?? null;
        if (!is_string($codePage) || !CodePages::has($codePage)) {
            throw $fail("'codePage' is not supported");
        }

        $features = [];
        foreach (is_array($data['features'] ?? null) ? $data['features'] : [] as $feature) {
            if (!in_array($feature, self::FEATURES, true)) {
                throw $fail("unknown feature '" . (is_string($feature) ? $feature : '?') . "'");
            }
            $features[] = $feature;
        }

        $models = array_values(array_filter(
            is_array($data['models'] ?? null) ? $data['models'] : [],
            'is_string',
        ));
        $name = $data['name'] ?? null;

        return new self(
            $id,
            is_string($name) && $name !== '' ? $name : $id,
            $driver,
            $numbers['columns'],
            $numbers['columnsSmall'],
            $numbers['dotsPerLine'],
            $codePage,
            array_values(array_unique($features)),
            $models,
        );
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /** Šířka řádku ve znacích pro písmo a násobek šířky znaku. */
    public function lineWidth(bool $small, int $charWidth = 1): int
    {
        return max(1, intdiv($small ? $this->columnsSmall : $this->columns, max(1, $charWidth)));
    }

    /**
     * Tvar kontraktu §6 — ovladač je věc serveru, aplikace ho nedostává.
     *
     * @return array{id: string, name: string, columns: int, columnsSmall: int, dotsPerLine: int, codePage: string, features: list<string>, models: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'columns'      => $this->columns,
            'columnsSmall' => $this->columnsSmall,
            'dotsPerLine'  => $this->dotsPerLine,
            'codePage'     => $this->codePage,
            'features'     => $this->features,
            'models'       => $this->models,
        ];
    }
}
