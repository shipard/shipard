<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Detail dokladu jako "textová faktura" (content type `document`):
 * hlavička se state badge, strany ze snapshotů / živě, řádky, DPH
 * rekapitulace, součty (zaokrouhlení, cizí měna) a skupiny příloh
 * (mailové zdrojové + vlastní přílohy dokladu) na konci.
 */
class DocsHeadsViewerDetailTest extends TestCase
{
    private const DOC_STATES = [
        '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1],
        '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3],
    ];

    private const DOC_TYPES = [
        'invno'  => ['name' => 'Faktura vydaná', 'trade_dir' => 1],
        'invni'  => ['name' => 'Faktura přijatá', 'trade_dir' => 2],
        'cmnbkp' => ['name' => 'Účetní doklad', 'trade_dir' => 0],
    ];

    private const PAYMENT_METHODS = [
        '1' => ['name' => 'Převodem'],
    ];

    /** @return array<string, mixed> výchozí hlavička dokladu pro fetchRow */
    private function baseRecord(array $overrides = []): array
    {
        return array_merge([
            'id'                => 7,
            'doc_type'          => 'invni',
            'doc_number'        => '!0000000016',
            'doc_text'          => 'testtest',
            'docState'          => 10,
            'partner'           => null,
            'partner_address'   => null,
            'bank_account'      => null,
            'vat_registration'  => null,
            'supplier_snapshot' => null,
            'customer_snapshot' => null,
            'issue_date'        => '2026-05-28',
            'due_date'          => '2026-06-11',
            'accounting_date'   => '2026-05-28',
            'vat_duzp'          => '2026-05-28',
            'doc_currency'      => 'czk',
            'home_currency'     => 'czk',
            'exchange_rate'     => null,
            'payment_method'    => 1,
            'payment_reference'   => '16',
            'specific_symbol'   => null,
            'constant_symbol'   => null,
            'total_base'        => 6000.0,
            'total_vat'         => 1260.0,
            'total_amount'      => 7260.0,
            'total_rounding'    => 0.0,
            'total_base_dom'    => 0.0,
            'total_vat_dom'     => 0.0,
            'total_amount_dom'  => 0.0,
            'partner_name'      => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $record               hlavička dokladu
     * @param list<array<string, mixed>> $rows           docs_core_rows
     * @param list<array<string, mixed>> $recap          docs_core_vat_recap
     * @param list<array<string, mixed>> $messages       zdrojové zprávy
     * @param array<int, list<array<string, mixed>>> $filesByMessage  message.id → přílohy zprávy (table_id 303)
     * @param list<array<string, mixed>> $docFiles       vlastní přílohy dokladu (table_id 401)
     * @param array<string, mixed> $dibiFetchMap         SQL substring → row data pro živé strany
     * @param list<array<string, mixed>> $attachmentQueries  zachycené dotazy na core_attachments_files (out)
     */
    private function makeViewer(
        array $record,
        array $rows = [],
        array $recap = [],
        array $messages = [],
        array $filesByMessage = [],
        array $docFiles = [],
        array $dibiFetchMap = [],
        array &$attachmentQueries = [],
    ): DocsHeadsViewer {
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchRow')->willReturn($record);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($rows, $recap, $messages, $filesByMessage, $docFiles, &$attachmentQueries): array {
                if (str_contains($sql, 'docs_core_rows')) {
                    return $rows;
                }
                if (str_contains($sql, 'docs_core_vat_recap')) {
                    return $recap;
                }
                if (str_contains($sql, 'core_mail_incoming_messages')) {
                    return $messages;
                }
                if (str_contains($sql, 'core_attachments_files')) {
                    $attachmentQueries[] = ['sql' => $sql, 'params' => $params];
                    $tableId = (int) ($params[0] ?? 0);
                    $recordId = (int) ($params[1] ?? 0);
                    if ($tableId === 303) {
                        return $filesByMessage[$recordId] ?? [];
                    }
                    return $docFiles;
                }
                return [];
            },
        );

        // Živé sestavení stran jde přes PersonSnapshotBuilder/OwnCompanyResolver
        // nad raw Dibi spojením — namapováno přes SQL substring.
        $dibi = $this->createMock(Connection::class);
        $dibi->method('fetch')->willReturnCallback(
            function (mixed ...$args) use ($dibiFetchMap): ?Row {
                $sql = (string) $args[0];
                foreach ($dibiFetchMap as $needle => $data) {
                    if (str_contains($sql, $needle)) {
                        return $data !== null ? new Row($data) : null;
                    }
                }
                return null;
            },
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'docs.core.docStates'      => self::DOC_STATES,
                'docs.core.docTypes'       => self::DOC_TYPES,
                'docs.core.paymentMethods' => self::PAYMENT_METHODS,
                default                    => null,
            },
        );

        $viewer = new DocsHeadsViewer($db, 'docs_core_heads');
        $viewer->setConfig($config);
        return $viewer;
    }

