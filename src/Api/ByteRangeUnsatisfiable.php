<?php
declare(strict_types=1);

namespace Shipard\Api;

/** Hlavička `Range` žádá bajty mimo soubor → odpověď 416 ({@see ByteRange::resolve()}). */
final class ByteRangeUnsatisfiable extends \RuntimeException
{
	public function __construct(public readonly int $size)
	{
		parent::__construct('Requested range not satisfiable (size ' . max(0, $size) . ')');
	}
}
