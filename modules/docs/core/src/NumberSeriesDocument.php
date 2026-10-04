<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Mail\AddressList;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Settings\SettingsStore;

class NumberSeriesDocument extends Document
{
    private const KNOWN_PLACEHOLDERS = ['D', 'C', 'y', 'Y', '3', '4', '5', '6'];
    private const ALLOWED_RESET_SCOPES = ['none', 'fiscal_year'];

    /**
     * Vazby řady na entitu (docTypes[].series_binding → FK sloupec řady).
     * Sdílená mapa s BoundNumberSeriesProvisioner — přidání vazby = jeden
     * záznam tady + sloupec v docs_core_number_series.jsonc.
     *
     * @var array<string, array{table: string, required: string, notAllowed: string, notFound: string, deleted: string}>
     */
    public const BINDINGS = [
        'cash_desk' => [
            'table'      => 'economy_codebooks_cash_desks',
            'required'   => 'Pokladna je pro tento typ dokladu povinná',
            'notAllowed' => 'Pokladna nepatří k tomuto typu dokladu',
            'notFound'   => 'Pokladna neexistuje',
            'deleted'    => 'Pokladna je smazaná',
        ],
        'warehouse' => [
            'table'      => 'economy_codebooks_warehouses',
            'required'   => 'Sklad je pro tento typ dokladu povinný',
            'notAllowed' => 'Sklad nepatří k tomuto typu dokladu',
            'notFound'   => 'Sklad neexistuje',
            'deleted'    => 'Sklad je smazaný',
        ],
    ];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $this->validateBinding($data, $result);

        if (empty($data['name'])) {
            $result->addError('name', 'Název řady je povinný', 'required');
        }
        if (empty($data['doc_type'])) {
            $result->addError('doc_type', 'Typ dokladu je povinný', 'required');
        }
        if (empty($data['doc_number_pattern'])) {
            $result->addError('doc_number_pattern', 'Vzorec čísla dokladu je povinný', 'required');
        }

        $pattern = (string) ($data['doc_number_pattern'] ?? '');

        if (str_contains($pattern, '%C') && empty($data['doc_number_code'])) {
            $result->addError(
                'doc_number_code',
                'Vzorec obsahuje %C — kód řady je povinný',
                'required_for_pattern',
            );
        }

        if ($pattern !== '' && preg_match_all('/%([A-Za-z0-9])/', $pattern, $matches)) {
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, self::KNOWN_PLACEHOLDERS, true)) {
                    $result->addError(
                        'doc_number_pattern',
                        "Neznámý placeholder %{$placeholder}",
                        'unknown_placeholder',
                    );
                    break;
                }
            }
        }

        if (!empty($data['reset_scope'])
            && !in_array($data['reset_scope'], self::ALLOWED_RESET_SCOPES, true)
        ) {
            $result->addError('reset_scope', 'Neplatný typ restartu', 'invalid_value');
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $result->addError(
                'valid_to',
                'Konec platnosti musí být později než začátek',
                'invalid_range',
            );
        }

        $this->validateSender($data, $result);

        return $result;
    }

    /**
     * Odesílatel dokladů řady (#90 D39): prázdná volba = automaticky (NULL),
     * jinak jen adresa, ze které zdroj dat smí odesílat. Jméno odesílatele
     * jde do hlavičky From — bez konců řádků.
     *
     * @param array<string, mixed> $data
     */
    private function validateSender(array &$data, ValidationResult $result): void
    {
        if (array_key_exists('email_from_name', $data)) {
            $name = trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($data['email_from_name'] ?? '')));
            $data['email_from_name'] = $name === '' ? null : $name;
        }

        if (!array_key_exists('email_from', $data)) {
            return;
        }
        $email = trim((string) ($data['email_from'] ?? ''));
        $data['email_from'] = $email === '' ? null : $email;
        if ($email === '') {
            return;
        }

        if (!AddressList::isValid($email)) {
            $result->addError('email_from', 'Adresa odesílatele není platná e-mailová adresa', 'invalid_email');
            return;
        }
        if ($this->db !== null && !$this->allowedSenders()->isAllowed($email)) {
            $result->addError(
                'email_from',
                'Z této adresy zdroj dat odesílat nesmí — vyberte výchozí adresu nebo aktivního odesílatele pošty',
                'sender_not_allowed',
            );
        }
    }

    /** Testovací šev — adresy, ze kterých zdroj dat smí odesílat. */
    protected function allowedSenders(): AllowedSenders
    {
        $connection = new DataSourceConnection($this->db);
        return new AllowedSenders($connection, new SettingsStore($connection));
    }

    /**
     * Vazba řady na entitu podle `series_binding` typu dokladu: vázaný typ
     * má svůj FK povinný a ostatní vazby prázdné; nevázaný typ má všechny
     * vazby prázdné. Odkazovaná entita musí existovat a nebýt smazaná.
     * Bez configu (typ nelze určit) se kontrola přeskočí.
     *
     * @param array<string, mixed> $data
     */
    private function validateBinding(array $data, ValidationResult $result): void
    {
        $docTypes = $this->config?->cfgItem('docs.core.docTypes');
        $docType  = (string) ($data['doc_type'] ?? '');
        if (!is_array($docTypes) || $docType === '' || !is_array($docTypes[$docType] ?? null)) {
            return;
        }

        $binding = $docTypes[$docType]['series_binding'] ?? null;
        if ($binding !== null && !isset(self::BINDINGS[$binding])) {
            $result->addError('doc_type', "Neznámá vazba řady „{$binding}“", 'unknown_binding');
            return;
        }

        foreach (self::BINDINGS as $column => $meta) {
            $value = (int) ($data[$column] ?? 0);

            if ($column !== $binding) {
                if ($value > 0) {
                    $result->addError($column, $meta['notAllowed'], 'binding_not_allowed');
                }
                continue;
            }

            if ($value <= 0) {
                $result->addError($column, $meta['required'], 'required');
                continue;
            }
            if ($this->db === null) {
                continue;
            }
            $row = $this->db->fetch(
                'SELECT [docState] FROM [' . $meta['table'] . '] WHERE [id] = %i',
                $value,
            );
            if ($row === null) {
                $result->addError($column, $meta['notFound'], 'not_found');
            } elseif ((int) $row['docState'] === 90) {
                $result->addError($column, $meta['deleted'], 'invalid_state');
            }
        }
    }
}
