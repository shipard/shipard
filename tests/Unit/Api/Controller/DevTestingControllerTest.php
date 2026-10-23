<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\DevDashboardController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Unit\Module\Core\Mail\Eval\Browse\EvalFixtureRoot;

/**
 * API sekce Testování (#103 D51–D53) přes dispatch dashboardu: seznam sad
 * a běhů nad vlastním kořenem {@see EvalFixtureRoot} (D106 — o skutečném
 * `tests/eval/` se jako celku nic netvrdí), detaily nad skutečným checkoutem
 * (sada `tests/datasets`, jen **pojmenované** běhy z `tests/eval`): tvary
 * odpovědí, 404 pro neznámé a podvržené cesty, PDF jen ze sidecar složky
 * zprávy, porovnání (400 / 409), server bez `tests/`.
 */
final class DevTestingControllerTest extends TestCase
{
	private const SET = 'cz-vat-payer';
	private const RUN = '2026-10-16-baseline.json';
	private const OLD_RUN = '2026-10-15-baseline.json';
	private const MSG = 'MSG-CORPUS-VP-0301';

	private DevDashboardController $ctrl;

	protected function setUp(): void
	{
		$repoRoot = dirname(__DIR__, 4);
		$this->ctrl = new DevDashboardController('/nonexistent/data-sources', null, new ModulePathResolver([$repoRoot . '/modules']), $repoRoot . '/nope.html', $repoRoot);
	}

	private function get(string $uri, ?DevDashboardController $ctrl = null): Response
	{
		$queryParams = [];
		$queryString = parse_url($uri, PHP_URL_QUERY);
		if (is_string($queryString) && $queryString !== '') {
			parse_str($queryString, $queryParams);
		}
		return ($ctrl ?? $this->ctrl)->dispatch(Request::fromArray('GET', $uri, $queryParams, '', []));
	}

	private function statusOf(Response $r): int
	{
		return (int) (new \ReflectionClass($r))->getProperty('status')->getValue($r);
	}

	private function payload(Response $r): mixed
	{
		return (new \ReflectionClass($r))->getProperty('payload')->getValue($r);
	}

	private function headers(Response $r): array
	{
		return (array) (new \ReflectionClass($r))->getProperty('headers')->getValue($r);
	}

	private function data(string $uri, int $expectedStatus = 200, ?DevDashboardController $ctrl = null): mixed
	{
		$resp = $this->get($uri, $ctrl);
		$this->assertSame($expectedStatus, $this->statusOf($resp), $uri . ' → ' . json_encode($this->payload($resp)));
		return $this->payload($resp)['data'];
	}

	/** Seznam sad a běhy nad vlastním kořenem (D106): počty, poslední běh podle `startedAt`, sada bez složky výsledků, `currentSet` podle hashe. */
	public function testDatasetsListIncludesRunCounts(): void
	{
		$fixture = EvalFixtureRoot::create();
		try {
			$hash = $fixture->seedPayerAndAssociation();
			$ctrl = new DevDashboardController('/nonexistent/data-sources', null, null, $fixture->root . '/nope.html', $fixture->root);

			$data = $this->data('/_dev/api/testing/datasets', ctrl: $ctrl);
			$byName = array_column($data, null, 'name');
			$this->assertSame([EvalFixtureRoot::ASSOCIATION, EvalFixtureRoot::PAYER], array_keys($byName), 'podle názvu');
			$this->assertSame(['name', 'title', 'country', 'profile', 'messages', 'runs', 'lastRunAt'], array_keys($byName[EvalFixtureRoot::PAYER]));
			$this->assertSame(3, $byName[EvalFixtureRoot::PAYER]['runs']);
			$this->assertSame('2026-10-16T10:00:00+00:00', $byName[EvalFixtureRoot::PAYER]['lastRunAt'], 'poslední podle startedAt, ne podle názvu souboru');
			$this->assertSame(0, $byName[EvalFixtureRoot::ASSOCIATION]['runs'], 'sada bez složky výsledků má nula běhů');
			$this->assertNull($byName[EvalFixtureRoot::ASSOCIATION]['lastRunAt']);

			$runs = $this->data('/_dev/api/testing/datasets/' . EvalFixtureRoot::PAYER . '/runs', ctrl: $ctrl);
			$this->assertSame($hash, $runs['currentHash']);
			$this->assertSame(
				['2026-10-16-baseline.json' => true, '2026-10-16-alpha.json' => true, '2026-10-15-baseline.json' => false],
				array_column($runs['runs'], 'currentSet', 'file'),
				'nejnovější první; běh s jiným hashem není nad aktuální sadou',
			);
		} finally {
			$fixture->remove();
		}
	}

