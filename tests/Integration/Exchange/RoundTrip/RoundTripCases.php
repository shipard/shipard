<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\RoundTrip;

use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripFixtures as F;

/**
 * Matice případů round-tripu dokladu (tasks/doc-saving-safety-net.md U4).
 * Každý případ staví data dokladu A pro `TableGateway` — tak, jak je pořídí
 * formulář; částky a názvy jsou syntetické, kódy DPH a sazby se berou
 * z konfigurace zdroje dat přes {@see RoundTripFixtures}.
 *
 * Očekávání `gap` nese přesný rozdíl ze skutečného běhu (U5). Když etapa 3
 * nebo 5 mezeru zavře, test to ohlásí a případ se přepne na `holds`.
 */
final class RoundTripCases
{
    /**
     * Mezera: snapshot dodavatele v importním módu skládá applier z payloadu
     * včetně bankovního spojení, formulářová cesta ho bere z adresáře bez něj.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function snapshotBank(): array
    {
        return ['head.supplier_snapshot.bank_account' => [SavedDocumentComparator::ABSENT, [
            'name'           => null,
            'account_number' => F::SUPPLIER_ACCOUNT,
            'iban'           => F::SUPPLIER_IBAN,
            'bic'            => F::SUPPLIER_BIC,
            'currency'       => null,
        ]]];
    }

    /**
     * Mezera: nevyplněný identifikátor partnera je ve snapshotu z adresáře
     * prázdný řetězec, ve snapshotu z payloadu (importní mód) null.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function snapshotEmpty(string ...$keys): array
    {
        $diff = [];
        foreach ($keys as $key) {
            $diff["head.supplier_snapshot.{$key}"] = ['', null];
        }
        return $diff;
    }

    /**
     * @return list<RoundTripCase>
     */
    public static function all(): array
    {
        $issuedBase = RoundTripCase::holds(
            'issued-base',
            'faktura vydaná, tuzemsko, Ze základu, dvě sazby',
            static fn (F $f): array => $f->issuedHead('vydaná ze základu') + ['rows' => [
                $f->row(true, 'práce', 2, 1234.50, $f->vatCode('output', 'domestic', 'standard')),
                $f->row(true, 'publikace', 3, 99.90, $f->vatCode('output', 'domestic', 'reduced')),
            ]],
        );

        $receivedDeclaredRecap = RoundTripCase::gap(
            'received-declared-recap',
            'faktura přijatá, tuzemsko, převzatá rekapitulace s haléřovým rozdílem proti řádkům',
            static function (F $f): array {
                $code = $f->vatCode('input', 'domestic', 'standard');
                // Řádky dávají daň 21,00; dodavatel vykázal 20,99.
                return $f->receivedHead('přijatá s převzatou rekapitulací', ['vat_recap_source' => 1]) + [
                    'rows'     => [$f->row(false, 'služby', 3, 33.33, $code)],
                    'vatRecap' => [['vat_code' => $code, 'base' => 99.99, 'tax' => 20.99, 'total' => 120.98]],
                ];
            },
            ['diff' => self::snapshotBank()],
        );

        return [
            $issuedBase,
            $issuedBase->asConcept(),

            RoundTripCase::holds(
                'issued-total',
                'faktura vydaná, Z celkové částky',
                static fn (F $f): array => $f->issuedHead('vydaná z celkové částky', ['vat_mode' => 2]) + ['rows' => [
                    $f->row(true, 'práce', 1, 1210.00, $f->vatCode('output', 'domestic', 'standard')),
                    $f->row(true, 'publikace', 2, 280.00, $f->vatCode('output', 'domestic', 'reduced')),
                ]],
            ),

            RoundTripCase::holds(
                'issued-cash-rounding',
                'faktura vydaná hrazená hotově, automatické zaokrouhlení',
                static fn (F $f): array => $f->issuedHead('vydaná hotově', [
                    'payment_method'      => 0,
                    'cash_desk'           => $f->cashDeskId(),
                    'total_rounding_auto' => 1,
                ]) + ['rows' => [
                    $f->row(true, 'práce', 1, 100.33, $f->vatCode('output', 'domestic', 'standard')),
                ]],
            ),

            RoundTripCase::gap(
                'issued-rounding-manual',
                'faktura vydaná, automatika vypnutá, ručně zvolený mód zaokrouhlení (nahoru na 1)',
                // 100,50 + 21 % = 121,61 → nahoru 122,00; matematicky vyjde totéž.
                static fn (F $f): array => $f->issuedHead('vydaná s ručním zaokrouhlením', [
                    'total_rounding_auto' => 0,
                    'total_rounding_mode' => RoundingModes::UP_UNIT,
                ]) + ['rows' => [
                    $f->row(true, 'práce', 1, 100.50, $f->vatCode('output', 'domestic', 'standard')),
                ]],
                // Export mód nenese, applier ho odhaduje z částek — matematický má přednost.
                ['diff' => ['head.total_rounding_mode' => [RoundingModes::UP_UNIT, RoundingModes::MATH_UNIT]]],
            ),

            RoundTripCase::holds(
                'issued-corrective',
                'opravný daňový doklad (příznak, blok correction)',
                static function (F $f): array {
                    $code = $f->vatCode('output', 'domestic', 'standard');
                    $originalId = $f->saveDocument(
                        $f->issuedHead('původní doklad k opravě') + ['rows' => [$f->row(true, 'práce', 1, 1000.00, $code)]],
                    );
                    $f->labelDocument($originalId, '@original');
                    return $f->issuedHead('opravný doklad', [
                        'corrective'        => 1,
                        'corrected_doc'     => $originalId,
                        'correction_reason' => F::PREFIX . ' sleva za vadu',
                    ]) + ['rows' => [$f->row(true, 'sleva', -1, 200.00, $code)]];
                },
            ),

            RoundTripCase::holds(
                'issued-foreign-currency',
                'faktura vydaná v cizí měně s kurzem na dokladu',
                static fn (F $f): array => $f->issuedHead('vydaná v cizí měně', [
                    'doc_currency'  => $f->homeCurrency() === 'eur' ? 'usd' : 'eur',
                    'exchange_rate' => 24.565,
                ]) + ['rows' => [
                    $f->row(true, 'práce', 4, 125.25, $f->vatCode('output', 'domestic', 'standard')),
                ]],
            ),

            $receivedDeclaredRecap,
            $receivedDeclaredRecap->asConcept(),

            RoundTripCase::gap(
                'received-eu-services',
                'faktura přijatá, služba z EU, samovyměření',
                static fn (F $f): array => $f->receivedHead('přijatá služba z EU', [
                    'partner'   => $f->person('euSupplier'),
                    'vat_place' => 1,
                ]) + ['rows' => [
                    $f->row(false, 'licence', 1, 5000.00, $f->vatCode('input', 'intracom', 'standard', ['supplyKind' => 'services'])),
                ]],
                ['diff' => self::snapshotEmpty('company_id')],
            ),

            RoundTripCase::gap(
                'received-domestic-rc',
                'faktura přijatá, tuzemské přenesení s předmětem plnění',
                static fn (F $f): array => $f->receivedHead('přijatá v přenesení') + ['rows' => [
                    $f->row(
                        false,
                        'stavební práce',
                        1,
                        8000.00,
                        $f->vatCode('input', 'domestic', 'standard', ['reverseCharge' => true]),
                        ['reverse_charge_code' => $f->reverseChargeSubject()],
                    ),
                ]],
                ['diff' => self::snapshotBank()],
            ),

            RoundTripCase::gap(
                'received-non-payer',
                'faktura přijatá Bez DPH od tuzemského neplátce',
                static fn (F $f): array => $f->receivedHead('přijatá od neplátce', [
                    'partner'  => $f->person('nonPayer'),
                    'vat_mode' => 0,
                ]) + ['rows' => [$f->row(false, 'služby', 2, 750.00, null)]],
                ['diff' => self::snapshotEmpty('tax_id', 'vat_id')],
            ),

            RoundTripCase::gap(
                'received-no-vat-foreign-id',
                'faktura přijatá Bez DPH, dodavatel s DIČ jiného státu',
                static fn (F $f): array => $f->receivedHead('přijatá bez DPH ze zahraničí', [
                    'partner'  => $f->person('euSupplier'),
                    'vat_mode' => 0,
                ]) + ['rows' => [$f->row(false, 'služby', 1, 300.00, null)]],
                // Místo plnění applier znovu odvodí z prefixu DIČ dodavatele (0 tuzemsko → 1 EU).
                ['diff' => ['head.vat_place' => [0, 1]] + self::snapshotEmpty('company_id')],
            ),

            RoundTripCase::gap(
                'received-out-of-scope-row',
                'faktura přijatá s DPH a řádkem „Mimo DPH“, převzatá rekapitulace',
                static function (F $f): array {
                    $code = $f->vatCode('input', 'domestic', 'standard');
                    return $f->receivedHead('přijatá s řádkem mimo DPH', ['vat_recap_source' => 1]) + [
                        'rows' => [
                            $f->row(false, 'služby', 1, 1000.00, $code),
                            $f->row(false, 'vratná záloha na obal', 1, 150.00, $f->outOfScopeCode()),
                        ],
                        'vatRecap' => [['vat_code' => $code, 'base' => 1000.00, 'tax' => 210.00, 'total' => 1210.00]],
                    ];
                },
                ['diff' => self::snapshotBank()],
            ),

            RoundTripCase::gap(
                'received-rate-dates',
                'faktura přijatá, datum sazby na řádcích (vat_rate_source 2), dvě data',
                static function (F $f): array {
                    $code = $f->vatCode('input', 'domestic', 'standard');
                    return $f->receivedHead('přijatá s daty sazeb na řádcích', ['vat_rate_source' => 2]) + ['rows' => [
                        $f->row(false, 'služby dnes', 1, 1000.00, $code, ['vat_rate_date' => F::DATE]),
                        $f->row(false, 'služby dříve', 1, 500.00, $code, ['vat_rate_date' => $f->otherRateDate($code)]),
                    ]];
                },
                ['diff' => self::snapshotBank()],
            ),

            RoundTripCase::gap(
                'received-dimensions',
                'faktura přijatá se střediskem na hlavičce i řádku',
                static fn (F $f): array => $f->receivedHead('přijatá se střediskem', ['cost_center' => $f->costCenter()]) + ['rows' => [
                    $f->row(false, 'služby', 1, 400.00, $f->vatCode('input', 'domestic', 'standard'), ['cost_center' => $f->costCenter()]),
                ]],
                ['diff' => self::snapshotBank()],
            ),

            RoundTripCase::holds(
                'accounting-doc',
                'účetní doklad s kontačními řádky',
                static fn (F $f): array => [
                    'doc_type'        => 'cmnbkp',
                    'number_series'   => $f->seriesId('cmnbkp'),
                    'issue_date'      => F::DATE,
                    'accounting_date' => F::DATE,
                    'doc_currency'    => $f->homeCurrency(),
                    'doc_text'        => F::PREFIX . ' účetní doklad',
                    'rows'            => [
                        ['row_kind' => 1, 'operation' => 'acc.record', 'account' => $f->accountId('518'), 'acc_side' => 0,
                         'total_price' => 1500.00, 'price_calc_mode' => 1, 'description' => F::PREFIX . ' přeúčtování nákladu'],
                        ['row_kind' => 1, 'operation' => 'acc.record', 'account' => $f->accountId('395'), 'acc_side' => 1,
                         'total_price' => 1500.00, 'price_calc_mode' => 1, 'description' => F::PREFIX . ' vnitřní zúčtování'],
                    ],
                ],
            ),

            RoundTripCase::holds(
                'cash-doc',
                'pokladní doklad, příjem',
                static function (F $f): array {
                    $desk = $f->cashDeskId();
                    return [
                        'doc_type'         => 'cash',
                        'cash_dir'         => 1,
                        'cash_desk'        => $desk,
                        'number_series'    => $f->seriesId('cash', $desk),
                        'partner'          => $f->person('customer'),
                        'vat_registration' => $f->vatRegistrationId(),
                        'vat_mode'         => 1,
                        'doc_currency'     => $f->homeCurrency(),
                        'payment_method'   => 0,
                        'issue_date'       => F::DATE,
                        'accounting_date'  => F::DATE,
                        'doc_text'         => F::PREFIX . ' pokladní příjem',
                        'rows'             => [$f->row(true, 'práce', 1, 500.00, $f->vatCode('output', 'domestic', 'standard'))],
                    ];
                },
            ),
        ];
    }
}
