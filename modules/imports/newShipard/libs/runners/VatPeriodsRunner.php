<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\BaseCodebookRunner;
use imports\newShipard\libs\ImportException;
use imports\newShipard\libs\LocalIdMap;

/**
 * Fáze 23: import období DPH ze starého `e10doc_base_taxperiods` do
 * `economy_codebooks_vat_periods`. Jen řádná období (periodType=0) —
 * opravná/dodatečná nový model nereprezentuje (vat_period dokladu je 1:1
 * na řádné období), takže se přeskakují s warningem.
 *
 * Vyžaduje předchozí běh VatRegistrationsRunner (FK vat_registration).
 */
final class VatPeriodsRunner extends BaseCodebookRunner
{
	/** Max počet vypsaných mezer/překryvů per kategorie v coverage reportu. */
	private const COVERAGE_WARN_LIMIT = 10;

	/** Pokrytí per starý vatReg ndx — plněno v mapRow, reportováno v run(). */
	private array $coverage = [];   // [oldVatReg => list<array{begin: string, end: string}>]

	protected function entityType(): string  { return LocalIdMap::ENTITY_VAT_PERIOD; }
	protected function targetTable(): string { return 'economy_codebooks_vat_periods'; }
	protected function entityLabel(): string { return 'vat-period'; }

	protected function sourceQuery(): array
	{
		// periodType se záměrně nefiltruje v SQL — non-zero hodnoty řeší mapRow
		// warningem + skip, tiché zahazování nechceme.
		return [
			'SELECT [ndx], [fullName], [vatReg], [periodType], [start], [end], [docState]'
			. ' FROM [e10doc_base_taxperiods]'
			. ' WHERE [docState] != %i', 9800,
			' ORDER BY [vatReg], [start]',
		];
	}

	protected function mapRow(array $oldRow): ?array
	{
		$oldNdx = (int) $oldRow['ndx'];

		$periodType = (int) ($oldRow['periodType'] ?? 0);
		if ($periodType !== 0)
		{
			$this->warn("vat-period {$oldNdx}: periodType={$periodType} (opravné/dodatečné) není v novém modelu podporováno, skipping");
			return null;
		}

		$begin = $this->dateToString($oldRow['start'] ?? null);
		$end = $this->dateToString($oldRow['end'] ?? null);
		if ($begin === null || $end === null)
		{
			$this->warn("vat-period {$oldNdx}: missing start/end date, skipping");
			return null;
		}

		// resolveFk s required=true hází ImportException pro nenamapovanou
		// nenulovou hodnotu, ale vatReg null/0 tiše vrátí null — proto ještě
		// explicitní check (období bez registrace je nevaliditní).
		$oldVatReg = (int) ($oldRow['vatReg'] ?? 0);
		$newRegId = $this->resolveFk(LocalIdMap::ENTITY_VAT_REGISTRATION, $oldVatReg, true);
		if ($newRegId === null)
			throw new ImportException("vat-period {$oldNdx}: empty vatReg — period without registration is invalid");

		$this->coverage[$oldVatReg][] = ['begin' => $begin, 'end' => $end];

		// docState nenastavujeme — processRow ho doplní přes mapDocState()
		// (9000 → 70 V archívu, 4000 → 40). locked vždy 0: starý model
		// ekvivalent nemá a zamčení historie by blokovalo editaci dokladů.
		return [
			'vat_registration' => $newRegId,
			'name'             => $this->derivePeriodName($begin, $end, (string) ($oldRow['fullName'] ?? '')),
			'date_begin'       => $begin,
			'date_end'         => $end,
			'locked'           => 0,
		];
	}

	public function run(): bool
	{
		$ok = parent::run();
		$this->reportCoverage();
		return $ok;
	}

