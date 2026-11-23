<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\CanonicalReferences;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;

/**
 * Reference ve formátu dokladu (#166 D30–D39, tasks/doc-format-references.md
 * U1–U4): každé místo je jedno pole s referencí, pokyn hledá jen přes
 * `ReferenceResolver`, nic nezakládá a nenalezená reference je chyba; návrh
 * hledá podle údajů jako dřív a uvedený `ref` má přednost.
 *
 * Harness {@see OutOfScopeHarness}: resolver referencí zná osobu 42
 * (`F00042`, IČO 12345678), položku 18 (`SLUZBA`) a registraci DPH `cz`.
 */
final class DocumentApplierReferencesTest extends TestCase
{
    use OutOfScopeHarness;

    /** @return array<string, array<string, mixed>> */
    private function docTypes(): array
    {
        return [
            'invno' => ['trade_dir' => 1],
            'invni' => ['trade_dir' => 2],
            'cash'  => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            'cmnbkp' => ['trade_dir' => 0],
        ];
    }

    /** Přijatá faktura harnessu bez číselných `totals`; strana a položka se nastavují v testu. */
    private function payload(): array
    {
        return self::withoutChecksum($this->happyPayload());
    }

    private function instruction(array $payload, ?DocumentApplier $applier = null): ApplyResult
    {
        return ($applier ?? $this->buildApplier())->apply($payload, ApplyMode::Instruction);
    }

    /** @return array{severity: ?string, path: ?string} */
    private function finding(ApplyResult $result, string $code): array
    {
        $issue = $this->issue($result->canonical, $code);
        return ['severity' => $issue['severity'] ?? null, 'path' => $issue['path'] ?? null];
    }

    private function assertRejected(ApplyResult $result, string $code, string $path): void
    {
        $this->assertFalse($result->success, "očekávána chyba {$code}");
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $this->assertNull($this->savedHeadsData, 'nic se neuloží');
        $this->assertSame(['severity' => 'error', 'path' => $path], $this->finding($result, $code), json_encode($this->issueCodes($result->canonical)));
    }

    // ── Strana ──────────────────────────────────────────────────────────────

    /** D30, D35: reference sama i objekt s `ref`; id, přirozený klíč, IČO. */
    public function testPartyIsFoundByEveryReferenceForm(): void
    {
        foreach ([42, '@F00042', '@company_id:12345678'] as $reference) {
            foreach ([$reference, ['ref' => $reference, 'name' => 'Dodavatel s.r.o.']] as $supplier) {
                $payload = self::asInstruction($this->payload());
                $payload['supplier'] = $supplier;

                $result = $this->instruction($payload);

                $this->assertTrue($result->success, json_encode([$supplier, $this->issueCodes($result->canonical)]));
                $this->assertSame(42, $this->savedHeadsData['partner']);
                $this->assertSame(
                    ['status' => 'matched', 'matchedId' => 42, 'matchedBy' => 'ref'],
                    $result->canonical['_resolve']['supplier'],
                );
            }
        }
    }

    public function testUnknownPartyReferenceIsAnError(): void
    {
        $payload = self::asInstruction($this->payload());
        $payload['supplier'] = '@F99999';

        $this->assertRejected($this->instruction($payload), 'reference_not_found', 'supplier');
    }

    /** D35: dvě osoby se stejným IČO — nikdy první nalezená. */
    public function testAmbiguousCompanyIdIsAnError(): void
    {
        $payload = self::asInstruction($this->payload());
        $payload['supplier'] = '@company_id:12345678';
        $applier = $this->buildApplier(records: ['base_persons_persons' => ['company_id=12345678' => [42, 43]]] + self::REFERENCE_RECORDS);

        $this->assertRejected($this->instruction($payload, $applier), 'reference_ambiguous', 'supplier');
    }

    /** D9: číselný řetězec není id — gramatiku drží `ReferenceValue`, ne schéma. */
    public function testNumericStringIsAnInvalidReference(): void
    {
        $payload = self::asInstruction($this->payload());
        $payload['supplier'] = '42';

        $this->assertRejected($this->instruction($payload), 'reference_invalid', 'supplier');
    }

