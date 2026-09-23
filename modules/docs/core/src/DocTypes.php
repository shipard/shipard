<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Statické čtení atributů typu dokladu z cfgItem `docs.core.docTypes`
 * (`config/docTypes.jsonc`), které nemají jinou autoritu.
 *
 * `tax_document` (#79 D1): `false` = doklad není daňový (zálohová faktura
 * vydaná) — DPH se na něm počítá jen informativně, nemá DUZP ani DPPD
 * a nevstupuje do tvrzení DPH. Chybějící atribut = daňový doklad (všechny
 * dosavadní typy). Kód nikdy neporovnává `doc_type === 'invpo'` — vždy
 * tenhle helper.
 *
 * Směr obchodu má vlastní autoritu `DocDocument::resolveTradeDir()`.
 */
final class DocTypes
{
    /**
     * Bez configu, u neznámého typu i bez atributu = daňový doklad (dnešní
     * chování); nedaňový jen při explicitním `false`.
     */
    public static function isTaxDocument(?ConfigRuntime $config, string $docType): bool
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes) || !is_array($docTypes[$docType] ?? null)) {
            return true;
        }
        return ($docTypes[$docType]['tax_document'] ?? true) !== false;
    }

    /**
     * Typy s `tax_document: false` — pro SQL pojistky (`doc_type NOT IN`).
     *
     * @return list<string>
     */
    public static function nonTaxDocTypes(?ConfigRuntime $config): array
    {
        $docTypes = $config?->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes)) {
            return [];
        }
        $result = [];
        foreach ($docTypes as $key => $docType) {
            if (is_array($docType) && ($docType['tax_document'] ?? true) === false) {
                $result[] = (string) $key;
            }
        }
        return $result;
    }
}
