<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Dibi\Connection;
use Shipard\Core\Database\RecordCode;

/**
 * Kód registrace DPH — přirozený klíč záznamu (#166 D16, D17): registrace
 * země s nejnižším id mezi nesmazanými má kód země malými písmeny (`cz`),
 * další registrace téže země `cz-2`, `cz-3`, …
 *
 * Obsazený je i kód registrace v koši — unikátní index platí pro všechny
 * řádky. Jedno pravidlo pro nový záznam (formulář,
 * `VatRegistrationDocument`) i pro doplnění existujících registrací při
 * `ds-upgrade`.
 */
final class VatRegistrationCodeGenerator
{
    public const TABLE = VatRegistrationDocument::TABLE;
    public const MAX_LENGTH = 20;

    private const DELETED = 90;
    /** Základ kódu registrace bez použitelné země (poškozený řádek). */
    private const FALLBACK_BASE = 'vat';

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Kód pro registraci — novou (bez `id`) i existující bez kódu.
     *
     * @param array<string, mixed> $registration `country`, u existující
     *        registrace `id` a `docState`
     * @param array<string, true> $alsoTaken kódy obsazené nad rámec toho,
     *        co je vidět v databázi (souběžný zápis, {@see RecordCode::key()})
     */
    public function generate(array $registration, array $alsoTaken = []): string
    {
        $id = (int) ($registration['id'] ?? 0);
        $country = (string) ($registration['country'] ?? '');

        return self::compose(
            $country,
            $this->isFirstOfCountry($country, $id, (int) ($registration['docState'] ?? 0)),
            $this->takenKeys($id) + $alsoTaken,
        );
    }

    /**
     * Čisté pravidlo kódu.
     *
     * @param bool $firstOfCountry registrace s nejnižším id mezi
     *        nesmazanými registracemi země
     * @param array<string, true> $taken obsazené kódy ({@see RecordCode::key()})
     */
    public static function compose(string $country, bool $firstOfCountry, array $taken): string
    {
        $base = RecordCode::base(strtolower($country));
        if ($base === '') {
            $base = self::FALLBACK_BASE;
        }
        return $firstOfCountry
            ? RecordCode::unique($base, $taken, self::MAX_LENGTH)
            : RecordCode::numbered($base, $taken, self::MAX_LENGTH);
    }

    /**
     * Kdo kód už má — jiná registrace než `$exceptId`, i v koši. Porovnává
     * databáze (kolace sloupce nerozlišuje velikost písmen).
     *
     * @return array{id: int, name: string, deleted: bool}|null
     */
    public function holder(string $code, int $exceptId = 0): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [name], [docState] FROM %n WHERE [code] = %s AND [id] <> %i ORDER BY [id] LIMIT 1',
            self::TABLE,
            $code,
            $exceptId,
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'      => (int) $row['id'],
            'name'    => (string) ($row['name'] ?? ''),
            'deleted' => (int) ($row['docState'] ?? 0) === self::DELETED,
        ];
    }

    /**
     * Je registrace tou s nejnižším id mezi nesmazanými registracemi země?
     * Nová (bez id) jen tehdy, když země žádnou nesmazanou nemá; registrace
     * v koši nikdy.
     */
    private function isFirstOfCountry(string $country, int $id, int $docState): bool
    {
        if ($docState === self::DELETED) {
            return false;
        }
        $row = $this->db->fetch(
            'SELECT MIN([id]) AS [first_id] FROM %n WHERE [country] = %s AND [docState] <> %i',
            self::TABLE,
            $country,
            self::DELETED,
        );
        $firstId = $row === null ? 0 : (int) ($row['first_id'] ?? 0);
        return $firstId === 0 || $firstId === $id;
    }

    /**
     * Obsazené kódy všech ostatních registrací včetně koše.
     *
     * @return array<string, true>
     */
    private function takenKeys(int $exceptId): array
    {
        $taken = [];
        $rows = $this->db->fetchAll(
            "SELECT [code] FROM %n WHERE [code] IS NOT NULL AND [code] <> '' AND [id] <> %i",
            self::TABLE,
            $exceptId,
        );
        foreach ($rows as $row) {
            $taken[RecordCode::key((string) $row['code'])] = true;
        }
        return $taken;
    }
}
