<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetPreflight;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;

class DatasetPreflightTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_preflight_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /** @return array<string, mixed> */
    public static function person(): array
    {
        return [
            'format' => 'shpd.persons.person', 'formatVersion' => '1.0', 'personType' => 'company', 'country' => 'cz',
            'companyId' => '12345678', 'name' => ['fullName' => 'Acme s.r.o.'],
            'applyOptions' => ['mergeStrategy' => 'createOnly', 'matchStrategy' => 'identifiersOnly', 'targetDocState' => 40],
        ];
    }

    /** @return array<string, mixed> */
    public static function item(): array
    {
        return [
            'format' => 'shpd.items.item', 'formatVersion' => '1.0', 'code' => 'K-001', 'name' => 'Konzultace',
            'kind' => ['code' => 'service'], 'unit' => 'h', 'status' => ['docState' => 40],
        ];
    }

    /** @return array<string, mixed> */
    public static function doc(): array
    {
        return [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceReceived',
            'selfParty' => 'customer', 'supplier' => ['name' => 'Acme s.r.o.', 'companyId' => '12345678'],
            'dates' => ['issueDate' => '2026-06-01', 'accountingDate' => '2026-06-01'],
            'currency' => 'CZK', 'vat' => ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'cz'],
            'rows' => [['rowKind' => 'item', 'item' => ['ourCode' => 'K-001', 'name' => 'Konzultace'], 'unit' => 'h',
                        'quantity' => 1.0, 'unitPrice' => 100.0, 'totalPrice' => 100.0, 'vat' => ['code' => 'cz-110', 'pct' => 21.0]]],
            'applyOptions' => ['targetDocState' => 10],
        ];
    }

    /** @return array<string, mixed> */
    public static function registryDoc(): array
    {
        return [
            'format' => 'shpd.dataset.registryDocument.v1',
            'document' => ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => 'Smlouva', 'kindFields' => new \stdClass()],
            'docState' => 40, 'sourceKind' => 'mail', 'binder' => 'Smlouvy', 'created' => '2026-02-01T09:30:00',
            'attachments' => [['file' => 'smlouva.pdf', 'mimeType' => 'application/pdf']],
        ];
    }

    /** @return array<string, mixed> */
    public static function mail(): array
    {
        return [
            'format' => 'shpd.mail.incomingMessage', 'formatVersion' => '1.0', 'messageId' => 'MSG-20260601-0001',
            'mailbox' => 'default', 'subject' => 'Faktura', 'senderEmail' => 'a@b.example', 'receivedAt' => '2026-06-01T07:55:10',
            'docState' => 40, 'analysisState' => 30,
            'attachments' => [['file' => 'faktura.pdf']],
            'analyses' => [['analyzedAt' => '2026-06-01T08:00:00', 'status' => 2, 'modelName' => 'm', 'promptVersion' => 'v1',
                            'canonicalJson' => ['format' => 'shpd.docs.document', 'attachments' => [['ref' => 'att:1']]]]],
        ];
    }

    /**
     * Sada v temp složce; `$mutate(DatasetWriter)` může přidat/přepsat soubory.
     */
    public function writeSet(?callable $mutate = null, array $counts = [], ?string $country = null): string
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('setup/binders.jsonc', ['format' => 'shpd.dataset.setup.v1', 'table' => 'binders', 'rows' => [['name' => 'Smlouvy', 'docState' => 40]]]);
        $w->writeJsonc('persons/0001-acme.jsonc', self::person());
        $w->writeJsonc('items/0001-k-001.jsonc', self::item());
        $w->writeJsonc('docs/0001-koncept-invni.jsonc', self::doc());
        $w->writeJsonc('registry/0001-smlouva.jsonc', self::registryDoc());
        $w->writeRaw('registry/0001-smlouva.files/smlouva.pdf', '%PDF');
        $w->writeJsonc('mail/0001-msg-20260601-0001.jsonc', self::mail());
        $w->writeRaw('mail/0001-msg-20260601-0001.files/faktura.pdf', '%PDF');
        if ($mutate !== null) {
            $mutate($w);
        }
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z',
            $counts ?: ['setup' => 1, 'persons' => 1, 'items' => 1, 'docs' => 1, 'registry' => 1, 'mail' => 1],
            country: $country));
        return $this->tmp . '/set';
    }

    // ── země sady vs. DS (#103 D24) ────────────────────────────────────────

    public function testCountryMismatchIsAnError(): void
    {
        $result = (new DatasetPreflight())->check(DatasetReader::open($this->writeSet(country: 'sk')), 'cz');

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString("country 'sk'", $result['errors'][0]);
        $this->assertStringContainsString("'cz'", $result['errors'][0]);
    }

    public function testCountryMatchPasses(): void
    {
        $result = (new DatasetPreflight())->check(DatasetReader::open($this->writeSet(country: 'cz')), 'CZ');

        $this->assertSame([], $result['errors']);
    }

    public function testCountryCheckIsSkippedWithoutManifestCountry(): void
    {
        $result = (new DatasetPreflight())->check(DatasetReader::open($this->writeSet()), 'sk');

        $this->assertSame([], $result['errors']);
    }

    public function testCountryCheckIsSkippedWithoutDsCountry(): void
    {
        $set = $this->writeSet(country: 'sk');

        $this->assertSame([], (new DatasetPreflight())->check(DatasetReader::open($set), null)['errors']);
        $this->assertSame([], (new DatasetPreflight())->check(DatasetReader::open($set), '')['errors']);
    }

    public function testValidSetPassesWithoutErrorsOrWarnings(): void
    {
        $result = (new DatasetPreflight())->check(DatasetReader::open($this->writeSet()));

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function testSchemaViolationsAreReportedPerFile(): void
    {
        $set = $this->writeSet(static function (DatasetWriter $w): void {
            $bad = self::person();
            unset($bad['country']);
            $w->writeJsonc('persons/0002-bad.jsonc', $bad);
            $w->writeJsonc('mail/0002-bad.jsonc', ['format' => 'shpd.mail.incomingMessage', 'formatVersion' => '1.0']);
        });

        $result = (new DatasetPreflight())->check(DatasetReader::open($set));

        $this->assertCount(2, $result['errors']);
        $this->assertStringStartsWith('persons/0002-bad.jsonc:', $result['errors'][0]);
        $this->assertStringStartsWith('mail/0002-bad.jsonc:', $result['errors'][1]);
        // counts nesedí (2 soubory v persons/mail vs. manifest 1)
        $this->assertCount(2, $result['warnings']);
    }

    public function testSemanticValidatorErrorsBlockToo(): void
    {
        $set = $this->writeSet(static function (DatasetWriter $w): void {
            $doc = self::doc();
            unset($doc['dates']); // DocumentValidator: issueDate required
            $w->writeJsonc('docs/0001-koncept-invni.jsonc', $doc);
        });

        $result = (new DatasetPreflight())->check(DatasetReader::open($set));

        $this->assertCount(1, $result['errors']);
        $this->assertStringStartsWith('docs/0001-koncept-invni.jsonc:', $result['errors'][0]);
    }

    public function testMissingAttachmentIsWarning(): void
    {
        $set = $this->writeSet(static function (DatasetWriter $w): void {
            unlink($w->getRootDir() . '/mail/0001-msg-20260601-0001.files/faktura.pdf');
        });

        $result = (new DatasetPreflight())->check(DatasetReader::open($set));

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString("příloha 'faktura.pdf' v sadě chybí", $result['warnings'][0]);
    }

    public function testInvalidManifestIsTheOnlyError(): void
    {
        mkdir($this->tmp . '/set', 0755, true);
        file_put_contents($this->tmp . '/set/manifest.jsonc', json_encode([
            'format' => 'shpd.dataset.v1', 'name' => 'demo', 'title' => 'Demo', 'dateMode' => 'relative', 'created' => '2026-08-26T10:00:00Z',
        ]));

        $result = (new DatasetPreflight())->check(DatasetReader::open($this->tmp . '/set'));

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString("Not implemented: dateMode 'relative'", $result['errors'][0]);
    }
}
