<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

/**
 * Rozebraný soubor pro EPO (`Pisemnost` → písemnost → věty) — jediný
 * parser XML podání na vstupu (#55 D33). Čte, nic neinterpretuje: věta je
 * jméno elementu, řádek věty mapa atributů jako řetězců. Co který atribut
 * znamená, říká `VatXmlMapping` (`Dp3XmlReader`, `EpoXmlLineComparer`).
 *
 * Bezpečnost jako `IsdocReader`: bez sítě, bez substituce entit, DTD se
 * odmítá (interní subset = vektor pro expanzi entit). Vstup je UTF-8 —
 * endpoint ho dostává z JSON, CLI soubor podle deklarace překóduje.
 *
 * Normalizace hodnot (`normalizeValue`) je záměrně shodná s
 * `Xml\EpoXmlDiff`: číslo podle hodnoty, datum podle dne, zbytek trimnutý
 * text — porovnání importu musí dávat stejný výsledek jako zlatý test.
 */
final class EpoXmlDocument
{
    public const ROOT = 'Pisemnost';

    /**
     * @param string $type                                   element písemnosti (`DPHDP3`, `DPHKH1`, `DPHSHV`)
     * @param array<string, list<array<string, string>>> $sentences věta → řádky (mapy atributů)
     */
    private function __construct(
        public readonly string $type,
        private readonly array $sentences,
    ) {}

    public static function fromString(string $xml): self
    {
        if (trim($xml) === '') {
            throw EpoXmlReadException::unreadable('prázdný soubor');
        }
        if (!mb_check_encoding($xml, 'UTF-8')) {
            throw EpoXmlReadException::unreadable('obsah není v UTF-8');
        }
        // Deklarace kódování musí sedět s bajty — přenos přes JSON už
        // překódoval, starý soubor mohl hlásit windows-1250.
        $xml = (string) preg_replace('/^(\s*<\?xml[^>]*encoding=")[^"]*(")/i', '$1UTF-8$2', $xml, 1);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
            if (!$loaded) {
                $error = libxml_get_last_error();
                throw EpoXmlReadException::unreadable($error !== false ? trim($error->message) : 'neplatné XML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($dom->doctype !== null) {
            throw EpoXmlReadException::unreadable('DTD není povolené');
        }
        $root = $dom->documentElement;
        if ($root === null || $root->nodeName !== self::ROOT) {
            throw EpoXmlReadException::unreadable(
                'kořenový element není ' . self::ROOT . ($root !== null ? " (je {$root->nodeName})" : ''),
            );
        }

        $document = null;
        foreach ($root->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                if ($document !== null) {
                    throw EpoXmlReadException::unreadable('písemnost má víc než jeden dokument');
                }
                $document = $node;
            }
        }
        if ($document === null) {
            throw EpoXmlReadException::unreadable('písemnost neobsahuje žádný dokument');
        }

        $sentences = [];
        foreach ($document->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $attributes = [];
            foreach ($node->attributes ?? [] as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }
            $sentences[$node->nodeName][] = $attributes;
        }

        return new self($document->nodeName, $sentences);
    }

    /** @return list<string> jména vět v pořadí prvního výskytu */
    public function sentenceNames(): array
    {
        return array_keys($this->sentences);
    }

    /** @return list<array<string, string>> všechny řádky věty (prázdné = věta chybí) */
    public function sentences(string $veta): array
    {
        return $this->sentences[$veta] ?? [];
    }

    /** @return ?array<string, string> první řádek věty */
    public function sentence(string $veta): ?array
    {
        return $this->sentences[$veta][0] ?? null;
    }

    /** Atribut prvního řádku věty; `null` když věta nebo atribut chybí. */
    public function attribute(string $veta, string $attribute): ?string
    {
        return $this->sentences[$veta][0][$attribute] ?? null;
    }

    /** Datum zjištění důvodů (`VetaD/@d_zjist`, DP3 i KH) jako ISO datum. */
    public function dateFound(string $vetaD = 'VetaD'): ?string
    {
        return self::isoDate($this->attribute($vetaD, 'd_zjist'));
    }

    /** Kód formy podání z věty D (`dapdph_forma`, `khdph_forma`, `shvies_forma`). */
    public function forma(string $formaAttribute, string $vetaD = 'VetaD'): ?string
    {
        $value = $this->attribute($vetaD, $formaAttribute);
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    // ── Hodnoty ─────────────────────────────────────────────────────────────

    /** Číselná hodnota atributu; chybějící nebo nečíselný atribut je nula (EPO). */
    public static function normalizeNumber(?string $value): float
    {
        $value = trim((string) ($value ?? ''));
        return preg_match('/^[-+]?\d+(\.\d+)?$/', $value) === 1 ? (float) $value : 0.0;
    }

    /** `DD.MM.RRRR` (i bez nul) nebo ISO → `RRRR-MM-DD`; jinak `null`. */
    public static function isoDate(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }
        return null;
    }

    /**
     * Hodnota ve tvaru, ve kterém má smysl ji porovnávat (jako
     * `EpoXmlDiff`): číslo kanonicky, datum jako ISO, jinak trimnutý text.
     */
    public static function normalizeValue(?string $value): string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^[-+]?\d+(\.\d+)?$/', $value) === 1) {
            return self::canonicalNumber((float) $value);
        }
        return self::isoDate($value) ?? $value;
    }

    /** Kanonický zápis čísla; nula (i záporná) je vždy `0`. */
    public static function canonicalNumber(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        return $text === '' || $text === '-0' || $text === '-' ? '0' : $text;
    }
}