	public function testRunsOfADataset(): void
	{
		$data = $this->data('/_dev/api/testing/datasets/' . self::SET . '/runs');
		$this->assertSame(self::SET, $data['dataset']['name']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['currentHash']);
		$files = array_column($data['runs'], 'file');
		$this->assertContains(self::RUN, $files);
		$this->assertContains(self::OLD_RUN, $files);
		$byFile = array_column($data['runs'], null, 'file');
		// běhy z 16. 10. jsou nad sadou před #119 krokem 1 (podoba druhých dokladů variant 5, 6, 8 a 9 je `pdf`, hash sady se změnil); nový běh nad aktuální sadou je věc člověka
		$this->assertFalse($byFile[self::RUN]['currentSet']);
		$this->assertFalse($byFile[self::OLD_RUN]['currentSet']);
	}

	public function testRunDetailWithoutCallsButWithMessages(): void
	{
		$data = $this->data('/_dev/api/testing/runs/' . self::SET . '/' . self::RUN);
		$this->assertArrayNotHasKey('calls', $data['run']);
		$this->assertArrayNotHasKey('_file', $data['run']);
		$this->assertSame('shpd.eval.run.v1', $data['run']['format']);
		$this->assertArrayHasKey('deviationsByField', $data['run']);
		$this->assertSame(self::RUN, $data['summary']['file']);
		$this->assertCount(20, $data['messages']);
		$first = array_values(array_filter($data['messages'], static fn (array $m): bool => $m['messageId'] === self::MSG))[0];
		$this->assertSame('domestic-rounding', $first['variant']);
		$this->assertSame('pdf', $first['form']);
		$this->assertSame([1, 2, 3], array_column($first['repeats'], 'repeat'));
		$this->assertSame(4, $first['repeats'][0]['deviations']);
		$this->assertSame('ai', $first['path'], 'běh před D76 bez klíče path = cesta ai');
		$this->assertSame(['ai', 'ai', 'ai'], array_column($first['repeats'], 'path'));
		$this->assertArrayNotHasKey('byPath', $data['run'], 'starší běh rozpad po cestách nemá — prohlížeč to unese');
	}

	public function testMessageDetailAndRepeatValidation(): void
	{
		$data = $this->data('/_dev/api/testing/runs/' . self::SET . '/' . self::RUN . '/messages/' . self::MSG . '?repeat=2');
		$this->assertSame(2, $data['repeat']);
		$this->assertSame([1, 2, 3], $data['repeats']);
		$this->assertTrue($data['countsMatch']);
		$this->assertSame('ai', $data['path']);
		$this->assertSame(0, $data['counts']['notCarried']);
		$this->assertFalse($data['currentSet'], 'běh z 16. 10. je nad sadou před #119 krokem 1');
		$this->assertTrue($data['hasPdf']);
		$this->assertSame(self::RUN, $data['run']['file']);
		$this->assertCount(10, $data['groups']);

		$default = $this->data('/_dev/api/testing/runs/' . self::SET . '/' . self::RUN . '/messages/' . self::MSG);
		$this->assertSame(1, $default['repeat'], 'bez ?repeat= první opakování');

		$this->assertSame(404, $this->statusOf($this->get('/_dev/api/testing/runs/' . self::SET . '/' . self::RUN . '/messages/' . self::MSG . '?repeat=9')));
		$this->assertSame(400, $this->statusOf($this->get('/_dev/api/testing/runs/' . self::SET . '/' . self::RUN . '/messages/' . self::MSG . '?repeat=x')));
		$this->assertSame(400, $this->statusOf($this->get('/_dev/api/testing/runs/' . self::SET . '/' . self::RUN . '/messages/' . self::MSG . '?repeat=0')));
		$this->assertSame(404, $this->statusOf($this->get('/_dev/api/testing/runs/' . self::SET . '/' . self::RUN . '/messages/MSG-NOPE')));
	}

	public function testPdfIsServedInlineFromTheMessageSidecar(): void
	{
		$resp = $this->get('/_dev/api/testing/datasets/' . self::SET . '/messages/' . self::MSG . '/pdf');
		$this->assertSame(200, $this->statusOf($resp));
		$headers = $this->headers($resp);
		$this->assertSame('application/pdf', $headers['Content-Type']);
		$this->assertStringStartsWith('inline; filename="', $headers['Content-Disposition']);
		$this->assertStringEndsWith('.pdf"', $headers['Content-Disposition']);
		$this->assertSame('%PDF', substr((string) $this->payload($resp), 0, 4));

		$this->assertSame(404, $this->statusOf($this->get('/_dev/api/testing/datasets/' . self::SET . '/messages/MSG-NOPE/pdf')));
	}

