<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Document\ValidationError;

/**
 * Kontrola podání **před** generováním XML (issue #55, X5): co úřad
 * vyžaduje a co by jinak spadlo až na daňovém portálu — nebo, hůř, prošlo
 * jako neúplné podání.
 *
 * Vrací field-level chyby (`header.<pole>`), aby je formulář podání ukázal
 * u příslušného inputu. Doplňuje ji validace proti XSD (`EpoXsdValidator`),
 * která hlídá strukturu; tady se hlídá to, co schéma neumí:
 *
 * - povinná pole podle typu subjektu (PO → obchodní jméno, FO → jméno),
 * - masky a délky, které XSD nechává volné (PSČ, telefon, kód zástupce),
 * - stát, který má v číselníku Země daňového portálu název (XML ho
 *   vypisuje názvem, ne kódem),
 * - datum zjištění důvodů u forem, které bez něj nedávají smysl (D/E/N),
 * - zdaňovací období, které nejde vyjádřit měsícem ani čtvrtletím,
 * - rozsah hodnot řádků.
 *
 * **Členství v číselnících se tu nekontroluje** — hlavička je strukturované
 * pole a `StructuredFieldValidator` enum ověřil už při uložení; neznámý
 * finanční úřad se do snapshotu nedostane.
 */
final class FilingXmlValidator
{
    /** Formy podání, u kterých je datum zjištění důvodů povinné. */
    private const DATE_FOUND_REQUIRED_FORMS = ['D', 'E', 'N'];

    /** `xs:decimal totalDigits="14"` u hodnot řádků. */
    private const MAX_VALUE = 1.0e14;

    private const PHONE_MAX = 14;

    /** Kód podepisující osoby: číslice s volitelným písmenem (`4a`, `4b`). */
    private const SIGNATORY_CODE_PATTERN = '/^[0-9][a-z]?$/';

    public function __construct(private readonly VatXmlMapping $mapping) {}

    /**
     * @return list<ValidationError> prázdné pole = podání lze vygenerovat
     */
    public function validate(FilingXmlInput $input): array
    {
        $errors = [];

        $this->validateSubject($input, $errors);
        $this->validateOffice($input, $errors);
        $this->validateContact($input, $errors);
        $this->validateSignatory($input, $errors);
        $this->validateFilingDetails($input, $errors);
        $this->validatePeriod($input, $errors);
        $this->validateRowValues($input, $errors);
        $this->validateControlRows($input, $errors);
        $this->validateRecapRows($input, $errors);

        return $errors;
    }

    /** Hodí výjimku, když podání není v pořádku. */
    public function assertValid(FilingXmlInput $input): void
    {
        $errors = $this->validate($input);
        if ($errors !== []) {
            throw new FilingXmlValidationException($errors);
        }
    }

    // ── Věta P ──────────────────────────────────────────────────────────────

    /** @param list<ValidationError> $errors */
    private function validateSubject(FilingXmlInput $input, array &$errors): void
    {
        $type = (string) $this->value($input, 'typ_ds');
        if ($type === '') {
            $this->error($errors, 'typ_ds', 'Vyplňte typ daňového subjektu.', 'required');
        } elseif (!in_array($type, ['P', 'F'], true)) {
            $this->error($errors, 'typ_ds', "Neznámý typ daňového subjektu '{$type}'.", 'invalid_value');
        }

        $dic = EpoXmlFormat::taxNumberDigits($this->value($input, 'dic'));
        if ($dic === null) {
            $this->error($errors, 'dic', 'Podání nemá DIČ — doplňte ho na registraci k DPH.', 'required');
        } elseif (strlen($dic) > 10) {
            $this->error($errors, 'dic', 'DIČ smí mít nejvýš 10 číslic.', 'too_long');
        }

        // Právnická osoba se podepisuje obchodním jménem, fyzická jménem.
        if ($type === 'P' && $this->value($input, 'zkrobchjm') === null) {
            $this->error($errors, 'zkrobchjm', 'Právnická osoba musí mít vyplněné obchodní jméno.', 'required');
        }
        if ($type === 'F') {
            foreach (['prijmeni' => 'příjmení', 'jmeno' => 'jméno'] as $field => $label) {
                if ($this->value($input, $field) === null) {
                    $this->error($errors, $field, "Fyzická osoba musí mít vyplněné {$label}.", 'required');
                }
            }
        }
    }

