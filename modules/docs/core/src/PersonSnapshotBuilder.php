<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Builds a person snapshot (name, IDs, address, contact, bank account,
 * VAT registration) for a document party.
 *
 * Shared by DocDocument (frozen snapshots written at Confirm) and
 * DocsHeadsViewer (live party assembly for Koncept documents in detail).
 * The output shape is persisted in the supplier_snapshot / customer_snapshot
 * JSON columns — existing stored snapshots must stay compatible with it.
 *
 * Partner bez vybrané adresy (`partner_address` prázdné — doklad z applieru,
 * z akce Vystavit opravný doklad, z periodické fakturace, nebo formulář,
 * kde ji uživatel nevybral) dostane **sídlo**, bez sídla první adresu
 * osoby: {@see buildPartner()}. Bez toho by daňový doklad neměl adresu
 * odběratele. Vlastní strana předává sídlo sama (`OwnCompanyResolver`).
 *
 * Takes a raw \Dibi\Connection because Document subclasses hold one.
 */
final class PersonSnapshotBuilder
{
    private const ADDRESS_TYPE_HEADQUARTERS = 1;

    public function __construct(
        private readonly \Dibi\Connection $db,
    ) {}

    /**
     * Snapshot partnera dokladu: vybraná adresa, bez ní výchozí adresa
     * osoby ({@see defaultAddressId()}).
     *
     * @return array<string, mixed>  empty array when the person doesn't exist
     */
    public function buildPartner(int $personId, mixed $addressId): array
    {
        if ($personId === 0) {
            return [];
        }
        return $this->build(
            $personId,
            !empty($addressId) ? $addressId : $this->defaultAddressId($personId),
            null,
            null,
        );
    }

    /**
     * Výchozí adresa osoby pro doklad: sídlo, bez sídla první adresa
     * v pořadí na osobě (docs/docs-mvp.md, výchozí hodnoty hlavičky). Jen
     * adresy mimo koš a platné k dnešku — stejně jako nabídka adres ve
     * formuláři.
     */
    public function defaultAddressId(int $personId): ?int
    {
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM [base_persons_addresses]
             WHERE [person] = %i AND [docState] <> 90
               AND ([valid_from] IS NULL OR [valid_from] <= CURDATE())
               AND ([valid_to] IS NULL OR [valid_to] >= CURDATE())
             ORDER BY ([address_type] = %i) DESC, [order_pos], [id]
             LIMIT 1',
            $personId,
            self::ADDRESS_TYPE_HEADQUARTERS,
        );
        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>  empty array when the person doesn't exist
     */
    public function build(
        int $personId,
        mixed $addressId,
        mixed $bankAccountId,
        mixed $vatRegistrationId,
    ): array {
        if ($personId === 0) {
            return [];
        }

        $personRow = $this->db->fetch(
            'SELECT * FROM [base_persons_persons] WHERE [id] = %i',
            $personId,
        );
        if ($personRow === null) {
            return [];
        }
        $person = $personRow->toArray();

        $snap = [
            'name'               => (string) ($person['full_name'] ?? ''),
            'company_id'         => $person['company_id']         ?? null,
            'tax_id'             => $person['tax_id']             ?? null,
            'vat_id'             => $person['vat_id']             ?? null,
            'court_registration' => $person['court_registration'] ?? null,
            'contact'            => [
                'email' => $person['email'] ?? null,
                'phone' => $person['phone'] ?? null,
            ],
        ];

        if ($addressId !== null) {
            $addr = $this->db->fetch(
                'SELECT * FROM [base_persons_addresses] WHERE [id] = %i',
                (int) $addressId,
            );
            if ($addr !== null) {
                $snap['address'] = [
                    'street'        => $addr['street']        ?? null,
                    'house_number'  => $addr['house_number']  ?? null,
                    'city'          => $addr['city']          ?? null,
                    'city_part'     => $addr['city_part']     ?? null,
                    'zip'           => $addr['zip']           ?? null,
                    'country'       => $addr['country']       ?? null,
                    'display_block' => $addr['display_block'] ?? null,
                    'display_line'  => $addr['display_line']  ?? null,
                ];
            }
        }

        if ($bankAccountId !== null) {
            $bank = $this->db->fetch(
                'SELECT * FROM [economy_codebooks_bank_accounts] WHERE [id] = %i',
                (int) $bankAccountId,
            );
            if ($bank !== null) {
                $snap['bank_account'] = [
                    'name'           => $bank['name']           ?? null,
                    'account_number' => $bank['account_number'] ?? null,
                    'iban'           => $bank['iban']           ?? null,
                    'bic'            => $bank['bic']            ?? null,
                    'currency'       => $bank['currency']       ?? null,
                ];
            }
        }

        if ($vatRegistrationId !== null) {
            $reg = $this->db->fetch(
                'SELECT * FROM [economy_codebooks_vat_registrations] WHERE [id] = %i',
                (int) $vatRegistrationId,
            );
            if ($reg !== null) {
                $snap['vat_registration'] = [
                    'country' => $reg['country'] ?? null,
                    'vat_id'  => $reg['vat_id']  ?? null,
                ];
            }
        }

        return $snap;
    }
}
