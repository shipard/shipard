<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Isdoc;

/**
 * Validace ISDOC XML proti lokálním schématům `schemas/isdoc/` (#119 D6):
 * výstup generátoru musí projít 6.0.1 i 6.0.2. Testy ji volají nad
 * fixturami i korpusem, CLI `einvoice-render` před zápisem souboru;
 * ve fázi 2 za běhu (neplatný výstup = bez ISDOC + hlášení, PDF jde ven).
 *
 * Schéma hlídá strukturu (pořadí a povinnost elementů, booleany
 * `true` / `false`, UUID, unikátní ID řádků v 6.0.2), ne obsah — prázdný
 * řetězec je pro XSD platná hodnota, proto povinná pole hlídá generátor.
 */
final class IsdocSchemaValidator
{
    public const VERSIONS = ['6.0.1', '6.0.2'];

    private readonly string $xsdDir;

    public function __construct(?string $xsdDir = null)
    {
        $this->xsdDir = $xsdDir ?? dirname(__DIR__, 2) . '/schemas/isdoc';
    }

    public function schemaFile(string $version): string
    {
        if (!in_array($version, self::VERSIONS, true)) {
            throw new \DomainException("Schéma ISDOC '{$version}' není v repozitáři (jen " . implode(', ', self::VERSIONS) . ')');
        }
        $file = "{$this->xsdDir}/isdoc-invoice-{$version}.xsd";
        if (!is_file($file)) {
            throw new \RuntimeException("Chybí schéma XSD '{$file}'");
        }
        return $file;
    }

    /**
     * @return list<string> hlášky libxml (`řádek N: …`); prázdné pole = XML je validní
     */
    public function validate(string $xml, string $version): array
    {
        $schema = $this->schemaFile($version);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new \DOMDocument();
            // LIBXML_NONET: ani XML, ani schéma nesmí sahat do sítě (XXE).
            if (!$dom->loadXML($xml, LIBXML_NONET)) {
                return self::collectErrors();
            }
            if ($dom->schemaValidate($schema, LIBXML_NONET)) {
                return [];
            }
            return self::collectErrors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Validace proti všem verzím v repozitáři.
     *
     * @return array<string, list<string>> verze → hlášky; jen verze s chybou, prázdné pole = validní všude
     */
    public function validateAll(string $xml): array
    {
        $out = [];
        foreach (self::VERSIONS as $version) {
            $messages = $this->validate($xml, $version);
            if ($messages !== []) {
                $out[$version] = $messages;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function collectErrors(): array
    {
        $out = [];
        foreach (libxml_get_errors() as $error) {
            $message = trim($error->message);
            $out[] = $error->line > 0 ? "řádek {$error->line}: {$message}" : $message;
        }
        return $out === [] ? ['XML neprošlo schématem (bez podrobností od libxml)'] : $out;
    }
}
