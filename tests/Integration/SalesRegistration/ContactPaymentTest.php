<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\SalesRegistration;

use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalContributorLoader;
use Shipard\Api\OpenItemLookupLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Module\Docs\Core\PartnerBalanceResolver;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\CashPaymentLookup;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentOutcome;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentService;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\PaymentTime;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocEvaluation;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Sales\DocsHeadsSalesHandler;
use Shipard\Module\Economy\SalesRegistration\Sales\ReconcileScanner;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kontaktní platba proti skutečné databázi (#171 fáze 3, „Hotovo když“):
 * faktura vydaná Převodem V pořádku → akce → tržba s časem platby; zrušení →
 * záporná tržba s časem zrušení; příznak ve formuláři v Konceptu; zálohová
 * faktura Kartou; odmítnuté způsoby úhrady; doklad vystavený před začátkem
 * registrace; varování D8c; záchranná síť po akci na dokladu s přechodem
 * starším než její okno.
 *
 * Potřebuje zdroj dat se zapnutým doplňkem `economy.salesRegistration`,
 * právě jednou platnou registrací `cz.eet2` V pořádku s výchozí jednotkou,
 * řadami faktur vydaných a zálohových faktur vydaných — jinak se přeskočí.
 * Ven nic neposílá: handler dokladů běží bez okamžitého odeslání
 * ({@see NoSendSalesHandler}) a služba kontaktní platby bez fronty.
 */
class ContactPaymentTest extends IntegrationTestCase
{
    private const HEADS = 'docs_core_heads';
    private const SALES = 'economy_sales_registration_sales';
    private const ATTEMPTS = 'economy_sales_registration_attempts';

    private ?TableGateway $gateway = null;
    private ?ConfigRuntime $configRuntime = null;
    private \DateTimeZone $saleZone;
    private string $validFrom = '';

    /** @var list<int> založené doklady — úklid */
    private array $headIds = [];

    /** @var array<string, int> typ dokladu → id řady */
    private array $series = [];

    private int $partner = 0;
    private int $bankAccount = 0;
    private int $vatRegistration = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('economy.salesRegistration', $this->dsConfig->getModules(), true)) {
            $this->markTestSkipped('Zdroj dat nemá zapnutý doplněk economy.salesRegistration');
        }
        $today = date('Y-m-d');
        $registrations = $this->db->fetchAll(
            'SELECT id, valid_from FROM economy_sales_registration_registrations'
            . ' WHERE docState = 40 AND type = %s AND default_unit IS NOT NULL'
            . ' AND (valid_from IS NULL OR valid_from <= %s) AND (valid_to IS NULL OR valid_to >= %s)',
            'cz.eet2',
            $today,
            $today,
        );
        if (count($registrations) !== 1) {
            $this->markTestSkipped('Zdroj dat nemá právě jednu platnou registraci cz.eet2 s výchozí jednotkou');
        }
        $validFrom = $registrations[0]['valid_from'];
        $this->validFrom = $validFrom instanceof \DateTimeInterface ? $validFrom->format('Y-m-d') : substr((string) $validFrom, 0, 10);

        foreach (['invno', 'invpo', 'cash'] as $docType) {
            $row = $this->db->fetchRow(
                'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 ORDER BY id LIMIT 1',
                $docType,
            );
            $this->series[$docType] = (int) ($row['id'] ?? 0);
        }
        $partner = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 0 AND docState = 40 ORDER BY id LIMIT 1');
        $bankAccount = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_bank_accounts WHERE docState = 40 AND currency = %s ORDER BY id LIMIT 1',
            'czk',
        );
        $vatRegistration = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations WHERE country = %s AND docState = 40 ORDER BY id LIMIT 1',
            'cz',
        );
        if ($this->series['invno'] === 0 || $this->series['invpo'] === 0 || $partner === null || $bankAccount === null || $vatRegistration === null) {
            $this->markTestSkipped('Zdroj dat nemá řady faktur, partnera, vlastní účet nebo českou registraci DPH');
        }
        $this->partner = (int) $partner['id'];
        $this->bankAccount = (int) $bankAccount['id'];
        $this->vatRegistration = (int) $vatRegistration['id'];

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->saleZone = new \DateTimeZone('Europe/Prague');
        $this->gateway = new TableGateway(
            self::HEADS,
            $this->db->getDibiConnection(),
            DocumentLoader::load($this->dsConfig, $resolver),
            $this->tables[self::HEADS]->childTables,
            $this->configRuntime,
            $this->dsConfig,
            $this->dispatcherWithoutSending($resolver),
        );
    }

    /** Skutečné handlery aktivních modulů, jen handler evidence tržeb bez okamžitého odeslání. */
    private function dispatcherWithoutSending(ModulePathResolver $resolver): DocumentEventDispatcher
    {
        $errors = [];
        $modules = ModuleResolver::resolve(ModuleLoader::loadAllModules($resolver), $this->dsConfig->getModules(), $errors);
        $registrations = [];
        foreach ($modules as $module) {
            foreach ($module->documentEventHandlers as $registration) {
                if ($registration['class'] === DocsHeadsSalesHandler::class) {
                    $registration['class'] = NoSendSalesHandler::class;
                }
                $registrations[] = $registration;
            }
        }
        $dibi = $this->db->getDibiConnection();
        return new DocumentEventDispatcher(
            $registrations,
            $dibi,
            $this->configRuntime,
            $this->dsConfig,
            null,
            OpenItemLookupLoader::fromModules($modules, $dibi, $this->configRuntime, $this->dsConfig),
            JournalContributorLoader::fromModules($modules, $dibi, $this->configRuntime, $this->dsConfig),
        );
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach (array_reverse($this->headIds) as $id) {
            $dibi->query(
                'DELETE FROM %n WHERE [sale] IN (SELECT [id] FROM %n WHERE [doc_head] = %i)',
                self::ATTEMPTS,
                self::SALES,
                $id,
            );
            foreach ([self::SALES, 'economy_accbal_ledger', 'economy_accounting_journal', 'docs_core_vat_recap', 'docs_core_rows'] as $table) {
                try {
                    $dibi->query('DELETE FROM %n WHERE [doc_head] = %i', $table, $id);
                } catch (\Throwable) {
                    // tabulka ve zdroji dat není (modul vypnutý)
                }
            }
            $dibi->query('DELETE FROM %n WHERE [id] = %i', self::HEADS, $id);
        }
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $head */
    private function saveInvoice(array $head = [], float $unitPrice = 1000.0): DocumentResult
    {
        $docType = (string) ($head['doc_type'] ?? 'invno');
        return $this->gateway->saveDocument($head + [
            'doc_type'         => $docType,
            'number_series'    => $this->series[$docType],
            'partner'          => $this->partner,
            'bank_account'     => $this->bankAccount,
            'payment_method'   => 1,
            'issue_date'       => date('Y-m-d'),
            'accounting_date'  => date('Y-m-d'),
            'vat_registration' => $this->vatRegistration,
            'vat_mode'         => 1,
            'doc_currency'     => 'czk',
            'doc_text'         => 'IT kontaktní platba',
            'docState'         => 10,
            'docStateMain'     => 1,
            'rows'             => [
                ['row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Služba', 'quantity' => 1,
                 'unit_price' => $unitPrice, 'vat_code' => 'cz-120', 'vat_pct' => 21.0],
            ],
        ]);
    }

    /** @param array<string, mixed> $head */
    private function createInvoice(array $head = [], float $unitPrice = 1000.0): int
    {
        $result = $this->saveInvoice($head, $unitPrice);
        $this->assertTrue($result->isSuccess(), 'Založení dokladu selhalo: ' . $this->failure($result));
        return $this->headIds[] = (int) $result->getData()['id'];
    }

    private function failure(DocumentResult $result): string
    {
        return ($result->getErrorMessage() ?? '') . ' ' . json_encode($result->getValidation()?->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $change */
    private function transitionTo(int $headId, int $state, array $change = []): void
    {
        $data = $this->gateway->loadDocument($headId);
        $this->assertNotNull($data);
        $data = $change + $data;
        $data['docState'] = $state;
        $result = $this->gateway->saveDocument($data);
        $this->assertTrue($result->isSuccess(), "Přechod do {$state} selhal: " . $this->failure($result));
    }

    /** Text chyby uložení (422 ve formuláři), nebo null, když uložení prošlo. */
    private function saveError(callable $save): ?string
    {
        try {
            /** @var DocumentResult $result */
            $result = $save();
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
        if ($result->isSuccess()) {
            $this->headIds[] = (int) $result->getData()['id'];
            return null;
        }
        return $this->failure($result);
    }

    /** @return list<array<string, mixed>> */
    private function sales(int $headId): array
    {
        return $this->db->fetchAll('SELECT * FROM %n WHERE [doc_head] = %i ORDER BY [doc_sequence]', self::SALES, $headId);
    }

    /** @return array<string, mixed> */
    private function head(int $headId): array
    {
        return $this->db->fetchRow('SELECT * FROM %n WHERE id = %i', self::HEADS, $headId);
    }

    private static function stamp(mixed $value): ?string
    {
        return PaymentTime::normalize($value);
    }

    /** Čas platby „před chvílí“, jak ho zadá uživatel — hodiny v místě tržby. */
    private function paidAt(string $modifier = '-10 minutes'): string
    {
        return (new \DateTimeImmutable($modifier, $this->saleZone))->format('Y-m-d H:i:00');
    }

    /** Zadaný čas platby převedený do zóny serveru — tak má být v `sold_at`. */
    private function inServerZone(string $paidAt): string
    {
        return PaymentTime::inServerZone($paidAt, $this->saleZone)->format(PaymentTime::FORMAT);
    }

    /** Služba akcí bez fronty — tržba zůstane `pending`, ven nic nejde. */
    private function service(): ContactPaymentService
    {
        $dibi = $this->db->getDibiConnection();
        $loader = new DocFactsLoader($dibi, $this->configRuntime, $this->dsConfig);
        return new ContactPaymentService($dibi, $loader, new SaleSync($dibi, $loader, $this->configRuntime));
    }

    // ── Akce na dokladu V pořádku (U17) ─────────────────────────────────────

    public function testActionCreatesTheSaleWithThePaymentTimeAndCancellationSettlesIt(): void
    {
        $headId = $this->createInvoice();
        $this->transitionTo($headId, 40);
        $this->assertSame([], $this->sales($headId), 'faktura Převodem sama do evidence nespadá');
        // Faktura byla potvrzená před hodinou — zákazník platí až teď.
        $this->db->getDibiConnection()->query(
            'UPDATE %n SET [doc_state_changed_at] = %s WHERE [id] = %i',
            self::HEADS,
            (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            $headId,
        );
        $transition = self::stamp($this->head($headId)['doc_state_changed_at']);

        $paidAt = $this->paidAt();
        $outcome = $this->service()->set($headId, $paidAt);

        $this->assertTrue($outcome->isWritten(), (string) $outcome->refusal . ' ' . (string) $outcome->evaluationReason);
        $this->assertSame(SyncResult::CREATED, $outcome->sync?->status);
        $this->assertSame(SyncResult::TIME_PAYMENT, $outcome->sync?->timeBasis);

        $head = $this->head($headId);
        $this->assertSame(40, (int) $head['docState'], 'doklad zůstává V pořádku');
        $this->assertSame($transition, self::stamp($head['doc_state_changed_at']), 'žádný přechod stavu');
        $this->assertSame(1, (int) $head['contact_payment']);
        $this->assertSame($paidAt, self::stamp($head['contact_payment_at']), 'čas platby, jak byl zadán');
        $this->assertNotNull($head['contact_payment_changed_at']);

        $sales = $this->sales($headId);
        $this->assertCount(1, $sales);
        $this->assertEqualsWithDelta((float) $head['total_amount_dom'], (float) $sales[0]['amount'], 0.001, 'celá částka dokladu');
        $this->assertSame(1210.0, (float) $sales[0]['amount']);
        $this->assertSame($this->inServerZone($paidAt), self::stamp($sales[0]['sold_at']), 'čas tržby = čas platby z dialogu');
        $this->assertSame((string) $head['doc_number'], (string) $sales[0]['serial_number']);
        $this->assertSame('pending', (string) $sales[0]['state']);

        // Druhé nastavení (dvojí kliknutí) nic nezaloží.
        $this->assertSame(ContactPaymentOutcome::REFUSAL_CONFLICT, $this->service()->set($headId, $paidAt)->refusal);
        $this->assertCount(1, $this->sales($headId));

        // Zrušení: záporná tržba s časem zrušení, součet tržeb dokladu 0.
        $cancelled = $this->service()->cancel($headId);

        $this->assertTrue($cancelled->isWritten());
        $this->assertSame(SyncResult::TIME_FLAG_CHANGE, $cancelled->sync?->timeBasis);
        $head = $this->head($headId);
        $this->assertSame(0, (int) $head['contact_payment']);
        $this->assertNull($head['contact_payment_at']);
        $sales = $this->sales($headId);
        $this->assertCount(2, $sales);
        $this->assertSame(-1210.0, (float) $sales[1]['amount']);
        $this->assertSame(0.0, round((float) $sales[0]['amount'] + (float) $sales[1]['amount'], 2), 'součet tržeb dokladu je nula');
        $this->assertSame(self::stamp($head['contact_payment_changed_at']), self::stamp($sales[1]['sold_at']), 'čas zrušení');
        $this->assertSame($head['doc_number'] . '-2', (string) $sales[1]['serial_number']);
        $this->assertSame(40, (int) $head['docState']);
    }

    // ── Příznak ve formuláři v Konceptu (U16) ───────────────────────────────

    public function testFlagSetInTheConceptCreatesTheSaleOnConfirmation(): void
    {
        $paidAt = $this->paidAt('-30 minutes');
        $withTime = $this->createInvoice(['contact_payment' => 1, 'contact_payment_at' => str_replace(' ', 'T', substr($paidAt, 0, 16))]);
        $this->assertNotNull($this->head($withTime)['contact_payment_changed_at'], 'změna příznaku nastaví čas změny');
        $this->assertSame([], $this->sales($withTime), 'v Konceptu tržba nevzniká');

        $this->transitionTo($withTime, 40);

        $sales = $this->sales($withTime);
        $this->assertCount(1, $sales);
        $this->assertSame($this->inServerZone($paidAt), self::stamp($sales[0]['sold_at']), 'čas platby z formuláře');
        $this->assertSame(1210.0, (float) $sales[0]['amount']);

        // Bez času platby: čas tržby je čas přechodu do V pořádku.
        $withoutTime = $this->createInvoice(['contact_payment' => 1]);
        $this->transitionTo($withoutTime, 40);

        $sales = $this->sales($withoutTime);
        $this->assertCount(1, $sales);
        $this->assertSame(self::stamp($this->head($withoutTime)['doc_state_changed_at']), self::stamp($sales[0]['sold_at']));
    }

    // ── Kde příznak jde a kde ne (U14, U22) ─────────────────────────────────

    public function testProformaByCardFallsAndOtherPaymentMethodsRefuseTheFlag(): void
    {
        $proforma = $this->createInvoice(['doc_type' => 'invpo', 'payment_method' => 2, 'contact_payment' => 1]);
        $this->transitionTo($proforma, 40);
        $this->assertCount(1, $this->sales($proforma), 'zálohová faktura Kartou s příznakem je tržba');

        // Faktura vydaná Kartou spadá pravidlem — příznak nenabízí; brána,
        // zápočet a dobírka kontaktní platbou nejsou.
        $gateway = $this->db->fetchRow('SELECT id FROM economy_codebooks_payment_terminals WHERE docState = 40 AND kind = %i ORDER BY id LIMIT 1', PartnerBalanceResolver::KIND_GATEWAY);
        foreach ([2 => 'Kartou', 5 => 'Platební bránou', 4 => 'Zápočtem', 3 => 'Dobírkou'] as $method => $label) {
            $head = ['payment_method' => $method, 'contact_payment' => 1];
            if ($method === 5 && $gateway !== null) {
                $head['payment_terminal'] = (int) $gateway['id'];
            }
            $error = $this->saveError(fn (): DocumentResult => $this->saveInvoice($head));
            $this->assertNotNull($error, "faktura {$label} příznak odmítne");
            if ($method !== 5 || $gateway !== null) {
                // Bez platební brány v číselníku doklad odmítne už jádro.
                $this->assertStringContainsString('Kontaktní platbu', $error, $label);
            }
        }

        // Změna způsobu úhrady u konceptu s příznakem se hlídá také.
        $concept = $this->createInvoice(['contact_payment' => 1]);
        $error = $this->saveError(function () use ($concept): DocumentResult {
            $data = $this->gateway->loadDocument($concept);
            $data['payment_method'] = 4;
            return $this->gateway->saveDocument($data);
        });
        $this->assertStringContainsString('Kontaktní platbu', (string) $error);
    }

    public function testPaymentTimeInTheFutureIsRefusedOnSaveAndByTheAction(): void
    {
        $future = (new \DateTimeImmutable('+2 hours', $this->saleZone))->format('Y-m-d\TH:i');

        $error = $this->saveError(fn (): DocumentResult => $this->saveInvoice(['contact_payment' => 1, 'contact_payment_at' => $future]));
        $this->assertStringContainsString('v budoucnu', (string) $error);

        $headId = $this->createInvoice();
        $this->transitionTo($headId, 40);
        $this->assertSame(ContactPaymentOutcome::REFUSAL_FUTURE, $this->service()->set($headId, $future)->refusal);
        $this->assertSame(0, (int) $this->head($headId)['contact_payment'], 'nic se nezapsalo');
    }

    // ── U15: doklad vystavený před začátkem registrace ──────────────────────

    public function testDocumentIssuedBeforeTheRegistrationFallsWhenPaidWithinIt(): void
    {
        if ($this->validFrom === '') {
            $this->markTestSkipped('Registrace nemá začátek platnosti');
        }
        $before = (new \DateTimeImmutable($this->validFrom))->modify('-5 days')->format('Y-m-d');
        $headId = $this->createInvoice(['issue_date' => $before, 'accounting_date' => $before]);
        $this->transitionTo($headId, 40);

        // Platba ještě před registrací do evidence nepatří…
        $tooEarly = (new \DateTimeImmutable($this->validFrom))->modify('-1 day')->format('Y-m-d 12:00:00');
        $refused = $this->service()->set($headId, $tooEarly);
        $this->assertSame(ContactPaymentOutcome::REFUSAL_NOT_APPLICABLE, $refused->refusal);
        $this->assertSame(DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION, $refused->evaluationReason);

        // …platba v době její platnosti ano, i když je doklad starší.
        $outcome = $this->service()->set($headId, $this->paidAt());
        $this->assertTrue($outcome->isWritten(), (string) $outcome->refusal . ' ' . (string) $outcome->evaluationReason);
        $this->assertCount(1, $this->sales($headId));
    }

    // ── U21: záchranná síť po akci na dokladu se starým přechodem ───────────

    public function testSafetyNetSettlesAnActionOnADocumentConfirmedLongAgo(): void
    {
        $headId = $this->createInvoice();
        $this->transitionTo($headId, 40);
        $dibi = $this->db->getDibiConnection();
        $paidAt = $this->paidAt();

        // Doklad přešel do V pořádku dávno před oknem sítě a založení tržby
        // po akci spadlo: příznak zapsaný je, tržba ne.
        $dibi->query('UPDATE %n SET %a WHERE [id] = %i', self::HEADS, [
            'doc_state_changed_at'       => (new \DateTimeImmutable('-20 days'))->format('Y-m-d H:i:s'),
            'contact_payment'            => 1,
            'contact_payment_at'         => $paidAt,
            'contact_payment_changed_at' => date('Y-m-d H:i:s'),
        ], $headId);

        $scanner = ReconcileScanner::create($dibi, $this->configRuntime, $this->dsConfig);
        $found = array_values(array_filter(
            $scanner->run(ReconcileScanner::DEFAULT_DAYS, null, true),
            static fn (SyncResult $result): bool => $result->docId === $headId,
        ));

        $this->assertCount(1, $found, 'doklad je v okně sítě díky času změny příznaku');
        $this->assertSame(SyncResult::WOULD_CREATE, $found[0]->status);
        $this->assertSame(SyncResult::TIME_PAYMENT, $found[0]->timeBasis);
        $this->assertSame($this->inServerZone($paidAt), $found[0]->sale['sold_at'], 'stejný čas, jaký by dala akce');

        // Síť tržbu založí týmž výpočtem; pak už doklad znovu nevyhodnocuje.
        $created = SaleSync::create($dibi, $this->configRuntime, $this->dsConfig)->sync($headId);
        $this->assertSame(SyncResult::CREATED, $created->status);
        $this->assertSame($this->inServerZone($paidAt), self::stamp($this->sales($headId)[0]['sold_at']));

        $again = array_filter(
            $scanner->run(ReconcileScanner::DEFAULT_DAYS, null, true),
            static fn (SyncResult $result): bool => $result->docId === $headId,
        );
        $this->assertSame([], $again);
    }

    // ── D8c: fakturu už hradí pokladní doklad ───────────────────────────────

    public function testCashDocumentPayingTheInvoiceIsFoundForTheWarning(): void
    {
        if ($this->series['cash'] === 0) {
            $this->markTestSkipped('Zdroj dat nemá řadu pokladních dokladů');
        }
        $invoiceId = $this->createInvoice();
        $this->transitionTo($invoiceId, 40);
        $invoice = $this->head($invoiceId);
        $dibi = $this->db->getDibiConnection();
        $loader = new DocFactsLoader($dibi, $this->configRuntime, $this->dsConfig);
        $lookup = new CashPaymentLookup($dibi, $loader, new SaleSync($dibi, $loader, $this->configRuntime));

        $this->assertSame([], $lookup->payingDocuments($invoice), 'fakturu zatím nic nehradí');

        $result = $this->gateway->saveDocument([
            'doc_type'        => 'cash',
            'cash_dir'        => 1,
            'number_series'   => $this->series['cash'],
            'payment_method'  => 0,
            'partner'         => (int) $invoice['partner'],
            'issue_date'      => date('Y-m-d'),
            'accounting_date' => date('Y-m-d'),
            'vat_registration' => $this->vatRegistration,
            'vat_mode'        => 1,
            'doc_currency'    => 'czk',
            'doc_text'        => 'IT úhrada faktury hotově',
            'docState'        => 10,
            'docStateMain'    => 1,
            'rows'            => [[
                'row_kind' => 1, 'operation' => 'payment.receivable', 'description' => 'Úhrada faktury', 'quantity' => 1,
                'unit_price' => (float) $invoice['total_amount'],
                'partner' => (int) ($invoice['partner_balance'] ?: $invoice['partner']),
                'payment_reference' => (string) $invoice['payment_reference'],
            ]],
        ]);
        $this->assertTrue($result->isSuccess(), 'Založení pokladního dokladu selhalo: ' . $this->failure($result));
        $cashId = $this->headIds[] = (int) $result->getData()['id'];

        $this->assertSame([], $lookup->payingDocuments($invoice), 'koncept pokladního dokladu fakturu nehradí');

        $this->transitionTo($cashId, 40);

        $this->assertCount(1, $this->sales($cashId), 'úhrada pohledávky hotově je tržba (pravidlo nad řádky)');
        $this->assertSame([(string) $this->head($cashId)['doc_number']], $lookup->payingDocuments($invoice));
    }
}
