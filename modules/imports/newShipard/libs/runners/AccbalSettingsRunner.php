<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;
use imports\newShipard\libs\CrudClient;
use imports\newShipard\libs\HttpException;

/**
 * Import nastavení saldokont (skupiny + jejich účty) ze starého Shipardu do
 * nového. Běží jako fáze `all` (přes runImport(), za číselníky) i samostatně
 * jako subkomanda `accbal-settings` — je to konfigurace, ne migrovaná data.
 *
 * Dva režimy (samostatně právě jeden povinný; `all` volá runImport() přímo):
 *   --dump    stará DB (e10doc_accBal_balances + …_balancesAccounts) → JSON
 *             v seed tvaru nového Shipardu (balances[] s vnořenými accounts[]).
 *             Mapování je čistý rename (enum hodnoty se starý↔nový kryjí);
 *             staré nastavení dobropisy nemá → dump je přirozeně bez nich.
 *   --import  JSON → POST přes generický CRUD do economy_accbal_balances /
 *             economy_accbal_balance_accounts. FK účtů řeší vnoření v JSONu
 *             (id skupiny z kroku 1), žádná LocalIdMap.
 *
 * Idempotence per kód skupiny: před vytvořením se skupina hledá na cíli přes
 * findOneBy('economy_accbal_balances', 'code', …); existuje → přeskočí se
 * vč. účtů (druhý běh `all` tedy neduplikuje). ZMĚNA uvnitř existující skupiny
 * se nepromítne — ruční doladění JSONu dál znamená `ds-reset` cílového DS.
 *
 * Cesta JSONu: default modules/imports/newShipard/data/accbalSettings.json
 * (verzovaný, ručně laděný); override přes --file=…
 *
 * Viz tasks/12-accbal-settings.md, tasks/15-accbal-all-integration.md
 * a shpd:docs/accbal.md.
 */
final class AccbalSettingsRunner extends ImportRunner
{
	private const DEFAULT_FILE = __DIR__ . '/../../data/accbalSettings.json';

	/**
	 * Samostatný vstup (subkomanda accbal-settings): validuje --dump XOR
	 * --import a deleguje na veřejné runDump()/runImport(). Fáze `all` volá
	 * runImport() přímo — bez simulace flagů.
	 */
	public function run(): bool
	{
		$dump   = (bool) $this->app()->arg('dump');
		$import = (bool) $this->app()->arg('import');

		if ($dump === $import)   // ani jeden, nebo oba zároveň
		{
			$this->err("accbal-settings: zvol právě jeden režim: --dump nebo --import.");
			return false;
		}

		return $dump ? $this->runDump() : $this->runImport();
	}

	private function filePath(): string
	{
		$f = trim((string) ($this->app()->arg('file') ?? ''));
		return $f !== '' ? $f : self::DEFAULT_FILE;
	}

	// ── --dump: stará DB → JSON ──────────────────────────────────────────

	/** Veřejný vstup pro dump (subkomanda accbal-settings --dump). */
	public function runDump(): bool
	{
		$this->info("Dump nastavení saldokont ze staré DB…");

		$balances = $this->db()->query([
			'SELECT [ndx], [fullName], [shortName], [globalId], [order], [validFrom], [validTo]'
			. ' FROM [e10doc_accBal_balances] WHERE [docState] != %i', 9800,
			' ORDER BY [order], [ndx]',
		])->fetchAll();

		$out = ['balances' => []];
		$nGroups = 0;
		$nAccounts = 0;
		$nWarn = 0;

		foreach ($balances as $bRow)
		{
			$b = (array) $bRow;
			$bNdx = (int) $b['ndx'];
			$code = $this->deriveGroupCode($b, $nWarn);

			$accRows = $this->db()->query([
				'SELECT [accountId], [accSide], [accAmountsSign], [balSide], [balModifySign],'
				. ' [note], [systemOrder], [validFrom], [validTo]'
				. ' FROM [e10doc_accBal_balancesAccounts] WHERE [balance] = %i', $bNdx,
				' AND [docState] != %i', 9800,
				' ORDER BY [systemOrder]',
			])->fetchAll();

			$accounts = [];
			foreach ($accRows as $aRow)
			{
				$a = (array) $aRow;
				$accounts[] = [
					'account_number' => (string) ($a['accountId'] ?? ''),
					'acc_side'       => (int) ($a['accSide'] ?? 0),
					'amounts_sign'   => (int) ($a['accAmountsSign'] ?? 0),
					'bal_side'       => (int) ($a['balSide'] ?? 0),
					'modify_sign'    => (bool) ($a['balModifySign'] ?? 0),
					'note'           => ($a['note'] ?? '') !== '' ? (string) $a['note'] : null,
					'sort_order'     => (int) ($a['systemOrder'] ?? 0),
					'valid_from'     => $this->dateStr($a['validFrom'] ?? null),
					'valid_to'       => $this->dateStr($a['validTo'] ?? null),
				];
				$nAccounts++;
			}

			$out['balances'][] = [
				'code'       => $code,
				'name'       => (string) ($b['fullName'] ?? ''),
				'short_name' => ($b['shortName'] ?? '') !== '' ? (string) $b['shortName'] : null,
				'sort_order' => (int) ($b['order'] ?? 0),
				'valid_from' => $this->dateStr($b['validFrom'] ?? null),
				'valid_to'   => $this->dateStr($b['validTo'] ?? null),
				'accounts'   => $accounts,
			];
			$nGroups++;
		}

		$json = json_encode($out,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false)
		{
			$this->err("json_encode selhal: " . json_last_error_msg());
			return false;
		}

		$path = $this->filePath();
		if (file_put_contents($path, $json . "\n") === false)
		{
			$this->err("Zápis selhal: {$path}");
			return false;
		}

		$this->ok("Dump: {$nGroups} skupin / {$nAccounts} účtů → {$path}");
		if ($nWarn > 0)
			$this->warn("{$nWarn} skupin nemělo globalId → kód odvozen z názvu; "
				. "dolaď ručně na seed kódy (receivables/payables/advances_given/…).");
		$this->info("Dobropisy: staré nastavení je nemá → dump je bez nich (záměrně, sedí starému chování).");
		return true;
	}

