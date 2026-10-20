<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalDatasetInfo;
use Shipard\Module\Core\Mail\Eval\Browse\EvalDatasetMessages;
use Shipard\Module\Core\Mail\Eval\Browse\EvalMessageAcrossRuns;
use Shipard\Module\Core\Mail\Eval\Browse\EvalOutputReconstruction;
use Shipard\Module\Core\Mail\Eval\Browse\EvalRunSummary;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalRunComparison;

/**
 * API sekce Testování vývojářského dashboardu (`/_dev/api/testing/…`,
 * #103 D51–D53): jen čte soubory v checkoutu repa (`tests/datasets`,
 * `tests/eval`), nic nespouští. Volá ho `DevDashboardController` — platí
 * stejná ochrana (mód development, důvěryhodná síť).
 *
 * Bezpečnost cest: sada, běh a zpráva z URL se hledají ve výpisech
 * ({@see EvalCatalog}), nikdy se z nich neskládá cesta na disk; PDF jen
 * z adresáře zprávy v sadě ({@see EvalDatasetMessages::pdfPath()}).
 *
 * | GET                                                     | odpověď                                      |
 * |---------------------------------------------------------|----------------------------------------------|
 * | `datasets`                                              | sady + počet běhů a poslední běh             |
 * | `datasets/{sada}/runs`                                  | řádky běhů ({@see EvalRunSummary})           |
 * | `datasets/{sada}/messages/{id}/pdf`                     | PDF zprávy (`application/pdf`, inline)       |
 * | `datasets/{sada}/messages/{id}/runs`                    | doklad napříč běhy                           |
 * | `runs/{sada}/{soubor}`                                  | běh bez `calls` + seznam dokladů             |
 * | `runs/{sada}/{soubor}/messages/{id}?repeat=N`           | rekonstruovaný výstup dokladu                |
 * | `compare/{sada}?a=&b=`                                  | porovnání běhů + doklady vedle sebe          |
 */
final class DevTestingController
{
	public const string PREFIX = '/_dev/api/testing';

	public function __construct(private readonly string $repoRoot) {}

