<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;
use imports\newShipard\libs\HttpException;

/**
 * Zápis parametrů vrstvy C (core_system_settings) na cílový DS. Přeimportovaný
 * DS přijde s kompletními daty, ale s prázdnými parametry — setup checklist
 * v novém Shipardu proto hlásí nerozhodnuté položky, přestože odpovědi jsou
 * ve starých datech. Import je zapíše sám (rozhodnutí D9 — žádný backfill
 * na nové straně):
 *
 *   economy.accountChart          'none' (konstanta) — osnovu dodává
 *                                 AccountsRunner z importu; 'none' = „vlastní
 *                                 osnova, neseedovat".
 *   economy.homeCurrency          měna fiskálního roku pokrývajícího dnešek
 *                                 (replika Utils::homeCurrency); fallback
 *                                 poslední rok, fallback 'czk'.
 *   economy.fiscalYearStartMonth  měsíc [start] téhož fiskálního roku;
 *                                 fallback 1.
 *   economy.vatAgenda             true ⇔ existuje aspoň jedna registrace
 *                                 k DPH. Zapisuje se i explicitní false —
 *                                 absence klíče = nerozhodnuto (D2) a checklist
 *                                 by svítil dál.
 *
 * Zápis jde přes POST /_setup/parameters — jedinou validovanou vzdálenou cestu
 * (LayerCParameters::validate(), all-or-nothing). Opakovaný POST týchž hodnot
 * je idempotentní sám o sobě → žádná LocalIdMap, žádná idempotence per záznam
 * (po vzoru AccbalSettingsRunner).
 *
 * PREREKVIZITA na cílové straně: guard provisionerů na `skipProvisioning`
 * (shpd:tasks/setup-parameters-skip-provisioning.md) musí být nasazený dřív,
 * než tenhle runner poprvé poběží naostro — jinak zápis klíčů doseeduje
 * fiskální roky/osnovu. Varování „provisioning je na DS vypnutý" v odpovědi
 * endpointu je proto očekávané a informativní.
 *
 * Viz tasks/25-layer-c-settings.md a shpd:docs/ds-setup.md §7.2.
 */
final class SettingsRunner extends ImportRunner
{
	public function run(): bool
	{
		$this->info("Zápis parametrů vrstvy C (settings)…");

		$values = $this->deriveValues();

		if ($this->isDryRun())
		{
			$this->info('[dry-run] settings: POST /_setup/parameters se přeskakuje.');
			return true;
		}

		try
		{
			$resp = $this->http()->post('/_setup/parameters', ['values' => $values]);
		}
		catch (HttpException $e)
		{
			$this->err("settings: POST /_setup/parameters selhal: " . $e->getMessage());
			return false;
		}

		$this->reportResponse($resp);
		$this->ok("settings: 4 parametry vrstvy C zapsány.");
		return true;
	}

	// ── Odvození hodnot ze staré DB ──────────────────────────────────────

	/**
	 * Čte jen starou DB (žádný zápis) — běží beze změny i v dry-run.
	 * Booleany vrací jako booleany, int jako int — endpoint normalizuje sám.
	 *
	 * @return array<string, mixed>
	 */
	private function deriveValues(): array
	{
		[$currency, $startMonth] = $this->deriveFromFiscalYear();
		$vatAgenda = $this->deriveVatAgenda();

		$this->info("  economy.accountChart         = none (konstanta — osnovu dodává import)");
		$this->info("  economy.homeCurrency         = {$currency}");
		$this->info("  economy.fiscalYearStartMonth = {$startMonth}");
		$this->info("  economy.vatAgenda            = " . ($vatAgenda ? 'true' : 'false'));

		return [
			'economy.accountChart'         => 'none',
			'economy.homeCurrency'         => $currency,
			'economy.fiscalYearStartMonth' => $startMonth,
			'economy.vatAgenda'            => $vatAgenda,
		];
	}