	public function testMessageAcrossRunsAndCompare(): void
	{
		$data = $this->data('/_dev/api/testing/datasets/' . self::SET . '/messages/' . self::MSG . '/runs');
		$this->assertSame(self::MSG, $data['message']['messageId']);
		$acrossRuns = array_column($data['runs'], 'file');
		$this->assertContains(self::RUN, $acrossRuns);
		$this->assertContains(self::OLD_RUN, $acrossRuns);

		$cmp = $this->data('/_dev/api/testing/compare/' . self::SET . '?a=' . self::RUN . '&b=2026-10-16-sonnet-5-5-haiku-tags.json');
		$this->assertSame(self::RUN, $cmp['runs']['a']['file']);
		$this->assertSame('2026-10-16-sonnet-5-5-haiku-tags.json', $cmp['runs']['b']['file']);
		$this->assertSame('claude-haiku-5-5', $cmp['summaries']['b']['tagsModel']);
		$this->assertNotEmpty($cmp['metrics']);
		$this->assertCount(20, $cmp['messages'], 'doklady vedle sebe');
		$this->assertSame(['messageId', 'a', 'b', 'delta'], array_keys($cmp['messages'][0]));

		$resp = $this->get('/_dev/api/testing/compare/' . self::SET . '?a=' . self::RUN . '&b=' . self::OLD_RUN);
		$this->assertSame(409, $this->statusOf($resp));
		$this->assertSame('DIFFERENT_SETS', $this->payload($resp)['error']['code']);

		$this->assertSame(400, $this->statusOf($this->get('/_dev/api/testing/compare/' . self::SET . '?a=' . self::RUN)));
		$this->assertSame(404, $this->statusOf($this->get('/_dev/api/testing/compare/' . self::SET . '?a=' . self::RUN . '&b=nope.json')));
	}

	/** @return list<array{string}> */
	public static function forbiddenPaths(): array
	{
		return [
			['/_dev/api/testing/datasets/..%2F..%2Fcomposer.json/runs'],
			['/_dev/api/testing/datasets/../../composer.json/runs'],
			['/_dev/api/testing/runs/cz-vat-payer/..%2F..%2F..%2Fcomposer.json'],
			['/_dev/api/testing/runs/cz-vat-payer/%2Fetc%2Fpasswd'],
			['/_dev/api/testing/runs/cz-vat-payer/2026-10-16-baseline.json%2F..%2F..%2Fx.json'],
			['/_dev/api/testing/datasets/cz-vat-payer/messages/..%2F..%2Fexpected%2Fprojection.jsonc/pdf'],
			['/_dev/api/testing/datasets/cz-non-payer/messages/MSG-CORPUS-VP-0301/pdf'],
			['/_dev/api/testing/datasets/nope/runs'],
			['/_dev/api/testing/runs/cz-vat-payer/2026-10-16-baseline'],
			['/_dev/api/testing/nope'],
			['/_dev/api/testing'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('forbiddenPaths')]
	public function testUnknownAndTraversalPathsReturn404(string $uri): void
	{
		$resp = $this->get($uri);
		$this->assertSame(404, $this->statusOf($resp), $uri);
		$this->assertSame('NOT_FOUND', $this->payload($resp)['error']['code']);
	}

	public function testPostIsNotFound(): void
	{
		$resp = $this->ctrl->dispatch(Request::fromArray('POST', '/_dev/api/testing/datasets', [], '', []));
		$this->assertSame(404, $this->statusOf($resp));
	}

	public function testServerWithoutTestsDirectoriesHasNoTestingSection(): void
	{
		$root = sys_get_temp_dir() . '/shpd-devtesting-' . bin2hex(random_bytes(4));
		mkdir($root, 0700, true);
		try {
			$ctrl = new DevDashboardController('/nonexistent', null, null, $root . '/nope.html', $root);
			$resp = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/testing/datasets', [], '', []));
			$this->assertSame(404, $this->statusOf($resp));
			$this->assertSame('TESTING_UNAVAILABLE', $this->payload($resp)['error']['code']);
			$info = $ctrl->dispatch(Request::fromArray('GET', '/_dev/api/info', [], '', []));
			$this->assertFalse($this->payload($info)['data']['testing']);
		} finally {
			rmdir($root);
		}
	}
}