	public function dispatch(Request $request): Response
	{
		if ($request->getMethod() !== 'GET') {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		$path = $request->getPath();
		if ($path !== self::PREFIX && !str_starts_with($path, self::PREFIX . '/')) {
			return Response::error('NOT_FOUND', 'Not found', 404);
		}
		$segments = array_values(array_filter(
			array_map(rawurldecode(...), explode('/', substr($path, strlen(self::PREFIX)))),
			static fn (string $s): bool => $s !== '',
		));

		$catalog = new EvalCatalog($this->repoRoot);
		if (!$catalog->isAvailable()) {
			return Response::error('TESTING_UNAVAILABLE', 'tests/datasets or tests/eval not present in this installation', 404);
		}

		try {
			return match (true) {
				$segments === ['datasets'] => $this->datasets($catalog),
				count($segments) === 3 && $segments[0] === 'datasets' && $segments[2] === 'runs'
					=> $this->runs($catalog, $segments[1]),
				count($segments) === 5 && $segments[0] === 'datasets' && $segments[2] === 'messages' && $segments[4] === 'pdf'
					=> $this->pdf($catalog, $segments[1], $segments[3]),
				count($segments) === 5 && $segments[0] === 'datasets' && $segments[2] === 'messages' && $segments[4] === 'runs'
					=> $this->messageRuns($catalog, $segments[1], $segments[3]),
				count($segments) === 3 && $segments[0] === 'runs'
					=> $this->run($catalog, $segments[1], $segments[2]),
				count($segments) === 5 && $segments[0] === 'runs' && $segments[3] === 'messages'
					=> $this->message($catalog, $segments[1], $segments[2], $segments[4], $request),
				count($segments) === 2 && $segments[0] === 'compare'
					=> $this->compare($catalog, $segments[1], $request),
				default => Response::error('NOT_FOUND', 'Not found', 404),
			};
		} catch (EvalException $e) {
			return Response::error('EVAL_ERROR', $e->getMessage(), 422);
		}
	}

	private function datasets(EvalCatalog $catalog): Response
	{
		$out = [];
		foreach ($catalog->datasets() as $dataset) {
			$runs = $catalog->runs($dataset);
			$out[] = $dataset->toArray() + [
				'runs' => count($runs),
				'lastRunAt' => $runs[0]['startedAt'] ?? null,
			];
		}
		return Response::success($out);
	}

	private function runs(EvalCatalog $catalog, string $setName): Response
	{
		$dataset = $catalog->dataset($setName);
		if ($dataset === null) {
			return $this->notFound('dataset');
		}
		$currentHash = $catalog->currentHash($dataset);
		$rows = [];
		foreach ($catalog->runs($dataset) as $run) {
			$rows[] = EvalRunSummary::fromRun($run, $currentHash);
		}
		return Response::success([
			'dataset' => $dataset->toArray(),
			'currentHash' => $currentHash,
			'runs' => $rows,
		]);
	}

	private function run(EvalCatalog $catalog, string $setName, string $file): Response
	{
		$dataset = $catalog->dataset($setName);
		if ($dataset === null) {
			return $this->notFound('dataset');
		}
		$run = $catalog->run($dataset, $file);
		if ($run === null) {
			return $this->notFound('run');
		}
		$currentHash = $catalog->currentHash($dataset);
		$cells = [];
		foreach ((new EvalDatasetMessages($dataset->reader()))->all() as $message) {
			$cells[$message->messageId] = EvalDatasetMessages::cell($message);
		}

		$messages = [];
		foreach ((array) ($run['calls'] ?? []) as $call) {
			if (!is_array($call) || !is_string($call['messageId'] ?? null)) {
				continue;
			}
			$id = $call['messageId'];
			$messages[$id] ??= ($cells[$id] ?? ['messageId' => $id, 'stem' => null, 'variant' => null, 'form' => null]) + ['path' => null, 'repeats' => []];
			// Cesta výpočtu (#103 D76) — běhy před ní jsou celé `ai`; v rámci zprávy je stejná pro každé opakování.
			$messages[$id]['path'] = \Shipard\Module\Core\Mail\Eval\EvalCallResult::pathOf($call);
			$messages[$id]['repeats'][] = [
				'repeat' => (int) ($call['repeat'] ?? 1),
				'path' => \Shipard\Module\Core\Mail\Eval\EvalCallResult::pathOf($call),
				'status' => $call['status'] ?? null,
				'errorType' => $call['errorType'] ?? null,
				'deviations' => \Shipard\Module\Core\Mail\Eval\EvalCallResult::deviationCount($call),
				'costUsd' => $call['costUsd'] ?? null,
				'tagsCostUsd' => is_array($call['tags'] ?? null) && is_numeric($call['tags']['costUsd'] ?? null) ? $call['tags']['costUsd'] : null,
				'durationMs' => $call['durationMs'] ?? null,
				'tag' => $call['tag'] ?? null,
				'primaryType' => $call['primaryType'] ?? null,
			];
		}

		$body = $run;
		unset($body['calls'], $body['_file'], $body['_basename']);
		return Response::success([
			'dataset' => $dataset->toArray(),
			'summary' => EvalRunSummary::fromRun($run, $currentHash),
			'run' => $body,
			'messages' => array_values($messages),
		]);
	}

	private function message(EvalCatalog $catalog, string $setName, string $file, string $messageId, Request $request): Response
	{
		$dataset = $catalog->dataset($setName);
		if ($dataset === null) {
			return $this->notFound('dataset');
		}
		$run = $catalog->run($dataset, $file);
		if ($run === null) {
			return $this->notFound('run');
		}
		$repeatParam = $request->getQueryParams()['repeat'] ?? '1';
		if (!is_string($repeatParam) || !preg_match('/^\d+$/', $repeatParam) || (int) $repeatParam < 1) {
			return Response::error('INVALID_PARAMS', 'repeat must be a positive integer', 400);
		}
		$repeat = (int) $repeatParam;

		$messages = new EvalDatasetMessages($dataset->reader());
		$evalMessage = $messages->find($messageId);
		if ($evalMessage === null) {
			return $this->notFound('message');
		}
		$call = null;
		$repeats = [];
		foreach ((array) ($run['calls'] ?? []) as $candidate) {
			if (is_array($candidate) && ($candidate['messageId'] ?? null) === $messageId) {
				$repeats[] = (int) ($candidate['repeat'] ?? 1);
				if ((int) ($candidate['repeat'] ?? 1) === $repeat) {
					$call = $candidate;
				}
			}
		}
		if ($call === null) {
			return Response::error('NOT_FOUND', 'call not found in run (message or repeat)', 404);
		}
		$currentHash = $catalog->currentHash($dataset);
		$detail = EvalOutputReconstruction::build($messages->projection(), $evalMessage, $call, ($run['set']['hash'] ?? null) === $currentHash);
		sort($repeats);
		$detail['repeats'] = $repeats;
		$detail['run'] = EvalRunSummary::fromRun($run, $currentHash);
		$detail['hasPdf'] = $messages->pdfPath($evalMessage) !== null;
		return Response::success($detail);
	}

	private function pdf(EvalCatalog $catalog, string $setName, string $messageId): Response
	{
		$dataset = $catalog->dataset($setName);
		if ($dataset === null) {
			return $this->notFound('dataset');
		}
		$messages = new EvalDatasetMessages($dataset->reader());
		$message = $messages->find($messageId);
		if ($message === null) {
			return $this->notFound('message');
		}
		$path = $messages->pdfPath($message);
		$bytes = $path !== null && is_file($path) ? @file_get_contents($path) : false;
		if ($bytes === false) {
			return $this->notFound('pdf');
		}
		return Response::binary($bytes, 'application/pdf')
			->withHeader('Content-Disposition', 'inline; filename="' . basename($path) . '"')
			->withHeader('Cache-Control', 'no-store');
	}

	private function messageRuns(EvalCatalog $catalog, string $setName, string $messageId): Response
	{
		$dataset = $catalog->dataset($setName);
		if ($dataset === null) {
			return $this->notFound('dataset');
		}
		$messages = new EvalDatasetMessages($dataset->reader());
		$message = $messages->find($messageId);
		if ($message === null) {
			return $this->notFound('message');
		}
		return Response::success([
			'dataset' => $dataset->toArray(),
			'message' => EvalDatasetMessages::cell($message),
			'runs' => EvalMessageAcrossRuns::build($catalog->runs($dataset), $messageId, $catalog->currentHash($dataset)),
		]);
	}

	private function compare(EvalCatalog $catalog, string $setName, Request $request): Response
	{
		$dataset = $catalog->dataset($setName);
		if ($dataset === null) {
			return $this->notFound('dataset');
		}
		$qp = $request->getQueryParams();
		$a = $qp['a'] ?? null;
		$b = $qp['b'] ?? null;
		if (!is_string($a) || !is_string($b) || $a === '' || $b === '') {
			return Response::error('INVALID_PARAMS', 'a and b (run file names) are required', 400);
		}
		$runA = $catalog->run($dataset, $a);
		$runB = $catalog->run($dataset, $b);
		if ($runA === null || $runB === null) {
			return $this->notFound('run');
		}
		try {
			$comparison = EvalRunComparison::of($runA, $runB)->toArray();
		} catch (EvalException $e) {
			return Response::error('DIFFERENT_SETS', $e->getMessage(), 409);
		}
		$comparison['runs']['a']['file'] = $runA['_basename'];
		$comparison['runs']['b']['file'] = $runB['_basename'];
		$currentHash = $catalog->currentHash($dataset);
		$comparison['summaries'] = [
			'a' => EvalRunSummary::fromRun($runA, $currentHash),
			'b' => EvalRunSummary::fromRun($runB, $currentHash),
		];
		return Response::success($comparison);
	}

	private function notFound(string $what): Response
	{
		return Response::error('NOT_FOUND', ucfirst($what) . ' not found', 404);
	}
}
