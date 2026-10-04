<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Send;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;

/**
 * Účely odesílání na kontaktu (`base_persons_contacts.send_purposes`, #90
 * D34) — jediné místo, které zná tvar sloupce: JSON pole id účelů, NULL =
 * žádný účel. Názvy účelů jsou v cfgItemu `base.persons.sendPurposes`.
 */
final class SendPurposes
{
    /**
     * Hodnota sloupce (JSON text z databáze, pole z formuláře, NULL) jako
     * seznam id účelů. Null = hodnota není seznam řetězců.
     *
     * @return list<string>|null
     */
    public static function decode(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        foreach ($value as $purpose) {
            if (!is_string($purpose) || $purpose === '') {
                return null;
            }
        }
        return $value;
    }

    /**
     * Seznam účelů pro uložení do sloupce; prázdný výběr = NULL.
     *
     * @param list<string> $purposes
     */
    public static function encode(array $purposes): ?string
    {
        $purposes = array_values(array_unique($purposes));
        return $purposes === []
            ? null
            : json_encode($purposes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Názvy účelů v jazyce konfigurace, v pořadí cfgItemu; účel, který
     * konfigurace nezná (modul vypnutý po uložení), se ukáže svým id.
     *
     * @param list<string> $purposes
     * @return list<string>
     */
    public static function labels(array $purposes, ?ConfigRuntime $config): array
    {
        $known = $config?->cfgItem(ConfigCompiler::SEND_PURPOSES_ITEM);
        $known = is_array($known) ? $known : [];

        $labels = [];
        foreach (array_keys($known) as $purposeId) {
            if (in_array((string) $purposeId, $purposes, true)) {
                $labels[] = self::label((string) $purposeId, $config);
            }
        }
        foreach ($purposes as $purpose) {
            if (!array_key_exists($purpose, $known)) {
                $labels[] = $purpose;
            }
        }
        return $labels;
    }

    public static function label(string $purpose, ?ConfigRuntime $config): string
    {
        $known = $config?->cfgItem(ConfigCompiler::SEND_PURPOSES_ITEM);
        $name  = is_array($known) ? ($known[$purpose]['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : $purpose;
    }
}