	/**
	 * Normalizace názvu na konvenci nového Shipardu (VatPeriodsProvisioner):
	 * přesný kalendářní měsíc → "MM/YYYY", přesný kvartál → "QN/YYYY", jinak
	 * fallback na starý fullName (anomálie typu "2011/4Q" — neúplné vstupní
	 * období od data registrace).
	 */
	private function derivePeriodName(string $begin, string $end, string $fullName): string
	{
		$b = new \DateTimeImmutable($begin);
		$e = new \DateTimeImmutable($end);

		// přesně kalendářní měsíc?
		if ($b->format('d') === '01' && $e->format('Y-m-d') === $b->modify('last day of this month')->format('Y-m-d'))
			return sprintf('%02d/%04d', (int) $b->format('n'), (int) $b->format('Y'));

		// přesně kalendářní kvartál?
		$m = (int) $b->format('n');
		if ($b->format('d') === '01' && in_array($m, [1, 4, 7, 10], true)
			&& $e->format('Y-m-d') === $b->modify('+3 months -1 day')->format('Y-m-d'))
			return sprintf('Q%d/%04d', intdiv($m - 1, 3) + 1, (int) $b->format('Y'));

		// anomálie → původní název (cílový sloupec name je varchar(20))
		$name = trim($fullName);
		if ($name === '')
			return $b->format('Y-m-d');
		return mb_strlen($name) > 20 ? mb_substr($name, 0, 20) : $name;
	}

	/**
	 * Diagnostika pokrytí per registrace: počet období, rozsah, mezery a
	 * překryvy. Mezera = doklady v tom okně zůstanou s vat_period=NULL;
	 * překryv = nedeterministické dohledání (resolveVatPeriodId má LIMIT 1
	 * bez ORDER BY). Díky mapRow běžícímu i v dry-runu dává plnou diagnostiku
	 * `vat-periods --dry-run` bez jediného zápisu.
	 */
	private function reportCoverage(): void
	{
		if ($this->coverage === [])
		{
			$this->summary('  coverage: no periods imported.');
			return;
		}

		ksort($this->coverage);
		foreach ($this->coverage as $oldVatReg => $periods)
		{
			usort($periods, static fn (array $a, array $b): int => strcmp($a['begin'], $b['begin']));

			$gaps = [];
			$overlaps = [];
			for ($i = 1; $i < count($periods); $i++)
			{
				$prev = $periods[$i - 1];
				$next = $periods[$i];
				$prevEndPlusDay = (new \DateTimeImmutable($prev['end']))->modify('+1 day')->format('Y-m-d');

				if ($next['begin'] > $prevEndPlusDay)
					$gaps[] = "{$prevEndPlusDay} … " . (new \DateTimeImmutable($next['begin']))->modify('-1 day')->format('Y-m-d');
				elseif ($next['begin'] <= $prev['end'])
					$overlaps[] = "{$prev['begin']}…{$prev['end']} × {$next['begin']}…{$next['end']}";
			}

			$newRegId = $this->idMap()->lookup(LocalIdMap::ENTITY_VAT_REGISTRATION, $oldVatReg);
			$this->summary(sprintf(
				'  coverage vatReg=%d (new id=%s): %d period(s), %s … %s, gaps=%d, overlaps=%d',
				$oldVatReg, $newRegId ?? '?', count($periods),
				$periods[0]['begin'], $periods[count($periods) - 1]['end'],
				count($gaps), count($overlaps),
			));

			$this->warnLimited($oldVatReg, 'gap', $gaps);
			$this->warnLimited($oldVatReg, 'overlap', $overlaps);
		}
	}

	/** @param list<string> $items */
	private function warnLimited(int $oldVatReg, string $kind, array $items): void
	{
		foreach (array_slice($items, 0, self::COVERAGE_WARN_LIMIT) as $item)
			$this->warn("  coverage vatReg={$oldVatReg}: {$kind} {$item}");

		$rest = count($items) - self::COVERAGE_WARN_LIMIT;
		if ($rest > 0)
			$this->warn("  coverage vatReg={$oldVatReg}: … a další {$rest} ({$kind})");
	}
}
