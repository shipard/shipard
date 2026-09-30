<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Odpisové nastavení karty majetku (D30) — vstup enginu.
 *
 * Daňová metoda a kód pravidla pocházejí z pravidel země
 * (`world.assets`), účetní metoda je volba firmy: `as_tax` (vzorec daňové
 * metody nad účetním zůstatkem) nebo `time` (rovnoměrně po `accMonths`).
 * `null` = okruh se neodepisuje.
 */
final readonly class DepreciationSettings
{
    public const ACC_AS_TAX = 'as_tax';
    public const ACC_TIME = 'time';

    public function __construct(
        public ?string $taxMethod,
        public ?string $taxRule = null,
        public ?string $accMethod = null,
        public ?int $accMonths = null,
        /** Příznak druhu majetku (`economy.assets.categories`). */
        public bool $intangible = false,
    ) {
        if ($accMethod !== null && !in_array($accMethod, [self::ACC_AS_TAX, self::ACC_TIME], true)) {
            throw new \InvalidArgumentException("Neznámá účetní metoda odpisu '{$accMethod}'");
        }
        if ($accMonths !== null && $accMonths < 0) {
            throw new \InvalidArgumentException('Délka účetního odpisování nesmí být záporná');
        }
    }

    /**
     * Klíče = sloupce karty `tax_method`, `tax_rule`, `acc_method`,
     * `acc_months` + příznak `intangible`. Prázdný řetězec = nenastaveno.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $text = static fn(string $key): ?string
            => ($data[$key] ?? '') === '' ? null : (string) $data[$key];

        return new self(
            $text('tax_method'),
            $text('tax_rule'),
            $text('acc_method'),
            ($data['acc_months'] ?? '') === '' ? null : (int) $data['acc_months'],
            (bool) ($data['intangible'] ?? false),
        );
    }
}
