<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eReport;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Report `dataset-e2e` (tasks/testing-phase7a.md D95, I11) nad fixturami
 * `tests/Fixtures/e2e/`: matice nálezů — selhaná karta, chyba prohlížeče,
 * 5xx proti 4xx, nesoulad počtu, zbylá karta, neúplný / chybějící průchod,
 * Playwright bez vysvětlení, `dataset-verify` rozdíl / přeskočeno /
 * odmítnuto / neběžel, přeseedování, signály; zrušení má přednost.
 */
final class E2eReportTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/shpd_e2e_rep_' . bin2hex(random_bytes(6));
        mkdir($this->out . '/traces', 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->out);
    }

    /** @return array<string, mixed> */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::fixturePath($name)), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function fixturePath(string $name): string
    {
        return dirname(__DIR__, 6) . '/Fixtures/e2e/' . $name;
    }

    /** @return array{dataset: array{name: string, profile: ?string, country: ?string}, dataSource: string, appUrl: string, outputDir: string, expected: ?int} */
    private function meta(?int $expected = null): array
    {
        return [
            'dataset' => ['name' => 'cz-test', 'profile' => 'vat-payer', 'country' => 'cz'],
            'dataSource' => 'abcd-abcd-abcd-abcd',
            'appUrl' => 'http://127.0.0.1/abcd-abcd-abcd-abcd/app/',
            'outputDir' => $this->out,
            'expected' => $expected,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function steps(int $walkExit = 0, int $verifyExit = 0): array
    {
        return [
            'reseed' => ['ran' => true, 'exitCode' => 0, 'durationMs' => 1500],
            'walkthrough' => ['ran' => true, 'exitCode' => $walkExit, 'termSignal' => null, 'durationMs' => 9500],
            'verify' => ['ran' => true, 'exitCode' => $verifyExit],
        ];
    }

    private function ok(): E2eReport
    {
        return E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), self::fixture('report-ok.json'), self::fixture('verify-ok.json'), null, self::steps(), false);
    }

    public function testCleanRunIsOkWithExitZero(): void
    {
        copy(self::fixturePath('walkthrough-ok.json'), $this->out . '/walkthrough.json');
        copy(self::fixturePath('report-ok.json'), $this->out . '/report.json');
        mkdir($this->out . '/playwright-report');
        file_put_contents($this->out . '/playwright-report/index.html', '<html></html>');

        $report = $this->ok();

        $this->assertSame('ok', $report->status());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame([], $report->problems());
        $this->assertSame(2, $report->expected());
        $this->assertCount(2, $report->applied());
        $this->assertSame([], $report->failed());
        $this->assertCount(1, $report->apiResponses(), '4xx se zapisuje');

        $array = $report->toArray();
        $this->assertSame(
            ['status', 'exitCode', 'problems', 'dataset', 'dataSource', 'appUrl', 'outputDir', 'steps', 'cards', 'browserErrors', 'apiResponses', 'verify', 'artifacts'],
            array_keys($array),
        );
        $this->assertSame(['name' => 'cz-test', 'profile' => 'vat-payer', 'country' => 'cz'], $array['dataset']);
        $this->assertSame(['reseed', 'walkthrough', 'verify'], array_keys($array['steps']));
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'durationMs' => 1500], $array['steps']['reseed']);
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'termSignal' => null, 'durationMs' => 9500], $array['steps']['walkthrough']);
        $this->assertSame(['ran' => true, 'exitCode' => 0, 'refused' => null], $array['steps']['verify']);
        $this->assertSame(['expected', 'applied', 'failed'], array_keys($array['cards']));
        $this->assertSame(2, $array['cards']['expected']);
        $this->assertSame('mail_suggestion:11', $array['cards']['applied'][0]['cardId']);
        $this->assertSame(self::fixture('verify-ok.json'), $array['verify'], 'JSON dataset-verify beze změny');
        $this->assertSame([
            'htmlReport' => $this->out . '/playwright-report/index.html',
            'playwrightReport' => $this->out . '/report.json',
            'walkthrough' => $this->out . '/walkthrough.json',
            'traces' => [],
        ], $array['artifacts']);

        $text = $this->text($report);
        $this->assertStringContainsString('Sada cz-test (profil vat-payer, země cz) → zdroj dat abcd-abcd-abcd-abcd', $text);
        $this->assertStringContainsString('Karty: použito 2 z 2 očekávaných, selhalo 0', $text);
        $this->assertStringContainsString('Odpovědi API ≥ 400: 1 (5xx: 0)', $text);
        $this->assertStringContainsString('dataset-verify: ok 3, rozdíl 0, přeskočeno 0', $text);
        $this->assertStringContainsString('Výsledek: v pořádku.', $text);
    }

    public function testFailedCardsBrowserErrorsAndServerErrorsAreFindings(): void
    {
        file_put_contents($this->out . '/traces/trace-mail_suggestion_22.zip', 'PK');
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-failed.json'), self::fixture('report-failed.json'), self::fixture('verify-ok.json'), null, self::steps(1), false);

        $this->assertSame('failed', $report->status());
        $this->assertSame(1, $report->exitCode());
        $problems = $report->problems();
        $this->assertSame([
            'použito 1 karet z 3 očekávaných',
            'na dashboardu zbylo 1 karet návrhů',
            'karta mail_suggestion:22: decide/no_create_offered (supplier)',
            'karta mail_suggestion:23: apply/disabled (supplierBank) — reference čeká na rozhodnutí, ale náhled pro ni nemá odznak',
            "prohlížeč: [mail_suggestion:22] pageerror: TypeError: Cannot read properties of undefined (reading 'id')",
            'prohlížeč: [mail_suggestion:23] console: Failed to load resource: the server responded with a status of 502 (Bad Gateway)',
            'API 5xx: [mail_suggestion:23] 500 GET http://127.0.0.1/abcd-abcd-abcd-abcd/api/v1/_ui/dashboard',
        ], $problems);
        $this->assertStringNotContainsString('422', implode("\n", $problems), '4xx není nález');
        $this->assertStringNotContainsString('Playwright hlásí', implode("\n", $problems), 'neúspěšný test je vysvětlený nálezy průchodu');

        $artifacts = $report->artifacts();
        $this->assertSame(
            [['cardId' => 'mail_suggestion:22', 'file' => 'trace-mail_suggestion_22.zip', 'path' => $this->out . '/traces/trace-mail_suggestion_22.zip']],
            $artifacts['traces'],
            'jen trace, který na disku je; `file` = basename pro stahování z dashboardu',
        );
        $this->assertNull($artifacts['walkthrough']);

        $text = $this->text($report);
        $this->assertStringContainsString('Karty: použito 1 z 3 očekávaných, selhalo 2', $text);
        $this->assertStringContainsString('[trace trace-mail_suggestion_22.zip]', $text);
        $this->assertStringContainsString('Chyby prohlížeče: 2', $text);
        $this->assertStringContainsString('Odpovědi API ≥ 400: 2 (5xx: 1)', $text);
        $this->assertStringContainsString('Výsledek: nálezy (7).', $text);
        $this->assertStringContainsString('trace mail_suggestion:22: ' . $this->out . '/traces/trace-mail_suggestion_22.zip', $text);
    }

    public function testServerErrorAloneFailsWhileClientErrorDoesNot(): void
    {
        $walkthrough = self::fixture('walkthrough-ok.json');
        $walkthrough['apiResponses'][] = ['status' => 500, 'method' => 'POST', 'url' => 'http://x/api/v1/_mail/messages/11/apply', 'cardId' => 'mail_suggestion:11', 'at' => 'now'];
        $report = E2eReport::build($this->meta(), $walkthrough, self::fixture('report-ok.json'), self::fixture('verify-ok.json'), null, self::steps(), false);

        $this->assertSame(['API 5xx: [mail_suggestion:11] 500 POST http://x/api/v1/_mail/messages/11/apply'], $report->problems());
        $this->assertSame(1, $report->exitCode());

        $walkthrough = self::fixture('walkthrough-ok.json');
        $walkthrough['browserErrors'][] = ['type' => 'console', 'message' => "Uncaught (in promise) x\nstack", 'cardId' => null, 'at' => 'now'];
        $report = E2eReport::build($this->meta(), $walkthrough, self::fixture('report-ok.json'), self::fixture('verify-ok.json'), null, self::steps(), false);
        $this->assertSame(['prohlížeč: [-] console: Uncaught (in promise) x'], $report->problems());
    }

    public function testExpectedCountComesFromTheWalkthroughOrTheCommand(): void
    {
        $walkthrough = self::fixture('walkthrough-ok.json');
        $walkthrough['expected'] = 3;
        $report = E2eReport::build($this->meta(2), $walkthrough, null, self::fixture('verify-ok.json'), null, self::steps(), false);
        $this->assertSame(['použito 2 karet z 3 očekávaných'], $report->problems());
        $this->assertSame(3, $report->toArray()['cards']['expected']);

        unset($walkthrough['expected']);
        $this->assertSame([], E2eReport::build($this->meta(2), $walkthrough, null, self::fixture('verify-ok.json'), null, self::steps(), false)->problems());
        $this->assertSame(['použito 2 karet z 5 očekávaných'], E2eReport::build($this->meta(5), $walkthrough, null, self::fixture('verify-ok.json'), null, self::steps(), false)->problems());
    }

    public function testIncompleteOrMissingWalkthroughFails(): void
    {
        $walkthrough = self::fixture('walkthrough-ok.json');
        $walkthrough['complete'] = false;
        $walkthrough['applied'] = [$walkthrough['applied'][0]];
        $walkthrough['current'] = ['cardId' => 'mail_suggestion:12', 'messageNdx' => 12, 'startedAt' => 'x'];
        $report = E2eReport::build($this->meta(), $walkthrough, null, self::fixture('verify-ok.json'), null, self::steps(1), false);
        $this->assertSame(['proklikání neúplné (walkthrough.json: complete = false)', 'použito 1 karet z 2 očekávaných'], $report->problems());

        $report = E2eReport::build($this->meta(2), null, null, self::fixture('verify-ok.json'), null, self::steps(1), false);
        $this->assertSame(['walkthrough.json chybí — Playwright nedoběhl do zápisu průchodu'], $report->problems());
        $this->assertSame(2, $report->expected());
        $this->assertSame([], $report->applied());
        $this->assertSame('failed', $report->status());
    }

    public function testPlaywrightFailureWithoutWalkthroughFindingIsReported(): void
    {
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), self::fixture('report-failed.json'), self::fixture('verify-ok.json'), null, self::steps(1), false);
        $this->assertSame(['Playwright hlásí 1 neúspěšný test bez nálezu v průchodu — viz report.json'], $report->problems());

        $playwright = self::fixture('report-ok.json');
        $playwright['errors'] = [['message' => "Error: Chybí proměnná prostředí SHPD_E2E_STORAGE_STATE.\n    at readEnv"]];
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), $playwright, self::fixture('verify-ok.json'), null, self::steps(1), false);
        $this->assertSame(['Playwright: Error: Chybí proměnná prostředí SHPD_E2E_STORAGE_STATE.'], $report->problems());

        $steps = self::steps(2);
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), null, self::fixture('verify-ok.json'), null, $steps, false);
        $this->assertSame(['Playwright skončil s kódem 2'], $report->problems());

        $steps['walkthrough']['exitCode'] = null;
        $steps['walkthrough']['termSignal'] = 9;
        $report = E2eReport::build($this->meta(), null, null, self::fixture('verify-ok.json'), null, $steps, false);
        $this->assertSame(['Playwright skončil signálem 9', 'walkthrough.json chybí — Playwright nedoběhl do zápisu průchodu'], $report->problems());
    }

    public function testVerifyDiffSkippedRefusedOrMissingAllFail(): void
    {
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), self::fixture('report-ok.json'), self::fixture('verify-diff.json'), null, self::steps(0, 1), false);
        $this->assertSame([
            'kontrola Položky a účty: rozdíl (2)',
            'kontrola Náklady: přeskočeno — žádný použitý doklad',
        ], $report->problems(), 'skipped je selhání (D95 doslova, I11)');
        $text = $this->text($report);
        $this->assertStringContainsString('dataset-verify: ok 1, rozdíl 1, přeskočeno 1', $text);
        $this->assertStringContainsString('2. Položky a účty … rozdíl (2)', $text);
        $this->assertStringContainsString('- položka X: účet 518100 ≠ 501100', $text);
        $this->assertStringContainsString('3. Náklady … přeskočeno — žádný použitý doklad', $text);

        $steps = self::steps(0, 2);
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), null, null, "dataset-verify odmítnuto:\n  - zdroj dat obsahuje data mimo sadu\n  - doklad #5 nemá zdrojovou zprávu\n", $steps, false);
        $this->assertSame(['dataset-verify odmítnuto: zdroj dat obsahuje data mimo sadu; doklad #5 nemá zdrojovou zprávu'], $report->problems());
        $this->assertSame("dataset-verify odmítnuto:\n  - zdroj dat obsahuje data mimo sadu\n  - doklad #5 nemá zdrojovou zprávu", $report->toArray()['steps']['verify']['refused']);
        $this->assertNull($report->toArray()['verify']);
        $this->assertStringContainsString("dataset-verify: odmítnuto\n   dataset-verify odmítnuto:\n     - zdroj dat obsahuje data mimo sadu", $this->text($report));

        $steps['verify'] = ['ran' => false, 'exitCode' => null];
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), null, null, null, $steps, false);
        $this->assertSame(['dataset-verify neběžel'], $report->problems());
        $this->assertStringContainsString('dataset-verify: neběžel', $this->text($report));

        $verify = self::fixture('verify-ok.json');
        $verify['exitCode'] = 1;
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), null, $verify, null, self::steps(0, 1), false);
        $this->assertSame(['dataset-verify skončil s kódem 1'], $report->problems());
    }

    public function testCancellationWinsOverFindings(): void
    {
        $steps = self::steps();
        $steps['walkthrough']['exitCode'] = 130;
        $steps['verify'] = ['ran' => false, 'exitCode' => null];
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-failed.json'), null, null, null, $steps, true);

        $this->assertSame('cancelled', $report->status());
        $this->assertSame(130, $report->exitCode());
        $this->assertNotSame([], $report->problems(), 'nálezy zůstávají informací');
        $this->assertSame('cancelled', $report->toArray()['status']);
        $this->assertStringContainsString('Výsledek: zrušeno.', $this->text($report));

        $report = E2eReport::build($this->meta(), null, null, null, null, ['reseed' => ['ran' => false], 'walkthrough' => ['ran' => false], 'verify' => ['ran' => false]], true);
        $this->assertSame(130, $report->exitCode());
        $this->assertSame(['proklikání neběželo', 'dataset-verify neběžel'], $report->problems());
    }

    public function testReseedFailureAndSkippedSteps(): void
    {
        $report = E2eReport::build(
            $this->meta(),
            null,
            null,
            null,
            null,
            ['reseed' => ['ran' => true, 'exitCode' => 1, 'durationMs' => 300], 'walkthrough' => ['ran' => false], 'verify' => ['ran' => false]],
            false,
        );
        $this->assertSame(['přeseedování selhalo (dataset-seed exit 1)', 'proklikání neběželo', 'dataset-verify neběžel'], $report->problems());
        $this->assertSame(1, $report->exitCode());
        $this->assertNull($report->expected());
        $text = $this->text($report);
        $this->assertStringContainsString('přeseedování … exit 1 (0.3 s)', $text);
        $this->assertStringContainsString('proklikání   … neběželo', $text);
        $this->assertStringContainsString('použito 0 z ? očekávaných', $text);

        $steps = self::steps();
        $steps['reseed'] = ['ran' => false];
        $report = E2eReport::build($this->meta(), self::fixture('walkthrough-ok.json'), null, self::fixture('verify-ok.json'), null, $steps, false);
        $this->assertSame('ok', $report->status(), '--no-reseed není nález');
        $this->assertStringContainsString('přeseedování … vynecháno (--no-reseed)', $this->text($report));
        $this->assertSame(['ran' => false, 'exitCode' => null, 'durationMs' => null], $report->toArray()['steps']['reseed']);
    }

    private function text(E2eReport $report): string
    {
        $output = new BufferedOutput();
        $report->writeText($output);
        return $output->fetch();
    }
}
