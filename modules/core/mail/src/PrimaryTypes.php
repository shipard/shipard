<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Čtení cfgItem `core.mail.primaryTypes` — jediné místo, které interpretuje
 * `target` a `docKind` typu dokumentu (D4: jedna klasifikační osa, typy
 * návrhů splynuly s primary types). Sdílí MessageProposalApplier,
 * AnalysisController (ingest/preview), RegistryApplier a
 * MailSuggestionsSource, aby fallback logika nežila v kopiích.
 */
final class PrimaryTypes
{
    public const TARGET_DOCS = 'docs';
    public const TARGET_REGISTRY = 'registry';

    /** Zpráva bez dokladu i dokumentu Spisovny („ostatní pošta“). */
    public const OTHER = 'other';

    /**
     * Target typu: `primaryTypes[type]['target'] ?? 'docs'`.
     * Chybějící compiled config, neznámý typ i typ bez `target` degradují
     * na `docs` (zpětná kompatibilita).
     */
    public static function targetFor(?ConfigRuntime $config, string $type): string
    {
        $cfg = self::typeConfig($config, $type);
        $target = $cfg['target'] ?? null;
        return is_string($target) && $target !== '' ? $target : self::TARGET_DOCS;
    }

    /** Klíč do `base.registry.docKinds` u registry typů; jinak null. */
    public static function docKindFor(?ConfigRuntime $config, string $type): ?string
    {
        $cfg = self::typeConfig($config, $type);
        $docKind = $cfg['docKind'] ?? null;
        return is_string($docKind) && $docKind !== '' ? $docKind : null;
    }

    /** Typ existuje v cfgItem (bez ohledu na enabled). */
    public static function isKnown(?ConfigRuntime $config, string $type): bool
    {
        if ($type === '' || $config === null) {
            return false;
        }
        $types = $config->cfgItem('core.mail.primaryTypes');
        return is_array($types) && is_array($types[$type] ?? null);
    }

    /** @return array<string, mixed> */
    private static function typeConfig(?ConfigRuntime $config, string $type): array
    {
        if ($type === '' || $config === null) {
            return [];
        }
        $types = $config->cfgItem('core.mail.primaryTypes');
        $cfg = is_array($types) ? ($types[$type] ?? null) : null;
        return is_array($cfg) ? $cfg : [];
    }
}
