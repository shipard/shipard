<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\ProformasOut;

use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Zálohová faktura vydaná (FVZ) — `doc_type = 'invpo'` (#79 D1).
 *
 * Výzva k platbě předem: není daňový doklad (`docTypes[].tax_document:
 * false`). DUZP/DPPD a období DPH nuluje `DocDocument` a economy.vat podle
 * atributu typu, ne tahle třída — díky tomu platí i pro přepočet řádků
 * (`DocHeadRecomputer` instancuje base). Per-typ pravidlo je jediné: náš
 * bankovní účet je při Potvrdit povinný stejně jako u FVB — bez něj
 * odběratel neví, kam platit. Účtování na podrozvahu a saldokonto:
 * `tasks/accbal-proformas-out.md`.
 */
class ProformaOutDocument extends DocsHeadsDocument
{
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        $newState = (int) ($data['docState'] ?? 10);

        // Potvrzeno a dál: proforma je hlavně výzva k platbě, účet je nutný.
        if (in_array($newState, [40, 80], true)) {
            if (empty($data['bank_account'])) {
                $result->addError(
                    'bank_account',
                    'Bankovní účet je povinný — partner musí vědět, kam zaplatit.',
                    'required',
                );
            }
        }

        return $result;
    }
}
