<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Generátor XML kontrolního hlášení (DPHKH1) ze snapshotu podání
 * (issue #55, X1/X4).
 *
 * Sekce A.1–B.3 vzniknou z řádků snapshotu (`economy_vat_filing_cs_rows`)
 * v pořadí, v jakém je nese config — a to je pořadí `xs:sequence`
 * schématu. Detailní řádky jdou po dokladech, A.5 a B.3 jedním součtovým
 * řádkem. Kontrolní hlášení se podává **na haléře**, takže se hodnoty
 * nezaokrouhlují (`valueScale` = 2).
 *
 * Věta C není součtem řádků hlášení, ale kontrolou proti přiznání
 * (X11): bere základy per řádek přiznání, které při sestavení spočítal
 * composer nad toutéž dokladovou úrovní.
 *
 * Atributy, které snapshot nenese, protože jejich agenda je mimo M1
 * (zvláštní režimy § 89/§ 90, oprava u nedobytné pohledávky § 46, poměrný
 * nárok § 75), vypisuje z konstant v configu (X10).
 */
final class Kh1XmlWriter extends EpoXmlWriter
{
    protected function appendBody(\DOMElement $document, FilingXmlInput $input): void
    {
        $this->appendSections($document, $input);
        $this->appendControlTotals($document, $input);
    }

    private function appendSections(\DOMElement $document, FilingXmlInput $input): void
    {
        foreach ($this->mapping->sections() as $section => $definition) {
            foreach ($this->rowsOfSection($input, (string) $section) as $row) {
                $this->appendSentence(
                    $document,
                    (string) $definition['veta'],
                    $this->rowAttributes($definition, $row),
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>> řádky sekce v pořadí snapshotu
     */
    private function rowsOfSection(FilingXmlInput $input, string $section): array
    {
        return array_values(array_filter(
            $input->controlRows,
            static fn (array $row): bool => (string) ($row['section'] ?? '') === $section,
        ));
    }

    /**
     * Atributy jednoho řádku sekce. Pořadí je stálé (identifikace, datum,
     * pásma, kódy), takže generování je deterministické.
     *
     * @param array<string, mixed> $definition mapování sekce
     * @param array<string, mixed> $row        řádek snapshotu
     * @return array<string, ?string>
     */
    private function rowAttributes(array $definition, array $row): array
    {
        $attributes = [];

        $vatId = $definition['vatId'] ?? null;
        if ($vatId !== null) {
            $value = $row['partner_vat_id'] ?? null;
            if (($vatId['format'] ?? 'cz') === 'eu') {
                [$country, $number] = EpoXmlFormat::euVatId($value);
                $attributes[(string) $vatId['countryAttr']] = $country;
                $attributes[(string) $vatId['attr']]        = $number;
            } else {
                $attributes[(string) $vatId['attr']] = EpoXmlFormat::taxNumberDigits($value);
            }
        }

        if (isset($definition['evidNumber'])) {
            $attributes[(string) $definition['evidNumber']] = self::text($row['doc_number'] ?? null);
        }
        if (isset($definition['date'])) {
            $attributes[(string) $definition['date']] = EpoXmlFormat::date($row['vat_dppd'] ?? null);
        }

        $alwaysEmit = $definition['alwaysEmit'] ?? [];
        foreach ($definition['bands'] ?? [] as $band => $attribute) {
            $attributes[(string) $attribute] = $this->money(
                (float) ($row[$band] ?? 0.0),
                in_array($attribute, $alwaysEmit, true),
            );
        }

        if (isset($definition['kodPredPl'])) {
            // Kód předmětu plnění je řetězec ("4a") — bez přetypování (#89 D13).
            $code = trim((string) ($row['reverse_charge_code'] ?? ''));
            $attributes[(string) $definition['kodPredPl']] = $code === '' ? null : $code;
        }
        foreach ($definition['constants'] ?? [] as $attribute => $value) {
            $attributes[(string) $attribute] = (string) $value;
        }

        return $attributes;
    }

    /**
     * Věta C — kontrolní součty proti přiznání. Vypíše se jen když je co
     * vykázat; prázdné hlášení kontrolní řádky nemá.
     */
    private function appendControlTotals(\DOMElement $document, FilingXmlInput $input): void
    {
        $vetaC = $this->mapping->vetaC();
        if ($vetaC === null || $input->controlReturnBase === []) {
            return;
        }

        $slot       = (string) $vetaC['slot'];
        $attributes = [];
        foreach ($vetaC['attributes'] as $attribute => $definition) {
            $sum = 0.0;
            foreach ($definition['rows'] as $row) {
                $sum += (float) ($input->controlReturnBase[(string) $row] ?? 0.0);
            }
            $attributes[(string) $attribute] = $this->money($sum);
        }

        // Slot je dnes vždycky `base`; kdyby přibyl jiný, ať to praskne
        // tady a ne tichým vypsáním základů místo daně.
        if ($slot !== 'base') {
            throw new \DomainException("Věta C: nepodporovaný slot '{$slot}'");
        }

        $this->appendSentence($document, (string) $vetaC['veta'], $attributes);
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }
}
