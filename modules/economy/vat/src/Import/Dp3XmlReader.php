<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Přiznání k DPH z XML → podané hodnoty řádků (#55 D33): mapování
 * `vat-xml-cz.jsonc` `rows` (řádek → věta + atribut base/full/reduced)
 * obráceně. Writer číslo řádku ani jméno atributu nezná a čtečka taky ne —
 * obojí je v configu.
 *
 * Chybějící atribut je nula (EPO bere nevyplněné jako nulu, starý Shipard
 * nuly u známých řádků vypisoval). Atributy hodnotových vět (`Veta1`…),
 * které mapování nezná a mají nenulovou hodnotu, jsou `unmapped` — řádky
 * formuláře, které nová strana nevykazuje; importní služba je zapíše do
 * zpráv, aby nezmizely potichu.
 */
final class Dp3XmlReader
{
    /** Slot mapování → klíč hodnoty ve tvaru `filedRows()`. */
    private const SLOT_COLUMN = ['base' => 'base', 'full' => 'taxFull', 'reduced' => 'taxReduced'];

    public function __construct(private readonly VatXmlMapping $mapping) {}

    public function read(EpoXmlDocument $xml): Dp3XmlData
    {
        if ($xml->type !== $this->mapping->element()) {
            throw EpoXmlReadException::typeMismatch($this->mapping->element(), $xml->type);
        }

        /** @var array<string, array<string, true>> $known věta → známé atributy */
        $known = [];
        $rows  = [];
        $slots = [];
        foreach ($this->mapping->rows() as $row => $definition) {
            $veta   = (string) $definition['veta'];
            $values = ['base' => 0.0, 'taxFull' => 0.0, 'taxReduced' => 0.0];
            $rowSlots = [];
            foreach (self::SLOT_COLUMN as $slot => $column) {
                if (!isset($definition[$slot])) {
                    continue;
                }
                $attribute = (string) $definition[$slot];
                $known[$veta][$attribute] = true;
                $rowSlots[] = $slot;
                $values[$column] = EpoXmlDocument::normalizeNumber($xml->attribute($veta, $attribute));
            }
            // Koeficient ř. 52/53 je atribut věty, ne hodnota řádku.
            if (isset($definition['percent']['attr'])) {
                $known[$veta][(string) $definition['percent']['attr']] = true;
            }
            $rows[(int) $row]  = $values;
            $slots[(int) $row] = $rowSlots;
        }

        $unmapped = [];
        foreach ($xml->sentenceNames() as $veta) {
            if (preg_match('/^Veta\d+$/', $veta) !== 1) {
                continue;
            }
            foreach ($xml->sentences($veta) as $attributes) {
                foreach ($attributes as $attribute => $value) {
                    if (isset($known[$veta][$attribute])
                        || abs(EpoXmlDocument::normalizeNumber($value)) < 0.005
                    ) {
                        continue;
                    }
                    $unmapped[] = ['veta' => $veta, 'attribute' => (string) $attribute, 'value' => trim($value)];
                }
            }
        }

        $header = $this->mapping->header();
        $vetaD  = (string) ($header['vetaD'] ?? 'VetaD');
        $vetaP  = (string) ($header['vetaP'] ?? 'VetaP');

        return new Dp3XmlData(
            rows: $rows,
            slots: $slots,
            unmapped: $unmapped,
            vetaD: $xml->sentence($vetaD) ?? [],
            vetaP: $xml->sentence($vetaP) ?? [],
            forma: $xml->forma($this->mapping->formaAttribute(), $vetaD),
            dateFound: $xml->dateFound($vetaD),
        );
    }
}
