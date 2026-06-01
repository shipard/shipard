<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\LocalIdMap;

/**
 * Import měrných jednotek ze starého Shipardu do nového (core_units).
 *
 * Proč samostatně (Fáze 02b): při importu na DS běží skipProvisioning=true,
 * takže UnitsProvisioner core_units nenaplní. Items (Fáze 04) i docs (Fáze 05)
 * ale mapují `unit` přes UnitResolver, který v prázdné tabulce nic nenajde.
 *
 * DVA zdroje jednotek ve starém Shipardu:
 *   1. Systémové — definované jen v configu e10.witems.units.json (kód →
 *      {text, shortcut}). NEJSOU v DB tabulce. Items/docs je referencují
 *      přímo kódem: `pcs`, `hr`, `stdpage`, `word`, `dgrcls`, …
 *   2. Uživatelské — řádky v e10_witems_units. Items/docs je referencují
 *      klíčem `_<ndx>` (tak je starý Shipard merguje do configu, viz
 *      TableUnits::saveConfig), NE přes shortcut.
 *
 * Klíč k resolvování (rozhodnuto): každá jednotka se importuje se
 * `system_code = původní klíč` (kód pro systémové, `_<ndx>` pro uživatelské).
 * UnitResolver pak každý token z items/docs trefí přímo přes system_code
 * probe — systémové i uživatelské. shortcut fallback se nepoužívá, takže
 * shody zkratek mezi systémovou a uživatelskou jednotkou nevadí.
 *
 * `quantity` (NOT NULL) přiřadíme z mapy (systémové podle kódu, uživatelské
 * podle shortcutu); neznámé → "other". `coefficient`/`is_base` nevyplňujeme.
 */
final class UnitsRunner extends BaseCodebookRunner
{
	/** Relativní cesta ke configu systémových jednotek (od modules/). */
	private const SYS_UNITS_CFG = 'e10/witems/config/e10.witems.units.json';

	/**
	 * Veličina pro systémové jednotky podle jejich kódu (= klíč v configu).
	 * Kódy mimo mapu → "other" (stdpage, word, dgrcls).
	 */
	private const SYS_UNIT_QUANTITY = [
		'pcs'  => 'count',
		'hr'   => 'time',  'hr_2' => 'time', 'hr_4' => 'time',
		'day'  => 'time',  'mnth' => 'time', 'year' => 'time',
		'm'    => 'length', 'km'  => 'length',
		'm2'   => 'area',
		'm3'   => 'volume', 'l'   => 'volume',
		'kg'   => 'weight', 'g'   => 'weight', 't' => 'weight',
		'kwh'  => 'energy', 'mwh' => 'energy', 'gj' => 'energy',
	];

	/**
	 * Veličina pro uživatelské jednotky podle (lowercase) shortcutu. Kopie
	 * shortcut→quantity z nov_shipard:unitsSeed.jsonc + UnitResolver::ALIASES.
	 * Slouží jen k vyplnění `quantity` (NOT NULL) — system_code u DB jednotek
	 * je vždy `_<ndx>`, ne ISO kód. Neznámé shortcuty → "other".
	 */
	private const SHORTCUT_QUANTITY = [
		'ks' => 'count', 'kus' => 'count', 'pc' => 'count', 'pcs' => 'count', 'piece' => 'count',
		'hod' => 'time', 'h' => 'time', '30min' => 'time', '15min' => 'time',
		'den' => 'time', 'měs' => 'time', 'mes' => 'time', 'rok' => 'time',
		'm' => 'length', 'km' => 'length',
		'm2' => 'area', 'm²' => 'area', 'm^2' => 'area', 'sqm' => 'area',
		'm3' => 'volume', 'm³' => 'volume', 'm^3' => 'volume', 'l' => 'volume', 'ltr' => 'volume',
		'kg' => 'weight', 'g' => 'weight', 't' => 'weight',
		'kwh' => 'energy', 'mwh' => 'energy', 'gj' => 'energy',
	];

	protected function entityType(): string  { return LocalIdMap::ENTITY_UNIT; }
	protected function targetTable(): string { return 'core_units'; }
	protected function entityLabel(): string { return 'unit'; }

	protected function sourceQuery(): array
	{
		return [
			'SELECT * FROM [e10_witems_units]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [ndx]',
		];
	}