    /** @param list<ValidationError> $errors */
    private function validateOffice(FilingXmlInput $input, array &$errors): void
    {
        if ($this->value($input, 'c_ufo') === null) {
            $this->error($errors, 'c_ufo', 'Vyberte finanční úřad, kterému se podává.', 'required');
        }
        foreach (['c_ufo', 'c_pracufo', 'c_pop', 'c_okec'] as $field) {
            $value = $this->value($input, $field);
            if ($value !== null && preg_match('/^\d+$/', (string) $value) !== 1) {
                $this->error($errors, $field, 'Hodnota musí být číslo.', 'invalid_value');
            }
        }
    }

    /** @param list<ValidationError> $errors */
    private function validateContact(FilingXmlInput $input, array &$errors): void
    {
        $psc     = $this->value($input, 'psc');
        $country = strtoupper((string) ($this->value($input, 'stat') ?? 'CZ'));
        if ($psc !== null && in_array($country, ['CZ', ''], true)) {
            $digits = preg_replace('/\s+/', '', (string) $psc);
            if (preg_match('/^\d{5}$/', (string) $digits) !== 1) {
                $this->error($errors, 'psc', 'České PSČ má pět číslic.', 'invalid_value');
            }
        }

        // Stát jde do XML jako název z číselníku Země; kód bez názvu by
        // shodil až writer bez vazby na pole (#55 F3-3).
        foreach ($this->mapping->countryNameFields() as $field) {
            $code = $this->value($input, $field);
            if ($code !== null && $this->mapping->countryName($code) === null) {
                $this->error(
                    $errors,
                    $field,
                    "Stát '{$code}' není v číselníku zemí daňového portálu — zkontrolujte kód,"
                    . ' nebo spusťte ds-upgrade (world.cz.epoCountries).',
                    'invalid_value',
                );
            }
        }
        foreach (['c_telef', 'sest_telef'] as $field) {
            $value = $this->value($input, $field);
            if ($value !== null && mb_strlen((string) $value) > self::PHONE_MAX) {
                $this->error($errors, $field, 'Telefon smí mít nejvýš 14 znaků.', 'too_long');
            }
        }
    }

    /** @param list<ValidationError> $errors */
    private function validateSignatory(FilingXmlInput $input, array &$errors): void
    {
        $code = $this->value($input, 'zast_kod');
        if ($code !== null && preg_match(self::SIGNATORY_CODE_PATTERN, (string) $code) !== 1) {
            $this->error($errors, 'zast_kod', "Neplatný kód podepisující osoby '{$code}'.", 'invalid_value');
        }

        $ic = $this->value($input, 'zast_ic');
        if ($ic !== null && preg_match('/^\d{1,10}$/', (string) $ic) !== 1) {
            $this->error($errors, 'zast_ic', 'IČ zástupce smí být jen číslo, nejvýš desetimístné.', 'invalid_value');
        }

        // Zástupce buď je, nebo není: typ bez kódu (a naopak) je půlka údaje.
        $type = $this->value($input, 'zast_typ');
        if ($type !== null && $code === null) {
            $this->error($errors, 'zast_kod', 'U podepisující osoby vyplňte i její kód.', 'required');
        }
        if ($code !== null && $type === null) {
            $this->error($errors, 'zast_typ', 'U podepisující osoby vyplňte i její typ.', 'required');
        }
    }

    // ── Věta D ──────────────────────────────────────────────────────────────

    /** @param list<ValidationError> $errors */
    private function validateFilingDetails(FilingXmlInput $input, array &$errors): void
    {
        $header = $this->mapping->header();

        // Typ podávající osoby je povinný jen tam, kde ho věta D má.
        if (in_array('typ_platce', $header['vetaDFields'] ?? [], true)
            && $this->value($input, 'typ_platce') === null
        ) {
            $this->error($errors, 'typ_platce', 'Vyberte typ podávající osoby.', 'required');
        }
        if (in_array('c_okec', $header['vetaDFields'] ?? [], true)
            && $this->value($input, 'c_okec') === null
        ) {
            $this->error($errors, 'c_okec', 'Vyplňte kód převažující činnosti (NACE).', 'required');
        }

        $forma = $this->mapping->forma($input->filingKind, $input->previousKind);
        if (in_array($forma, self::DATE_FOUND_REQUIRED_FORMS, true)
            && in_array('d_zjist', $header['derived'] ?? [], true)
            && ($input->dateFound === null || $input->dateFound === '')
        ) {
            $errors[] = new ValidationError(
                'date_found',
                'U tohoto druhu podání je povinné datum zjištění důvodů pro podání.',
                'required',
            );
        }
    }

    /** @param list<ValidationError> $errors */
    private function validatePeriod(FilingXmlInput $input, array &$errors): void
    {
        if (!$input->period->isComplete()) {
            $errors[] = new ValidationError(
                ValidationError::FIELD_FORM,
                'Rozsah daňového tvrzení nejde vyjádřit měsícem ani čtvrtletím —'
                . ' bez toho podání úřad nezpracuje.',
                'invalid_period',
            );
        }
    }