    /** D31: strana jen údaji není tiché „bez partnera“ ani hledání podle jména. */
    public function testPartyByDataOnlyIsReferenceMissingAndNothingIsLookedUpOrCreated(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->expects($this->never())->method('resolve');
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        $persons = $this->createMock(TransactionlessTableGateway::class);
        $persons->expects($this->never())->method('saveDocument');

        $payload = self::asInstruction($this->payload());
        unset($payload['supplier']['ref']);
        // Volby zakládání a volby `_resolve` pokyn nečte (D31, D32).
        $payload['applyOptions'] = ['autoCreateMode' => 'liberal'];
        $payload['_resolve'] = ['supplier' => ['userAction' => 'useExisting:42']];

        $result = $this->instruction($payload, $this->buildApplier(party: $party, persons: $persons));

        $this->assertRejected($result, 'reference_missing', 'supplier');
        $this->assertNotContains('unresolved_required', $this->issueCodes($result->canonical));
    }

    /** D33: návrh hledá podle údajů jako dřív; `ref` má přednost a hledání se přeskočí. */
    public function testProposalLooksUpByDataAndPrefersReference(): void
    {
        $byData = $this->buildApplier()->apply($this->happyPayload(), ApplyMode::Proposal);
        $this->assertTrue($byData->success, json_encode($this->issueCodes($byData->canonical)));
        $this->assertSame('companyId', $byData->canonical['_resolve']['supplier']['matchedBy']);

        $party = $this->createMock(PartyResolver::class);
        $party->expects($this->never())->method('resolve');
        $item = $this->createMock(ItemResolver::class);
        $item->expects($this->never())->method('resolve');
        $payload = $this->happyPayload();
        $payload['supplier']['ref'] = '@F00042';
        $payload['rows'][0]['item']['ref'] = '@SLUZBA';

        $byRef = $this->buildApplier(party: $party, item: $item)->apply($payload, ApplyMode::Proposal);

        $this->assertTrue($byRef->success, json_encode($this->issueCodes($byRef->canonical)));
        $this->assertSame('ref', $byRef->canonical['_resolve']['supplier']['matchedBy']);
        $this->assertSame(18, $this->savedHeadsData['rows'][0]['item']);
    }

    /** D33: nenalezený `ref` je chyba i v návrhu — volba uživatele ji nepřebíjí. */
    public function testUnknownReferenceIsAnErrorInProposalToo(): void
    {
        $payload = $this->happyPayload();
        $payload['supplier']['ref'] = '@F99999';
        $payload['_resolve'] = ['supplier' => ['userAction' => 'useExisting:42']];

        $result = $this->buildApplier()->apply($payload, ApplyMode::Proposal);

        $this->assertRejected($result, 'reference_not_found', 'supplier');
    }

    // ── Položka, účet, jednotka ─────────────────────────────────────────────

    public function testItemIsFoundByReference(): void
    {
        foreach ([18, '@SLUZBA', ['ref' => '@SLUZBA', 'name' => 'Konzultace']] as $item) {
            $payload = self::asInstruction($this->payload());
            $payload['rows'][0]['item'] = $item;
            $payload['rows'][0]['description'] = 'Konzultace';

            $result = $this->instruction($payload);

            $this->assertTrue($result->success, json_encode([$item, $this->issueCodes($result->canonical)]));
            $this->assertSame(18, $this->savedHeadsData['rows'][0]['item']);
        }
    }

    public function testItemByNameOnlyIsReferenceMissing(): void
    {
        $payload = self::asInstruction($this->payload());
        $payload['rows'][0]['item'] = ['name' => 'Konzultace'];

        $this->assertRejected($this->instruction($payload), 'reference_missing', 'rows.0.item');
    }

    /** D39: `item.ourCode` je signál návrhu — pokyn ho odmítá, týž payload v návrhu projde. */
    public function testOurCodeIsRejectedInInstructionAndReadInProposal(): void
    {
        $payload = self::asInstruction($this->payload());
        $payload['rows'][0]['item'] = ['ourCode' => 'SLUZBA', 'name' => 'Konzultace'];

        $result = $this->instruction($payload);
        $this->assertRejected($result, 'signal_not_allowed', 'rows.0.item.ourCode');
        $this->assertNotContains('reference_missing', $this->issueCodes($result->canonical), 'jedna chyba na pole');

        $proposal = $this->buildApplier()->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($proposal->success, json_encode($this->issueCodes($proposal->canonical)));
        $this->assertSame(18, $this->savedHeadsData['rows'][0]['item']);
    }