	// ── --import: JSON → nový Shipard ────────────────────────────────────

	/** Veřejný vstup pro import — volá ho i AllRunner jako fázi `all`. */
	public function runImport(): bool
	{
		$path = $this->filePath();
		if (!is_file($path))
		{
			$this->err("Soubor nenalezen: {$path} (spusť nejdřív --dump, nebo zadej --file=…).");
			return false;
		}

		$data = json_decode((string) file_get_contents($path), true);
		if (!is_array($data) || !isset($data['balances']) || !is_array($data['balances']))
		{
			$this->err("Neplatný JSON ({$path}): chybí pole 'balances'.");
			return false;
		}

		$this->info("Import nastavení saldokont z {$path}…");

		// Pre-flight: kódy skupin musí být unikátní (DB unq_code). Staré globalId
		// nejsou spolehlivě unikátní (kolize → částečný import + neprůhledné HTTP
		// 500 z DB). Selž čistě JEŠTĚ PŘED jakýmkoli POSTem.
		if (!$this->assertUniqueCodes($data['balances']))
			return false;

		$crud = new CrudClient($this->http());
		$stats = ['groups' => 0, 'accounts' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($data['balances'] as $i => $g)
		{
			if (!is_array($g) || ($g['code'] ?? '') === '' || ($g['name'] ?? '') === '')
			{
				$this->err("balances[{$i}]: povinné 'code'/'name' chybí — skupina přeskočena.");
				$stats['failed']++;
				if (!$this->isContinueOnError())
				{
					$this->err("Aborting (use --continue-on-error to skip).");
					return false;
				}
				continue;
			}

			// Idempotence per skupina podle `code`: existuje na cíli → přeskoč
			// (vč. účtů). Read-only GET → běží i v dry-run. HttpException (chybí
			// filter whitelist na nové straně) je prerekvizita, ne datový řádek
			// → tvrdý fail po vzoru clearing guardu v AllRunner.
			try
			{
				if ($crud->findOneBy('economy_accbal_balances', 'code', (string) $g['code']) !== null)
				{
					$stats['skipped']++;
					$this->info("skupina '{$g['code']}' už na cíli existuje — přeskakuji (vč. účtů).");
					continue;
				}
			}
			catch (HttpException $e)
			{
				$this->err("accbal-settings: idempotence check vrátil HTTP {$e->statusCode} pro filter[code].");
				$this->err("List endpoint asi nepodporuje filter[code] — doplň whitelist na nové straně a spusť znovu.");
				return false;
			}

			try
			{
				$balanceId = $this->createBalance($crud, $g);
				$stats['groups']++;
				$this->ok(sprintf("[balance] '%s' → id=%s  %s",
					$g['code'], $balanceId ?? 'dry-run', $g['name']));

				foreach (($g['accounts'] ?? []) as $j => $a)
				{
					if (!is_array($a) || ($a['account_number'] ?? '') === '')
					{
						$this->warn("balances[{$i}].accounts[{$j}]: prázdné 'account_number' — přeskočeno.");
						continue;
					}
					$this->createAccount($crud, $balanceId, $a);
					$stats['accounts']++;
				}
			}
			catch (HttpException $e)
			{
				$stats['failed']++;
				$this->err("skupina '{$g['code']}': " . $e->getMessage());
				if (!$this->isContinueOnError())
				{
					$this->err("Aborting (use --continue-on-error to skip failed groups).");
					return false;
				}
			}
		}

		$this->summary("");
		$this->summary(sprintf("Done accbal-settings: skupiny=%d, účty=%d, skipped=%d, failed=%d",
			$stats['groups'], $stats['accounts'], $stats['skipped'], $stats['failed']));
		return true;   // chyby řádků → exit code 2 přes Logger::errorCount()
	}

	/**
	 * POST skupiny → nové id (nebo null v dry-run).
	 * docStateMain neposíláme — je system:true, server ho dopočítá z docState.
	 */
	private function createBalance(CrudClient $crud, array $g): ?int
	{
		$payload = [
			'code'       => (string) $g['code'],
			'name'       => (string) $g['name'],
			'short_name' => ($g['short_name'] ?? '') !== '' ? (string) $g['short_name'] : null,
			'sort_order' => (int) ($g['sort_order'] ?? 0),
			'valid_from' => $g['valid_from'] ?? null,
			'valid_to'   => $g['valid_to'] ?? null,
			'docState'   => 40,
		];

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: POST economy_accbal_balances "
				. json_encode($payload, JSON_UNESCAPED_UNICODE));
			return null;
		}
		return $crud->create('economy_accbal_balances', $payload);
	}

	private function createAccount(CrudClient $crud, ?int $balanceId, array $a): void
	{
		$payload = [
			'balance'        => $balanceId,
			'account_number' => (string) $a['account_number'],
			'acc_side'       => (int) ($a['acc_side'] ?? 0),
			'amounts_sign'   => (int) ($a['amounts_sign'] ?? 0),
			'bal_side'       => (int) ($a['bal_side'] ?? 0),
			'modify_sign'    => (bool) ($a['modify_sign'] ?? false),
			'note'           => ($a['note'] ?? '') !== '' ? (string) $a['note'] : null,
			'sort_order'     => (int) ($a['sort_order'] ?? 0),
			'valid_from'     => $a['valid_from'] ?? null,
			'valid_to'       => $a['valid_to'] ?? null,
			'docState'       => 40,
		];

		if ($this->isDryRun())
		{
			$this->debug("DRY-RUN: POST economy_accbal_balance_accounts "
				. json_encode($payload, JSON_UNESCAPED_UNICODE));
			return;
		}
		$crud->create('economy_accbal_balance_accounts', $payload);
	}

	// ── helpers ──────────────────────────────────────────────────────────

	private function isContinueOnError(): bool
	{
		return (bool) $this->app()->arg('continue-on-error');
	}

	/**
	 * Kódy skupin musí být unikátní (poruší jinak unq_code). Vypíše kolize
	 * (kód → názvy skupin) a vrátí false. Prázdné kódy řeší per-skupina check
	 * v runImport().
	 */
	private function assertUniqueCodes(array $balances): bool
	{
		$byCode = [];
		foreach ($balances as $i => $g)
		{
			if (!is_array($g))
				continue;
			$code = (string) ($g['code'] ?? '');
			if ($code === '')
				continue;
			$byCode[$code][] = (string) ($g['name'] ?? "balances[{$i}]");
		}

		$dups = array_filter($byCode, static fn(array $names): bool => count($names) > 1);
		if ($dups === [])
			return true;

		$this->err("Duplicitní kódy skupin (porušily by unq_code) — oprav v JSONu:");
		foreach ($dups as $code => $names)
			$this->err(sprintf("  '%s' ×%d: %s", $code, count($names), implode(', ', $names)));
		$this->err("Staré globalId nejsou unikátní → ruční reconciliation na seed kódy "
			. "(receivables/payables/credits/…).");
		return false;
	}

	/**
	 * `code` skupiny: použij staré globalId; je-li prázdné, odvoď slug z názvu
	 * a zvyš čítač varování (uživatel doladí na seed kódy). Ořež na 25 (varchar).
	 */
	private function deriveGroupCode(array $b, int &$nWarn): string
	{
		$gid = trim((string) ($b['globalId'] ?? ''));
		if ($gid !== '')
			return mb_strlen($gid) > 25 ? mb_substr($gid, 0, 25) : $gid;

		$code = $this->slug((string) ($b['fullName'] ?? ''));
		$nWarn++;
		$this->warn(sprintf("skupina ndx=%s '%s' nemá globalId → kód odvozen jako '%s' (dolaď ručně).",
			$b['ndx'] ?? '?', $b['fullName'] ?? '', $code));
		return $code;
	}

	/** Název → ASCII slug (a-z0-9 + '_'), ořez na 25 znaků. */
	private function slug(string $name): string
	{
		$s = $name;
		if (function_exists('iconv'))
		{
			$t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if ($t !== false)
				$s = $t;
		}
		$s = strtolower($s);
		$s = preg_replace('~[^a-z0-9]+~', '_', $s) ?? '';
		$s = trim($s, '_');
		if ($s === '')
			$s = 'balance';
		return mb_strlen($s) > 25 ? mb_substr($s, 0, 25) : $s;
	}

	/** Dibi DateTime / string / null → ISO 'Y-m-d' nebo null. */
	private function dateStr(mixed $date): ?string
	{
		if ($date === null || $date === '' || $date === '0000-00-00')
			return null;
		if ($date instanceof \DateTimeInterface)
			return $date->format('Y-m-d');
		if (is_string($date))
		{
			$ts = strtotime($date);
			return $ts !== false ? date('Y-m-d', $ts) : null;
		}
		return null;
	}
}
