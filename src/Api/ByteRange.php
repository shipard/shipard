<?php
declare(strict_types=1);

namespace Shipard\Api;

/**
 * Jeden bajtový rozsah z hlavičky `Range` (RFC 9110 § 14) pro servírování
 * souboru po částech — přehrávač videa bez `206` a `Accept-Ranges`
 * neposouvá (tasks/testing-phase7b.md § 3, D127). Čistá hodnota bez I/O:
 * {@see resolve()} vrátí null, když hlavička chybí nebo jí nerozumíme
 * (více rozsahů, jiná jednotka, nesmysl) — pak se pošle celý soubor
 * s 200, což je podle RFC dovolené; nesplnitelný rozsah (začátek za
 * koncem souboru, prázdný soubor) vyhodí {@see ByteRangeUnsatisfiable}
 * → 416 s `Content-Range: bytes * /<velikost>`.
 */
final readonly class ByteRange
{
	public function __construct(
		public int $start,
		public int $end,
		public int $size,
	) {
		if ($start < 0 || $end < $start || $end >= $size) {
			throw new \InvalidArgumentException("Invalid byte range {$start}-{$end}/{$size}");
		}
	}

	/**
	 * @param ?string $header hodnota hlavičky `Range` (null = chybí)
	 * @param int $size velikost souboru v bajtech
	 * @return ?self null = celý soubor (bez hlavičky, nebo jí nerozumíme)
	 * @throws ByteRangeUnsatisfiable rozsah leží mimo soubor
	 */
	public static function resolve(?string $header, int $size): ?self
	{
		if ($header === null || trim($header) === '') {
			return null;
		}
		if (preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/', $header, $m) !== 1) {
			return null;
		}
		[, $first, $last] = $m;
		if ($first === '' && $last === '') {
			return null;
		}
		if ($size <= 0) {
			throw new ByteRangeUnsatisfiable($size);
		}
		if ($first === '') {
			// Posledních N bajtů.
			$suffix = (int) $last;
			if ($suffix <= 0) {
				throw new ByteRangeUnsatisfiable($size);
			}
			$start = max(0, $size - $suffix);
			return new self($start, $size - 1, $size);
		}
		$start = (int) $first;
		if ($start >= $size) {
			throw new ByteRangeUnsatisfiable($size);
		}
		$end = $last === '' ? $size - 1 : min((int) $last, $size - 1);
		if ($end < $start) {
			return null;
		}
		return new self($start, $end, $size);
	}

	public function length(): int
	{
		return $this->end - $this->start + 1;
	}

	/** Hodnota `Content-Range` pro 206. */
	public function contentRange(): string
	{
		return "bytes {$this->start}-{$this->end}/{$this->size}";
	}

	/** Hodnota `Content-Range` pro 416. */
	public static function unsatisfiableRange(int $size): string
	{
		return 'bytes */' . max(0, $size);
	}
}