    /** D31: nenalezený účet je v pokynu chyba; holé číslo je signál — návrh jen varuje. */
    public function testRowAccount(): void
    {
        $records = ['economy_accounting_accounts' => ['number=518100' => 9]] + self::REFERENCE_RECORDS;
        $payload = self::asInstruction($this->payload());

        $payload['rows'][0]['account'] = '@518100';
        $result = $this->instruction($payload, $this->buildApplier(records: $records));
        $this->assertTrue($result->success, json_encode($this->issueCodes($result->canonical)));
        $this->assertSame(9, $this->savedHeadsData['rows'][0]['account']);
        $this->assertSame(
            ['number' => '518100', 'matchedId' => 9, 'status' => 'matched'],
            $result->canonical['_resolve']['rows'][0]['account'],
        );

        $payload['rows'][0]['account'] = '@999999';
        $this->assertRejected($this->instruction($payload, $this->buildApplier(records: $records)), 'reference_not_found', 'rows.0.account');

        $payload['rows'][0]['account'] = '518100';
        $this->assertRejected($this->instruction($payload, $this->buildApplier(records: $records)), 'signal_not_allowed', 'rows.0.account');

        // Návrh: holé číslo hledá jako dřív, nenalezené jen varuje.
        $account = $this->createMock(AccountResolver::class);
        $account->expects($this->once())->method('resolve')->with('518100')->willReturn(null);
        $proposal = $this->buildApplier(account: $account)->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($proposal->success, json_encode($this->issueCodes($proposal->canonical)));
        $this->assertSame('warning', $this->issue($proposal->canonical, 'account_not_found')['severity'] ?? null);
    }

    /** D37: pokyn bere jen přesný systémový kód jednotky; návrh zná i zkratky a neznámou jen varuje. */
    public function testUnknownUnitIsAnErrorOnlyInInstruction(): void
    {
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturnCallback(
            static fn (?string $code, bool $systemCodeOnly = false): ResolveResult => $code === 'hour'
                || (!$systemCodeOnly && $code === 'h') ? ResolveResult::matched(3, 'systemCode') : ResolveResult::notFound(),
        );
        $payload = self::asInstruction($this->payload());

        $payload['rows'][0]['unit'] = 'hour';
        $result = $this->instruction($payload, $this->buildApplier(unit: $unit));
        $this->assertTrue($result->success, json_encode($this->issueCodes($result->canonical)));
        $this->assertSame(3, $this->savedHeadsData['rows'][0]['unit']);

        $payload['rows'][0]['unit'] = 'h';
        $this->assertRejected($this->instruction($payload, $this->buildApplier(unit: $unit)), 'unit_not_found', 'rows.0.unit');

        $proposal = $this->buildApplier(unit: $unit)->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($proposal->success);
        $payload['rows'][0]['unit'] = 'sáh';
        $proposal = $this->buildApplier(unit: $unit)->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($proposal->success);
        $this->assertSame('warning', $this->issue($proposal->canonical, 'unit_not_found')['severity'] ?? null);
    }

    // ── Účet partnera ───────────────────────────────────────────────────────

    /** U1: `supplier.bankAccount` jsou data hlavičky; vazba jen přesnou shodou, pokyn účet nezakládá. */
    public function testPartnerBankAccountIsLinkedOnlyOnExactMatchAndNeverCreated(): void
    {
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::canCreate(['iban' => 'CZ6508000000001234567890']));
        $payload = self::asInstruction($this->payload());
        $payload['applyOptions'] = ['autoCreateMode' => 'liberal'];

        $result = $this->instruction($payload, $this->buildApplier(bank: $bank));

        $this->assertTrue($result->success, json_encode($this->issueCodes($result->canonical)));
        $this->assertArrayNotHasKey('partner_bank', $this->savedHeadsData);
        $this->assertSame('1234567890/0100', $this->savedHeadsData['partner_bank_account'], 'údaje účtu jdou do sloupců hlavičky');
        $this->assertArrayNotHasKey('supplierBank', $result->canonical['_resolve']);