    /** @return array<string, mixed> content jediného overview tabu */
    private function detailContent(DocsHeadsViewer $viewer, int $recordId = 7): array
    {
        $detail = $viewer->renderDetail($recordId);
        $this->assertCount(1, $detail['tabs'], 'Detail má jediný tab overview');
        $this->assertSame('overview', $detail['tabs'][0]['id']);
        return $detail['tabs'][0]['content'];
    }

    // ── Hlavička ────────────────────────────────────────────────────────────

    public function testHeaderCarriesTypeNumberTextAndStateBadge(): void
    {
        $viewer = $this->makeViewer($this->baseRecord(['docState' => 40]));
        $detail = $viewer->renderDetail(7);

        // Hlavička je nově nad taby (generický header ViewerDetail)
        $this->assertSame('!0000000016', $detail['title']);
        $this->assertSame('testtest', $detail['subtitle']);
        $this->assertSame([
            ['label' => 'Faktura přijatá', 'style' => 'neutral'],
            ['label' => 'V pořádku', 'style' => 'done'],
        ], $detail['badges']);
        $this->assertSame('invoice-in', $detail['icon']);

        $content = $detail['tabs'][0]['content'];
        $this->assertSame('document', $content['type']);
        $this->assertArrayNotHasKey('header', $content, 'Hlavička už není v contentu dokumentu');
    }

    // ── Strany ──────────────────────────────────────────────────────────────

    public function testPartiesPreferStoredSnapshots(): void
    {
        $supplier = ['name' => 'Dodavatel s.r.o.', 'company_id' => '11111111'];
        $customer = ['name' => 'My a.s.', 'company_id' => '22222222'];
        $viewer = $this->makeViewer($this->baseRecord([
            'supplier_snapshot' => json_encode($supplier),
            'customer_snapshot' => json_encode($customer),
        ]));
        $content = $this->detailContent($viewer);

        $this->assertSame($supplier, $content['supplier']);
        $this->assertSame($customer, $content['customer']);
    }

    public function testStoredSnapshotPartnerGetsPersonIdOnTradeDirSide(): void
    {
        // Regrese Task A: attachPartnerPersonId volal smazanou privátní
        // resolveTradeDir → fatal u dokladu s partnerem. invni (trade_dir 2):
        // partner = dodavatel → person_id na supplier; invno → customer.
        $supplier = ['name' => 'Dodavatel s.r.o.'];
        $customer = ['name' => 'My a.s.'];

        $viewer = $this->makeViewer($this->baseRecord([
            'partner'           => 5,
            'partner_name'      => 'Dodavatel s.r.o.',
            'supplier_snapshot' => json_encode($supplier),
            'customer_snapshot' => json_encode($customer),
        ]));
        $content = $this->detailContent($viewer);
        $this->assertSame(5, $content['supplier']['person_id']);
        $this->assertArrayNotHasKey('person_id', $content['customer']);

        $viewer = $this->makeViewer($this->baseRecord([
            'doc_type'          => 'invno',
            'partner'           => 5,
            'partner_name'      => 'My a.s.',
            'supplier_snapshot' => json_encode($supplier),
            'customer_snapshot' => json_encode($customer),
        ]));
        $content = $this->detailContent($viewer);
        $this->assertSame(5, $content['customer']['person_id']);
        $this->assertArrayNotHasKey('person_id', $content['supplier']);
    }

    public function testConceptAssemblesPartiesLive(): void
    {
        // invni (trade_dir 2): partner = dodavatel, vlastní firma = odběratel.
        $viewer = $this->makeViewer(
            $this->baseRecord(['partner' => 5]),
            dibiFetchMap: [
                'is_own'                 => ['id' => 9],
                'base_persons_persons'   => [
                    'id' => 5, 'full_name' => 'Česká Tech, s.r.o.',
                    'company_id' => '12345678', 'tax_id' => 'CZ12345678',
                ],
            ],
        );
        $content = $this->detailContent($viewer);

        // Pozn.: mock vrací stejnou osobu pro partnera i vlastní firmu —
        // podstatné je obsazení obou stran a tvar snapshotu.
        $this->assertSame('Česká Tech, s.r.o.', $content['supplier']['name']);
        $this->assertSame('12345678', $content['supplier']['company_id']);
        $this->assertSame('Česká Tech, s.r.o.', $content['customer']['name']);
    }

