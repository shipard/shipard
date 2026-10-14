<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Jeden rozdíl porovnávače: tečkovaná cesta hodnoty (`rows[3].vat.pct`),
 * očekávání a skutečnost po projekci a normalizaci. U polí různé délky
 * nesou obě strany počet prvků.
 */
final class Difference
{
    public function __construct(
        public readonly string $path,
        public readonly mixed $expected,
        public readonly mixed $actual,
    ) {}

    /** Cesta uzlu projekce (indexy nahrazené `[]`). */
    public function nodePath(): string
    {
        return Projection::nodePath($this->path);
    }

    /** @return array{path: string, expected: mixed, actual: mixed} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'expected' => $this->expected, 'actual' => $this->actual];
    }

    public function __toString(): string
    {
        return "{$this->path}: očekáváno " . self::show($this->expected) . ', skutečnost ' . self::show($this->actual);
    }

    public static function show(mixed $value): string
    {
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $json = $json === false ? '[…]' : $json;
            return mb_strlen($json) > 80 ? mb_substr($json, 0, 77) . '…' : $json;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? 'null' : $json;   // ne `?:` — "0" je falsy
    }
}