        $matched = $this->instruction($payload);
        $this->assertTrue($matched->success);
        $this->assertSame(7, $this->savedHeadsData['partner_bank']);
    }

    // ── Řada, pokladna, vlastní účet ────────────────────────────────────────

    /** DB applieru pro cíle uložení: řady podle id / typu / pokladny. */
    private function targetsDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql, mixed ...$args): ?Row {
            if (!str_contains($sql, 'docs_core_number_series')) {
                return null;
            }
            return match (true) {
                // řada vázaná na pokladnu 2
                str_contains($sql, '[cash_desk] = %i') => (int) ($args[1] ?? 0) === 2 ? new Row(['id' => 11]) : null,
                // řada z reference: 3 je řada přijatých faktur
                str_contains($sql, '[id] = %i') => [$args[0] ?? null, $args[1] ?? null] === [3, 'invni'] ? new Row(['id' => 3]) : null,
                // výchozí řada typu
                default => new Row(['id' => 1]),
            };
        });
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} výsledek a nálezy
     */
    private function saveTargets(array $canonical): array
    {
        $applier = $this->buildApplier($this->targetsDb(), records: [
            'docs_core_number_series'         => ['code=invni' => 3, 'code=invno' => 4, 'code=cash-POK' => 11],
            'economy_codebooks_cash_desks'    => ['code=POK' => 2],
            'economy_codebooks_bank_accounts' => ['code=BU1' => 17],
        ]);
        $issues = [];
        $method = new \ReflectionMethod($applier, 'resolveSaveTargets');
        $args = [$canonical + ['format' => 'shpd.docs.document', 'formatVersion' => '1.0'], &$issues, false];
        return [$method->invokeArgs($applier, $args), $issues];
    }

    public function testNumberSeriesReferenceAndDefault(): void
    {
        [$targets] = $this->saveTargets(['docType' => 'invoiceReceived', 'numberSeries' => '@invni']);
        $this->assertSame([3, null], [$targets['numberSeriesId'], $targets['error']]);

        [$targets] = $this->saveTargets(['docType' => 'invoiceReceived', 'numberSeries' => 3]);
        $this->assertSame(3, $targets['numberSeriesId']);

        [$targets] = $this->saveTargets(['docType' => 'invoiceReceived']);
        $this->assertSame(1, $targets['numberSeriesId'], 'bez pole výchozí řada typu');

        // Řada jiného typu dokladu: resolver ji najde, typ nesedí.
        [$targets] = $this->saveTargets(['docType' => 'invoiceReceived', 'numberSeries' => '@invno']);
        $this->assertSame(['number_series_not_found', 'numberSeries'], [$targets['error']['code'], $targets['error']['path']]);

        [$targets, $issues] = $this->saveTargets(['docType' => 'invoiceReceived', 'numberSeries' => '@neznama']);
        $this->assertSame(['validation_failed', true], [$targets['error']['code'], $targets['error']['reported']]);
        $this->assertSame([['error', 'numberSeries', 'reference_not_found']], array_map(
            static fn (array $i): array => [$i['severity'], $i['path'], $i['code']],
            $issues,
        ));
    }

    /** U3: u typu vázaného na pokladnu určuje řadu pokladna; uvedená jiná řada je chyba. */
    public function testCashDeskBoundTypeTakesSeriesFromCashDesk(): void
    {
        $cash = ['docType' => 'cashDocument', 'cashDirection' => 1, 'cashDesk' => '@POK'];

        [$targets] = $this->saveTargets($cash);
        $this->assertSame([11, 2, null], [$targets['numberSeriesId'], $targets['cashDeskId'], $targets['error']]);

        [$targets] = $this->saveTargets($cash + ['numberSeries' => '@cash-POK']);
        $this->assertSame([11, null], [$targets['numberSeriesId'], $targets['error']], 'táž řada není rozpor');

        [$targets] = $this->saveTargets($cash + ['numberSeries' => '@invno']);
        $this->assertSame(['number_series_conflict', 'numberSeries'], [$targets['error']['code'], $targets['error']['path']]);

        [$targets, $issues] = $this->saveTargets(['cashDesk' => '@XXX'] + $cash);
        $this->assertTrue($targets['error']['reported']);
        $this->assertSame([['cashDesk', 'reference_not_found']], array_map(static fn (array $i): array => [$i['path'], $i['code']], $issues));
    }

    public function testOwnBankAccountReference(): void
    {
        [$targets] = $this->saveTargets(['docType' => 'invoiceIssued', 'payment' => ['ownBankAccount' => '@BU1']]);
        $this->assertSame([17, null], [$targets['ownBankAccountId'], $targets['error']]);

        [$targets, $issues] = $this->saveTargets([
            'docType' => 'invoiceIssued', 'numberSeries' => '@neznama', 'payment' => ['ownBankAccount' => '@BU9'],
        ]);
        $this->assertTrue($targets['error']['reported']);
        $this->assertSame(['numberSeries', 'payment.ownBankAccount'], array_column($issues, 'path'), 'nález za každou referenci');
    }

    // ── Partner účetního a pokladního dokladu (tasks/doc-format-partner-fields.md) ──

    /** Osoby 42 a 77, účty kontace a pokladna pro doklady s polem `partner`. */
    private const PARTNER_RECORDS = [
        'base_persons_persons'         => ['person_id=F00042' => 42, 'company_id=12345678' => 42, 'person_id=F00077' => 77],
        'economy_accounting_accounts'  => ['number=518100' => 195, 'number=321100' => 207],
        'economy_codebooks_cash_desks' => ['code=POK' => 2],
    ] + self::REFERENCE_RECORDS;

    /** @return array<string, mixed> */
    private function accountingPayload(): array
    {
        return [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0',
            'docType' => 'accountingDocument',
            'dates' => ['issueDate' => '2026-06-10', 'accountingDate' => '2026-06-10'],
            'rows' => [
                ['operation' => 'acc.record', 'account' => '@518100', 'accSide' => 'debit', 'totalPrice' => 1000.0],
                ['operation' => 'acc.record', 'account' => '@321100', 'accSide' => 'credit', 'totalPrice' => 1000.0, 'paymentReference' => 'VS123'],
            ],
        ];
    }

    /** DB harnessu, ve které volba `useExisting:<id>` najde živou osobu. */
    private function dbWithPersons(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql, mixed ...$args): ?Row {
            if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                return new Row(['id' => 5, 'country' => 'cz']);
            }
            return ($args[0] ?? null) === 'base_persons_persons' ? new Row(['id' => $args[1]]) : null;
        });
        $db->method('fetchAll')->willReturn([]);
        $db->method('getInsertId')->willReturn(0);
        return $db;
    }

    /** D30: pole `partner` na hlavičce i řádku účetního dokladu — reference sama i objekt s `ref`. */
    public function testAccountingDocumentPartnerFields(): void
    {
        $payload = $this->accountingPayload();
        $payload['partner'] = ['ref' => '@F00077', 'name' => 'Partner hlavičky s.r.o.'];
        $payload['rows'][1]['partner'] = 42;

        $result = $this->instruction($payload, $this->buildApplier(records: self::PARTNER_RECORDS));

        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame(77, $this->savedHeadsData['partner']);
        $this->assertArrayNotHasKey('partner', $this->savedHeadsData['rows'][0]);
        $this->assertSame(42, $this->savedHeadsData['rows'][1]['partner']);
        $this->assertSame('ref', $result->canonical['_resolve']['partner']['matchedBy']);
        $this->assertSame(42, $result->canonical['_resolve']['rows'][1]['partner']['matchedId']);
        $this->assertSame(['ref' => 42], $result->canonical['rows'][1]['partner'], 'odpověď vrací rozbalený tvar');
    }

    /** D31: nenalezená reference partnera je chyba, partner jen údaji `reference_missing`. */
    public function testUnknownOrDataOnlyPartnerIsAnErrorInInstruction(): void
    {
        $payload = $this->accountingPayload();
        $payload['rows'][1]['partner'] = '@F99999';
        $this->assertRejected(
            $this->instruction($payload, $this->buildApplier(records: self::PARTNER_RECORDS)),
            'reference_not_found',
            'rows.1.partner',
        );

        $payload = $this->accountingPayload();
        $payload['partner'] = ['name' => 'Partner s.r.o.', 'companyId' => '12345678'];
        $party = $this->createMock(PartyResolver::class);
        $party->expects($this->never())->method('resolve');
        $this->assertRejected(
            $this->instruction($payload, $this->buildApplier(records: self::PARTNER_RECORDS, party: $party)),
            'reference_missing',
            'partner',
        );
    }

    /** D32: pokyn `_resolve` nečte — volba partnera se neuplatní, payload projde s jedním info nálezem. */
    public function testResolveBlockIsIgnoredInInstruction(): void
    {
        $payload = $this->accountingPayload();
        $payload['_resolve'] = [
            'partner' => ['userAction' => 'useExisting:77'],
            'rows'    => [1 => ['partner' => ['userAction' => 'useExisting:42']]],
        ];

        $result = $this->instruction($payload, $this->buildApplier(db: $this->dbWithPersons(), records: self::PARTNER_RECORDS));

        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('partner', $this->savedHeadsData);
        $this->assertArrayNotHasKey('partner', $this->savedHeadsData['rows'][1]);
        $this->assertSame(['severity' => 'info', 'path' => '_resolve'], $this->finding($result, 'resolve_ignored'));
        $this->assertCount(1, $this->issues($result->canonical, 'resolve_ignored'));

        // Bez bloku žádný nález; validate() ho hlásí stejně jako apply.
        $this->assertNull($this->issue($this->instruction($this->accountingPayload(), $this->buildApplier(records: self::PARTNER_RECORDS))->canonical, 'resolve_ignored'));
        $validated = $this->buildApplier(records: self::PARTNER_RECORDS)->validate($payload);
        $this->assertTrue($validated->success);
        $this->assertSame('info', $this->issue($validated->canonical, 'resolve_ignored')['severity'] ?? null);
    }

    /** U3: v návrhu volby partnera fungují dál; uvedené pole má přednost. */
    public function testProposalReadsPartnerPinsAndPrefersTheField(): void
    {
        $pins = [
            'partner' => ['userAction' => 'useExisting:77'],
            'rows'    => [1 => ['partner' => ['userAction' => 'useExisting:77']]],
        ];

        $payload = $this->accountingPayload() + ['_resolve' => $pins];
        $result = $this->buildApplier(db: $this->dbWithPersons(), records: self::PARTNER_RECORDS)->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame(77, $this->savedHeadsData['partner'], 'volba hlavičky');
        $this->assertSame(77, $this->savedHeadsData['rows'][1]['partner'], 'volba řádku');
        $this->assertNull($this->issue($result->canonical, 'resolve_ignored'));

        $payload['partner'] = '@F00042';
        $payload['rows'][1]['partner'] = ['ref' => 42];
        $result = $this->buildApplier(db: $this->dbWithPersons(), records: self::PARTNER_RECORDS)->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($result->success, json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame(42, $this->savedHeadsData['partner'], 'pole má přednost před volbou');
        $this->assertSame(42, $this->savedHeadsData['rows'][1]['partner']);

        // Partner jen údaji: návrh podle nich nehledá, pole se s varováním nepoužije a platí volba.
        $payload['partner'] = ['name' => 'Partner s.r.o.'];
        $result = $this->buildApplier(db: $this->dbWithPersons(), records: self::PARTNER_RECORDS)->apply($payload, ApplyMode::Proposal);
        $this->assertTrue($result->success);
        $this->assertSame(['severity' => 'warning', 'path' => 'partner'], $this->finding($result, 'reference_missing'));
        $this->assertSame(77, $this->savedHeadsData['partner']);
    }

    /** R3: `partner` na faktuře je v pokynu chyba; návrh pole s varováním ignoruje a partnerem zůstává strana. */
    public function testPartnerOnInvoiceIsRejectedInInstructionAndIgnoredInProposal(): void
    {
        $payload = self::asInstruction($this->payload());
        $payload['partner'] = '@F00077';
        $this->assertRejected(
            $this->instruction($payload, $this->buildApplier(records: self::PARTNER_RECORDS)),
            'partner_not_supported',
            'partner',
        );

        $proposal = $this->happyPayload();
        $proposal['partner'] = '@F00077';
        $result = $this->buildApplier(records: self::PARTNER_RECORDS)->apply($proposal, ApplyMode::Proposal);
        $this->assertTrue($result->success, json_encode($this->issueCodes($result->canonical)));
        $this->assertSame('warning', $this->issue($result->canonical, 'partner_not_supported')['severity'] ?? null);
        $this->assertSame(42, $this->savedHeadsData['partner'], 'partnerem je strana');
        $this->assertArrayNotHasKey('partner', $result->canonical['_resolve']);
    }

    /** R1: u pokladního dokladu má pole přednost před stranou; dvě různé osoby pokyn neuloží. */
    public function testCashDocumentPartnerFieldAndConflictWithParty(): void
    {
        $cash = [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0',
            'docType' => 'cashDocument', 'cashDirection' => 1, 'cashDesk' => '@POK',
            'dates' => ['issueDate' => '2026-06-10', 'accountingDate' => '2026-06-10'],
            'rows' => [['operation' => 'payment.receivable', 'totalPrice' => 500.0, 'paymentReference' => '2026000011', 'partner' => '@F00042']],
        ];
        $resolve = fn (array $canonical, ApplyMode $mode): array => (function () use ($canonical, $mode): array {
            $applier = $this->buildApplier(records: self::PARTNER_RECORDS);
            $issues = [];
            // Reference jen hodnotou rozbaluje applier před resolve ({@see CanonicalReferences::expand()}).
            $canonical = CanonicalReferences::expand($canonical);
            $resolved = (new \ReflectionMethod($applier, 'resolveAll'))->invokeArgs($applier, [$canonical, &$issues, $mode]);
            $discarded = [];
            $plan = (new \ReflectionMethod($applier, 'reconcile'))->invokeArgs($applier, [$resolved, [], &$discarded, []]);
            return [$plan, array_column($issues, 'code', 'path')];
        })();

        [$plan, $issues] = $resolve($cash + ['partner' => '@F00077'], ApplyMode::Instruction);
        $this->assertSame([], $issues);
        $this->assertSame(77, $plan['resolvedHeadPartner']);
        $this->assertSame([0 => 42], $plan['resolvedRowPartners']);

        // Tatáž osoba polem i stranou rozpor není.
        [$plan, $issues] = $resolve($cash + ['partner' => '@F00042', 'customer' => ['ref' => 42]], ApplyMode::Instruction);
        $this->assertSame([], $issues);
        $this->assertSame([42, 42], [$plan['resolvedHeadPartner'], $plan['resolvedCustomer']]);

        [, $issues] = $resolve($cash + ['partner' => '@F00077', 'customer' => ['ref' => 42]], ApplyMode::Instruction);
        $this->assertSame(['partner' => 'partner_conflict'], $issues);

        // Návrh: pole má přednost jako dřív volba, rozpor se nehlásí.
        [$plan, $issues] = $resolve($cash + ['partner' => '@F00077', 'customer' => ['ref' => 42]], ApplyMode::Proposal);
        $this->assertSame([], $issues);
        $this->assertSame(77, $plan['resolvedHeadPartner']);
    }

    // ── Importní mód ────────────────────────────────────────────────────────

    /** D34: data vedle `ref` jsou v importním módu dobový snapshot; strana jen referencí ho bere z adresáře. */
    public function testImportSnapshotComesFromPayloadDataOrFromDirectory(): void
    {
        $queries = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (string $sql) use (&$queries): ?Row {
            $queries[] = $sql;
            return str_contains($sql, 'base_persons_persons')
                ? new Row(['id' => 42, 'full_name' => 'Dodavatel z adresáře a.s.', 'company_id' => '12345678'])
                : null;
        });
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchSingle')->willReturn(null);
        $applier = $this->buildApplier($db);
        $snapshot = static fn (array $supplier): ?array => (new \ReflectionMethod($applier, 'buildImportPartnerSnapshot'))
            ->invoke($applier, ['selfParty' => 'customer', 'supplier' => $supplier], 'invni', 0, 42);

        $fromPayload = $snapshot(['ref' => 42, 'name' => 'Dodavatel s.r.o.', 'companyId' => '12345678']);
        $this->assertSame('Dodavatel s.r.o.', $fromPayload['name'], 'dobová data z payloadu');
        $this->assertSame([], $queries, 'adresář se nečte');

        $fromDirectory = $snapshot(['ref' => 42]);
        $this->assertSame('Dodavatel z adresáře a.s.', $fromDirectory['name'] ?? null, 'bez dat snapshot z adresáře, ne prázdný');

        // Pokladní doklad s polem `partner` (D30): dobová data nese pole; účetní doklad snapshot nemá.
        $build = new \ReflectionMethod($applier, 'buildImportPartnerSnapshot');
        $fromPartner = $build->invoke($applier, [
            'docType' => 'cashDocument', 'cashDirection' => 2,
            'partner' => ['ref' => 42, 'name' => 'Partner pokladny s.r.o.', 'companyId' => '12345678'],
        ], 'cash', 2, 42);
        $this->assertSame('Partner pokladny s.r.o.', $fromPartner['name'] ?? null);
        $this->assertNull($build->invoke($applier, [
            'docType' => 'accountingDocument', 'partner' => ['ref' => 42, 'name' => 'Partner s.r.o.'],
        ], 'cmnbkp', 0, 42));
    }
}