    /** @param list<ValidationError> $errors */
    private function validateRowValues(FilingXmlInput $input, array &$errors): void
    {
        foreach ($input->returnRows as $row => $values) {
            foreach ($values as $slot => $value) {
                if (abs((float) $value) >= self::MAX_VALUE) {
                    $errors[] = new ValidationError(
                        ValidationError::FIELD_FORM,
                        "Hodnota řádku {$row} ({$slot}) je mimo rozsah, který formulář dovolí.",
                        'out_of_range',
                    );
                }
            }
        }
    }

    // ── Řádky hlášení ───────────────────────────────────────────────────────

    /**
     * Řádky kontrolního hlášení: co sekce vyžaduje (`required` v configu),
     * musí být vyplněné. Sázková pásma se nekontrolují — nula je legitimní
     * hodnota a povinné atributy se vypisují i s ní (`alwaysEmit`).
     *
     * Chyba se váže na formulář, ne na pole hlavičky: opravit ji jde jen
     * na dokladu, proto ho hláška jmenuje.
     *
     * @param list<ValidationError> $errors
     */
    private function validateControlRows(FilingXmlInput $input, array &$errors): void
    {
        $sections = $this->mapping->sections();

        foreach ($input->controlRows as $row) {
            $section    = (string) ($row['section'] ?? '');
            $definition = $sections[$section] ?? null;
            if ($definition === null) {
                $errors[] = new ValidationError(
                    ValidationError::FIELD_FORM,
                    "Snapshot obsahuje řádek neznámé sekce '{$section}'.",
                    'unknown_section',
                );
                continue;
            }

            foreach ($definition['required'] ?? [] as $key) {
                $missing = match ((string) $key) {
                    'vatId'      => self::isBlank($row['partner_vat_id'] ?? null),
                    'evidNumber' => self::isBlank($row['doc_number'] ?? null),
                    'date'       => self::isBlank($row['vat_dppd'] ?? null),
                    'kodPredPl'  => self::isBlank($row['reverse_charge_code'] ?? null),
                    default      => false, // pásma: nula je hodnota
                };
                if ($missing) {
                    $errors[] = new ValidationError(
                        ValidationError::FIELD_FORM,
                        sprintf(
                            'Kontrolní hlášení, sekce %s, doklad %s: chybí %s.',
                            $section,
                            (string) ($row['doc_number'] ?? '?'),
                            self::LABELS[(string) $key] ?? (string) $key,
                        ),
                        'incomplete_row',
                    );
                }
            }
        }
    }

    /**
     * Řádky souhrnného hlášení: bez kódu státu a DIČ pořizovatele je řádek
     * nepodatelný (popis struktury je označuje za povinné mimo storno).
     *
     * @param list<ValidationError> $errors
     */
    private function validateRecapRows(FilingXmlInput $input, array &$errors): void
    {
        $required = $this->mapping->row()['required'] ?? [];
        if ($required === []) {
            return;
        }

        foreach ($input->recapRows as $row) {
            [$country, $number] = EpoXmlFormat::euVatId($row['partner_vat_id'] ?? null);

            foreach ($required as $key) {
                $missing = match ((string) $key) {
                    'country' => $country === null,
                    'vatId'   => $number === null,
                    default   => false, // kód plnění, počet a hodnota nese snapshot vždy
                };
                if ($missing) {
                    $errors[] = new ValidationError(
                        ValidationError::FIELD_FORM,
                        sprintf(
                            'Souhrnné hlášení: DIČ pořizovatele „%s" nejde rozdělit na kód státu'
                            . ' a číslo registrace.',
                            (string) ($row['partner_vat_id'] ?? ''),
                        ),
                        'incomplete_row',
                    );
                    break;
                }
            }
        }
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** Popisky chybějících částí řádku do hlášky. */
    private const LABELS = [
        'vatId'      => 'DIČ protistrany',
        'evidNumber' => 'evidenční číslo dokladu',
        'date'       => 'datum plnění',
        'kodPredPl'  => 'kód předmětu plnění',
    ];

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_scalar($value) && trim((string) $value) === '');
    }

    /** Neprázdná hodnota pole hlavičky, jinak `null`. */
    private function value(FilingXmlInput $input, string $field): mixed
    {
        $value = $input->header[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        return is_string($value) ? trim($value) : $value;
    }

    /** @param list<ValidationError> $errors */
    private function error(array &$errors, string $field, string $message, string $code): void
    {
        $errors[] = new ValidationError('header.' . $field, $message, $code);
    }
}
