<?php

namespace imports\newShipard\libs;

/**
 * Sdílený akumulátor statistik napříč fázemi importu. Každý runner po
 * zpracování řádku zavolá add(entityLabel, status); orchestrátor (`all`)
 * z toho na konci sestaví souhrn per entita.
 *
 * Žije v ImportContext, takže ho sdílí všechny runnery jednoho běhu.
 */
final class ImportStats
{
	/** @var array<string, array{created:int,updated:int,skipped:int,failed:int}> */
	private array $byEntity = [];

	public function add(string $entity, string $status): void
	{
		if (!isset($this->byEntity[$entity]))
			$this->byEntity[$entity] = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
		if (isset($this->byEntity[$entity][$status]))
			$this->byEntity[$entity][$status]++;
	}

	/** @return array<string, array{created:int,updated:int,skipped:int,failed:int}> */
	public function byEntity(): array { return $this->byEntity; }

	public function totalFailed(): int
	{
		$n = 0;
		foreach ($this->byEntity as $s) $n += $s['failed'];
		return $n;
	}
}