	/**
	 * homeCurrency + fiscalYearStartMonth z jednoho fiskálního roku: nejdřív
	 * rok pokrývající dnešek (replika Utils::homeCurrency ve starém Shipardu),
	 * fallback poslední rok, fallback 'czk' / 1 s warn().
	 *
	 * @return array{string, int}  [currency, startMonth]
	 */
	private function deriveFromFiscalYear(): array
	{
		$today = date('Y-m-d');
		$row = $this->db()->query(
			'SELECT [start], [currency] FROM [e10doc_base_fiscalyears]',
			' WHERE [docState] != %i', 9800,
			' AND [start] <= %d', $today, ' AND [end] >= %d', $today,
			' ORDER BY [start] DESC',
		)->fetch();
		$source = 'fiskální rok pokrývající dnešek';

		if (!$row)
		{
			$row = $this->db()->query(
				'SELECT [start], [currency] FROM [e10doc_base_fiscalyears]',
				' WHERE [docState] != %i', 9800,
				' ORDER BY [start] DESC',
			)->fetch();
			$source = 'poslední fiskální rok (žádný nepokrývá dnešek)';
		}

		if (!$row)
		{
			$this->warn("settings: stará DB nemá žádný fiskální rok — homeCurrency='czk', fiscalYearStartMonth=1 (fallback).");
			return ['czk', 1];
		}

		$r = (array) $row;

		$currency = strtolower(trim((string) ($r['currency'] ?? '')));
		if ($currency === '')
		{
			$this->warn("settings: fiskální rok ({$source}) má prázdnou měnu — homeCurrency='czk' (fallback).");
			$currency = 'czk';
		}

		$startMonth = $this->monthOf($r['start'] ?? null);
		if ($startMonth === null)
		{
			$this->warn("settings: fiskální rok ({$source}) má neplatné [start] — fiscalYearStartMonth=1 (fallback).");
			$startMonth = 1;
		}

		$this->debug("settings: zdroj homeCurrency/fiscalYearStartMonth: {$source}.");
		return [$currency, $startMonth];
	}

	/**
	 * true ⇔ existuje aspoň jedna registrace k DPH. Stejný diskriminátor jako
	 * VatRegistrationsRunner::sourceQuery() — sloupec [taxType] = 'vat';
	 * NE [taxArea], ten drží daňovou oblast (viz komentář tam).
	 */
	private function deriveVatAgenda(): bool
	{
		$count = (int) $this->db()->query(
			'SELECT COUNT(*) FROM [e10doc_base_taxRegs]',
			' WHERE [taxType] = %s', 'vat',
			' AND [docState] != %i', 9800,
		)->fetchSingle();

		return $count > 0;
	}

	/** Dibi DateTime / string → měsíc 1–12, null když nejde odvodit. */
	private function monthOf(mixed $date): ?int
	{
		if ($date instanceof \DateTimeInterface)
			return (int) $date->format('n');
		if (is_string($date) && $date !== '' && $date !== '0000-00-00')
		{
			$ts = strtotime($date);
			if ($ts !== false)
				return (int) date('n', $ts);
		}
		return null;
	}

	// ── Odpověď endpointu ────────────────────────────────────────────────

	/**
	 * Odpověď má tvar {success, data: {items, parameters, currencyOptions,
	 * warnings}} (shpd:SetupController::saveParameters → buildState()+warnings).
	 * `items` jsou jen NEvyřešené položky checklistu (vše nastaveno → prázdný
	 * list), `warnings` stringy z běhu provisionerů — na DS se skipProvisioning
	 * je tu očekávané varování o přeskočeném seedování.
	 *
	 * @param array<string, mixed> $resp
	 */
	private function reportResponse(array $resp): void
	{
		$d = is_array($resp['data'] ?? null) ? $resp['data'] : $resp;

		$warnings = is_array($d['warnings'] ?? null) ? $d['warnings'] : [];
		foreach ($warnings as $w)
		{
			if (is_string($w) && $w !== '')
				$this->warn("settings: {$w}");
		}

		if (is_array($d['items'] ?? null))
		{
			$n = count($d['items']);
			if ($n === 0)
				$this->info("settings: setup checklist je čistý (0 zbývajících položek).");
			else
				$this->info("settings: setup checklist má ještě {$n} položek — viz panel dsSetup na cíli.");
		}
	}
}