    public function testTypeWithoutSidesHasNoLiveParties(): void
    {
        // cmnbkp (trade_dir 0 → resolveTradeDir null): strany se živě nestaví
        // (stejně jako snapshoty), i když má hlavička partnera.
        $viewer = $this->makeViewer(
            $this->baseRecord(['doc_type' => 'cmnbkp', 'partner' => 5]),
            dibiFetchMap: [
                'is_own'               => ['id' => 9],
                'base_persons_persons' => ['id' => 5, 'full_name' => 'Partner s.r.o.'],
            ],
        );
        $content = $this->detailContent($viewer);

        $this->assertNull($content['supplier']);
        $this->assertNull($content['customer']);
    }

    public function testMissingOwnCompanyYieldsNullSideWithoutCrash(): void
    {
        // DS bez vlastní firmy (is_own dotaz vrátí null) → strana "my" je null.
        $viewer = $this->makeViewer(
            $this->baseRecord(['partner' => 5]),
            dibiFetchMap: [
                'is_own'               => null,
                'base_persons_persons' => ['id' => 5, 'full_name' => 'Partner s.r.o.'],
            ],
        );
        $content = $this->detailContent($viewer);

        $this->assertSame('Partner s.r.o.', $content['supplier']['name']);
        $this->assertNull($content['customer']);
    }

    // ── Meta, řádky, rekapitulace, součty ───────────────────────────────────

    public function testMetaIsFormattedAndLocalized(): void
    {
        $viewer = $this->makeViewer($this->baseRecord());
        $meta = $this->detailContent($viewer)['meta'];

        $this->assertSame('28. 5. 2026', $meta['issue_date']);
        $this->assertSame('11. 6. 2026', $meta['due_date']);
        $this->assertSame('CZK', $meta['currency']);
        $this->assertNull($meta['exchange_rate'], 'Kurz jen u cizí měny');
        $this->assertSame('Převodem', $meta['payment_method']);
        $this->assertSame('16', $meta['payment_reference']);
        $this->assertNull($meta['specific_symbol']);
    }

    public function testRowsIncludeTextualKindAndFormattedAmounts(): void
    {
        $viewer = $this->makeViewer(
            $this->baseRecord(),
            rows: [
                [
                    'row_kind' => 1, 'order_pos' => 1, 'description' => 'Konzultace',
                    'quantity' => '10.0000', 'unit_shortcut' => 'hod',
                    'unit_price' => '600.00', 'vat_pct' => '21.00', 'total_price' => '6000.00',
                ],
                [
                    'row_kind' => 0, 'order_pos' => 2, 'description' => 'Textová poznámka',
                    'quantity' => null, 'unit_shortcut' => null,
                    'unit_price' => null, 'vat_pct' => null, 'total_price' => null,
                ],
            ],
        );
        $rows = $this->detailContent($viewer)['rows'];

        $this->assertSame([
            'order_pos'   => 1,
            'kind'        => 1,
            'description' => 'Konzultace',
            'quantity'    => '10',
            'unit'        => 'hod',
            'unit_price'  => '600,00',
            'vat_pct'     => '21',
            'total_price' => '6 000,00',
        ], $rows[0]);

        $this->assertSame(0, $rows[1]['kind']);
        $this->assertSame('Textová poznámka', $rows[1]['description']);
        $this->assertNull($rows[1]['quantity']);
        $this->assertNull($rows[1]['total_price']);
    }

    public function testVatRecapAndTotalsWithoutRounding(): void
    {
        $viewer = $this->makeViewer(
            $this->baseRecord(),
            recap: [
                ['vat_pct' => '21.00', 'base' => '6000.00', 'tax' => '1260.00', 'total' => '7260.00'],
            ],
        );
        $content = $this->detailContent($viewer);

        $this->assertSame(
            [['vat_pct' => '21', 'base' => '6 000,00', 'tax' => '1 260,00', 'total' => '7 260,00']],
            $content['vat_recap'],
        );
        $this->assertSame([
            'currency' => 'CZK',
            'base'     => '6 000,00',
            'vat'      => '1 260,00',
            'amount'   => '7 260,00',
            'rounding' => null,
            'dom'      => null,
        ], $content['totals']);
    }

    public function testForeignCurrencyAddsExchangeRateAndDomTotals(): void
    {
        $viewer = $this->makeViewer($this->baseRecord([
            'doc_currency'     => 'eur',
            'exchange_rate'    => '24.500000',
            'total_base'       => 100.0,
            'total_vat'        => 21.0,
            'total_amount'     => 121.0,
            'total_rounding'   => -0.5,
            'total_base_dom'   => 2450.0,
            'total_vat_dom'    => 514.5,
            'total_amount_dom' => 2964.5,
        ]));
        $content = $this->detailContent($viewer);

        $this->assertSame('24,500', $content['meta']['exchange_rate']);
        $this->assertSame('-0,50', $content['totals']['rounding']);
        $this->assertSame([
            'currency' => 'CZK',
            'base'     => '2 450,00',
            'vat'      => '514,50',
            'amount'   => '2 964,50',
        ], $content['totals']['dom']);
    }

