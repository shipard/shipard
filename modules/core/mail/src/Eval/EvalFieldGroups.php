<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Skupiny polí pro metriky evaluace (#103 D37) podle cesty uzlu projekce:
 * hlavička, dodavatel, odběratel, DPH (`vat.*`, `rows[].vat.*`), řádky,
 * rekapitulace, součty, štítek dokladu, výjimky štítků řádků a klasifikace
 * zprávy (virtuální cesta {@see CLASSIFICATION_PATH} mimo canonical).
 */
final class EvalFieldGroups
{
    public const string CLASSIFICATION_PATH = 'message_classification.primary_type';

    public const array GROUPS = ['header', 'supplier', 'customer', 'vat', 'rows', 'vatRecap', 'totals', 'tag', 'rowExceptions', 'classification'];

    private const array LABELS = [
        'header' => 'Hlavička',
        'supplier' => 'Dodavatel',
        'customer' => 'Odběratel',
        'vat' => 'DPH',
        'rows' => 'Řádky',
        'vatRecap' => 'Rekapitulace',
        'totals' => 'Součty',
        'tag' => 'Štítek dokladu',
        'rowExceptions' => 'Výjimky štítků řádků',
        'classification' => 'Klasifikace zprávy',
    ];

    /** Uzly, jejichž shoda znamená „správné částky“ (D37). */
    public const array AMOUNT_NODES = ['rows[].totalPrice', 'vatRecap', 'totals'];

    private function __construct()
    {
    }

    public static function groupOf(string $nodePath): string
    {
        if ($nodePath === self::CLASSIFICATION_PATH) {
            return 'classification';
        }
        if (str_starts_with($nodePath, '_resolve.contentTag.rowExceptions')) {
            return 'rowExceptions';
        }
        if (str_starts_with($nodePath, '_resolve.')) {
            return 'tag';
        }
        if (str_starts_with($nodePath, 'vat.') || str_starts_with($nodePath, 'rows[].vat')) {
            return 'vat';
        }
        if ($nodePath === 'rows' || str_starts_with($nodePath, 'rows[]')) {
            return 'rows';
        }
        if ($nodePath === 'vatRecap' || str_starts_with($nodePath, 'vatRecap[]')) {
            return 'vatRecap';
        }
        if (str_starts_with($nodePath, 'totals')) {
            return 'totals';
        }
        if (str_starts_with($nodePath, 'supplier')) {
            return 'supplier';
        }
        if (str_starts_with($nodePath, 'customer')) {
            return 'customer';
        }
        return 'header';
    }

    /** Uzel patří mezi částky (řádky `totalPrice`, rekapitulace, součty — včetně počtu prvků rekapitulace). */
    public static function isAmountNode(string $nodePath): bool
    {
        foreach (self::AMOUNT_NODES as $node) {
            if ($nodePath === $node || str_starts_with($nodePath, $node . '.') || str_starts_with($nodePath, $node . '[')) {
                return true;
            }
        }
        return false;
    }

    public static function label(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }
}