	/**
	 * Sloučí uživatelské jednotky (DB) a systémové jednotky (JSON config) do
	 * jednoho seznamu. Každý řádek dostane:
	 *   - `_unitKey`  string  původní klíč = budoucí system_code (`_<ndx>` / kód)
	 *   - `ndx`       int     pro DB reálné, pro systémové syntetické (záporné,
	 *                         stabilní napříč běhy → idempotence v LocalIdMap)
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetchSourceRows(): array
	{
		$rows = parent::fetchSourceRows();  // uživatelské jednotky z DB
		foreach ($rows as &$r)
		{
			$r['_unitKey'] = '_' . (int) ($r['ndx'] ?? 0);
			$r['_source']  = 'db';
		}
		unset($r);

		foreach ($this->systemUnitRows() as $sysRow)
			$rows[] = $sysRow;

		return $rows;
	}

	/**
	 * Načte systémové jednotky z e10.witems.units.json a převede je na
	 * row-shape kompatibilní s mapRow/processRow.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function systemUnitRows(): array
	{
		$base = defined('__SHPD_MODULES_DIR__') ? __SHPD_MODULES_DIR__ : (dirname(__DIR__, 4) . '/');
		$file = $base . self::SYS_UNITS_CFG;

		$json = @file_get_contents($file);
		if ($json === false)
		{
			$this->warn("system units config not found: {$file} (skipping system units)");
			return [];
		}

		$cfg = json_decode($json, true);
		$units = $cfg['units'] ?? null;
		if (!is_array($units))
		{
			$this->warn("system units config malformed (no 'units' key): {$file}");
			return [];
		}

		$out = [];
		foreach ($units as $code => $def)
		{
			$code     = (string) $code;
			$shortcut = trim((string) ($def['shortcut'] ?? ''));
			$text     = trim((string) ($def['text'] ?? ''));

			// "none" (prázdná jednotka) a prázdné definice se neimportují —
			// items/docs je mapují na NULL (viz DocsRunner::unitOrNull).
			if ($code === 'none' || ($shortcut === '' && $text === ''))
				continue;

			$out[] = [
				'ndx'      => $this->syntheticNdx($code),
				'shortcut' => $shortcut,
				'fullName' => $text,
				'docState' => 4000,        // Potvrzeno → mapDocState() → 40 (V pořádku)
				'_unitKey' => $code,       // = system_code
				'_source'  => 'sys',
			];
		}

		return $out;
	}

	/**
	 * Stabilní záporné ndx pro systémovou jednotku (nemá reálné ndx v DB).
	 * Záporné → nikdy nekoliduje s reálnými (kladnými) ndx z DB. Deterministické
	 * (crc32 kódu) → druhý běh trefí stejné LocalIdMap entry → idempotence.
	 */
	private function syntheticNdx(string $code): int
	{
		return -1 - (int) (crc32($code) % 2000000000);
	}

	protected function mapRow(array $oldRow): array
	{
		$oldNdx   = (int) ($oldRow['ndx'] ?? 0);
		$unitKey  = (string) ($oldRow['_unitKey'] ?? '');
		$isSystem = ($oldRow['_source'] ?? '') === 'sys';

		$shortcut = trim((string) ($oldRow['shortcut'] ?? ''));
		$fullName = trim((string) ($oldRow['fullName'] ?? ''));

		// shortcut je NOT NULL v core_units — fallback pokud prázdný.
		if ($shortcut === '')
		{
			$shortcut = $fullName !== '' ? mb_substr($fullName, 0, 20) : $unitKey;
			$this->debug("unit {$oldNdx} ('{$unitKey}'): empty shortcut, derived '{$shortcut}'");
		}
		// name NOT NULL — fallback na shortcut.
		$name = $fullName !== '' ? $fullName : $shortcut;

		// quantity (NOT NULL): systémové podle kódu, uživatelské podle shortcutu.
		$quantity = $isSystem
			? (self::SYS_UNIT_QUANTITY[$unitKey] ?? 'other')
			: (self::SHORTCUT_QUANTITY[mb_strtolower($shortcut)] ?? 'other');

		return [
			'name'        => mb_substr($name, 0, 50),
			'shortcut'    => mb_substr($shortcut, 0, 20),
			'system_code' => mb_substr($unitKey, 0, 25),  // = původní token (kód / `_<ndx>`)
			'quantity'    => $quantity,
			'coefficient' => null,
			'is_base'     => 0,
		];
	}
}
