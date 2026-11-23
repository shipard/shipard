<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Maps a free-form unit token from canonical (`"h"`, `"ks"`, `"kWh"`) to
 * a `core_units.id`. Strategy:
 *
 *   1. Normalize the input (lowercase, strip whitespace and trailing dots).
 *   2. Apply the alias map ({@see ALIASES}) to translate canonical / local
 *      synonyms onto the Shipard `system_code` we ship in unitsSeed.
 *   3. Look that `system_code` up in `core_units` (active rows only).
 *   4. Fall back to a case-insensitive lookup on `core_units.shortcut`
 *      (matches user-defined units).
 *   5. Last resort: case-insensitive lookup on `core_units.name` —
 *      supplier documents often carry the full unit name („Kus“)
 *      instead of the shortcut („ks“).
 *
 * No `canCreate` — units are a closed system codebook in Shipard, the
 * user adds them via the Settings viewer, not implicitly via apply.
 */
class UnitResolver
{
    /** Active doc states for codebook lookups (Koncept, V pořádku, V opravě). */
    private const ACTIVE_STATES = [10, 40, 80];

    /**
     * Canonical / locale unit token → Shipard `system_code`.
     *
     * Keys are lowercase; UnitResolver lowercases input before lookup.
     */
    private const ALIASES = [
        // ISO / canonical
        'h'    => 'hr',
        'pc'   => 'pcs',
        'piece' => 'pcs',
        'pcs'  => 'pcs',
        'kg'   => 'kg',
        'g'    => 'g',
        't'    => 't',
        'm'    => 'm',
        'km'   => 'km',
        'm2'   => 'm2',
        'm^2'  => 'm2',
        'sqm'  => 'm2',
        'm3'   => 'm3',
        'm^3'  => 'm3',
        'l'    => 'l',
        'ltr'  => 'l',
        'kwh'  => 'kwh',
        'mwh'  => 'mwh',
        'gj'   => 'gj',

        // Czech shortcuts
        'ks'   => 'pcs',
        'kus'  => 'pcs',
        'kusy' => 'pcs',
        'kusů' => 'pcs',
        'hod'  => 'hr',
        'den'  => 'day',
        'měs'  => 'mnth',
        'mes'  => 'mnth',
        'rok'  => 'year',

        // Time fractions
        '30min' => 'hr_2',
        '15min' => 'hr_4',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param bool $systemCodeOnly Pokyn (#166 D37): jen přesný systémový kód
     *        jednotky — bez zkratek, aliasů a názvů, hodnota se neupravuje.
     */
    public function resolve(?string $unit, bool $systemCodeOnly = false): ResolveResult
    {
        if ($unit === null) {
            return ResolveResult::notFound();
        }
        if ($systemCodeOnly) {
            if ($unit === '') {
                return ResolveResult::notFound();
            }
            $row = $this->db->fetch(
                'SELECT [id] FROM [core_units]
                 WHERE [system_code] = %s AND [docState] IN (%i, %i, %i)
                 LIMIT 1',
                $unit,
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            return $row !== null
                ? ResolveResult::matched((int) $row['id'], 'systemCode')
                : ResolveResult::notFound();
        }
        $token = trim($unit);
        if ($token === '') {
            return ResolveResult::notFound();
        }

        $lower = rtrim(mb_strtolower($token), '.');
        if ($lower === '') {
            return ResolveResult::notFound();
        }
        $systemCode = self::ALIASES[$lower] ?? null;

        // First try resolving via system_code (either via alias or directly).
        $probe = $systemCode ?? $lower;
        $row = $this->db->fetch(
            'SELECT [id] FROM [core_units]
             WHERE [system_code] = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $probe,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        if ($row !== null) {
            return ResolveResult::matched(
                (int) $row['id'],
                $systemCode !== null ? 'alias' : 'systemCode',
            );
        }

        // Fall back to shortcut — case-insensitive, picks the first match.
        $row = $this->db->fetch(
            'SELECT [id] FROM [core_units]
             WHERE LOWER([shortcut]) = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $lower,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        if ($row !== null) {
            return ResolveResult::matched((int) $row['id'], 'shortcut');
        }

        // Last resort: match on the unit name (e.g. "Kus" → pcs).
        $row = $this->db->fetch(
            'SELECT [id] FROM [core_units]
             WHERE LOWER([name]) = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $lower,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        if ($row !== null) {
            return ResolveResult::matched((int) $row['id'], 'name');
        }

        return ResolveResult::notFound();
    }

    /**
     * Jednotka číslem (#166 D60): id aktivní jednotky — tvar, kterým formát
     * nese jednotku bez systémového kódu (založenou uživatelem).
     */
    public function resolveId(int $unitId): ResolveResult
    {
        $row = $unitId > 0 ? $this->db->fetch(
            'SELECT [id] FROM [core_units]
             WHERE [id] = %i AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $unitId,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        ) : null;
        return $row !== null
            ? ResolveResult::matched((int) $row['id'], 'id')
            : ResolveResult::notFound();
    }

    /**
     * Systémový kód jednotky, kterou {@see resolve()} našel **kódem nebo
     * aliasem** — plyne z textu, bez dotazu. Výměnný formát nese jednotku
     * kódem, papír zkratkou nebo názvem (#166 D37, D52). Null = shoda jinou
     * cestou (zkratka, název) — kód dá {@see systemCodeById()}.
     */
    public static function systemCodeFromText(string $unit, ?string $matchedBy): ?string
    {
        if (!in_array($matchedBy, ['systemCode', 'alias'], true)) {
            return null;
        }
        $lower = rtrim(mb_strtolower(trim($unit)), '.');
        return self::ALIASES[$lower] ?? $lower;
    }

    /** Systémový kód jednotky podle id; null = jednotka kód nemá. */
    public function systemCodeById(int $unitId): ?string
    {
        $row = $this->db->fetch('SELECT [system_code] FROM [core_units] WHERE [id] = %i', $unitId);
        $code = trim((string) ($row['system_code'] ?? ''));
        return $code !== '' ? $code : null;
    }
}