    // ── Přílohy ─────────────────────────────────────────────────────────────

    public function testNoAttachmentsOmitsAttachmentsBlock(): void
    {
        $viewer = $this->makeViewer($this->baseRecord());
        $content = $this->detailContent($viewer);

        $this->assertArrayNotHasKey('attachments', $content);
    }

    public function testMailGroupsComeFirstThenDocAttachments(): void
    {
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-A', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => null],
        ];
        $filesByMessage = [
            11 => [
                ['id' => 101, 'name' => 'faktura.pdf', 'file_name' => 'faktura.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf'],
            ],
        ];
        $docFiles = [
            ['id' => 301, 'name' => 'smlouva.pdf', 'file_name' => 'smlouva.pdf', 'file_size' => 4096, 'mime_type' => 'application/pdf'],
        ];

        $viewer = $this->makeViewer(
            $this->baseRecord(),
            messages: $messages,
            filesByMessage: $filesByMessage,
            docFiles: $docFiles,
        );
        $groups = $this->detailContent($viewer)['attachments']['groups'];

        $this->assertCount(2, $groups);

        $this->assertSame('mail', $groups[0]['kind']);
        $this->assertSame('core.mail.incoming', $groups[0]['sourceViewerId']);
        $this->assertSame('MSG-A', $groups[0]['message_id']);
        // Kód mimo vzor MSG-YYYYMMDD-NNNN zůstává v krátkém tvaru beze změny (D8).
        $this->assertSame('MSG-A', $groups[0]['message_code_short']);
        $this->assertSame(11, $groups[0]['message_ndx']);
        $this->assertSame('1. 6. 2026', $groups[0]['received_at']);
        $this->assertSame(
            ['id' => 101, 'name' => 'faktura.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048],
            $groups[0]['attachments'][0],
        );

        $this->assertSame('doc', $groups[1]['kind']);
        $this->assertSame(
            ['id' => 301, 'name' => 'smlouva.pdf', 'mime_type' => 'application/pdf', 'file_size' => 4096],
            $groups[1]['attachments'][0],
        );
    }

    public function testMailGroupCarriesShortCodeForFullPattern(): void
    {
        // Nadpis skupiny ukazuje krátký tvar, plný kód zůstává pro tooltip
        // (tasks/mail-source-message-link.md D9).
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-20260601-0007', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => null],
        ];
        $filesByMessage = [
            11 => [
                ['id' => 101, 'name' => 'faktura.pdf', 'file_name' => 'faktura.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf'],
            ],
        ];

        $viewer = $this->makeViewer($this->baseRecord(), messages: $messages, filesByMessage: $filesByMessage);
        $groups = $this->detailContent($viewer)['attachments']['groups'];

        $this->assertSame('MSG-20260601-0007', $groups[0]['message_id']);
        $this->assertSame('260601-0007', $groups[0]['message_code_short']);
    }

    public function testMessageWithoutAttachmentsIsSkipped(): void
    {
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-A', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => null],
        ];
        $viewer = $this->makeViewer($this->baseRecord(), messages: $messages, filesByMessage: [11 => []]);
        $content = $this->detailContent($viewer);

        $this->assertArrayNotHasKey('attachments', $content);
    }

    public function testRawEmlIsExcludedFromAttachmentQuery(): void
    {
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-A', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => 99],
        ];
        $filesByMessage = [
            11 => [
                ['id' => 101, 'name' => 'faktura.pdf', 'file_name' => 'faktura.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf'],
            ],
        ];

        $attachmentQueries = [];
        $viewer = $this->makeViewer(
            $this->baseRecord(),
            messages: $messages,
            filesByMessage: $filesByMessage,
            attachmentQueries: $attachmentQueries,
        );
        $viewer->renderDetail(7);

        // První dotaz = přílohy zprávy (s vyloučením raw .eml), druhý =
        // vlastní přílohy dokladu (table_id 401).
        $this->assertCount(2, $attachmentQueries);
        $this->assertStringContainsString('AND `id` != %i', $attachmentQueries[0]['sql']);
        $this->assertContains(99, $attachmentQueries[0]['params']);
        $this->assertSame(401, $attachmentQueries[1]['params'][0]);
    }
}
