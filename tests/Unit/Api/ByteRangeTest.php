<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\ByteRange;
use Shipard\Api\ByteRangeUnsatisfiable;
use Shipard\Api\Response;

/**
 * Hlavička `Range` pro video (tasks/testing-phase7b.md D127): jeden
 * rozsah `a-b` / `a-` / `-n`, oříznutí na konec souboru, nesrozumitelná
 * hlavička = celý soubor, rozsah mimo soubor = 416; odpověď `Response::file`.
 */
final class ByteRangeTest extends TestCase
{
	/** @return list<array{0: ?string, 1: int, 2: array{int, int}|null}> */
	public static function ranges(): array
	{
		return [
			[null, 1000, null],
			['', 1000, null],
			['bytes=0-499', 1000, [0, 499]],
			['bytes=500-999', 1000, [500, 999]],
			['bytes=500-', 1000, [500, 999]],
			['bytes=-300', 1000, [700, 999]],
			['bytes=-5000', 1000, [0, 999]],
			['bytes=0-5000', 1000, [0, 999]],
			['bytes = 10 - 20', 1000, [10, 20]],
			['bytes=0-0', 1000, [0, 0]],
			['bytes=999-999', 1000, [999, 999]],
			['bytes=0-499,600-700', 1000, null],
			['items=0-1', 1000, null],
			['bytes=-', 1000, null],
			['bytes=abc', 1000, null],
			['bytes=20-10', 1000, null],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
	public function testResolve(?string $header, int $size, ?array $expected): void
	{
		$range = ByteRange::resolve($header, $size);
		if ($expected === null) {
			$this->assertNull($range, (string) $header);
			return;
		}
		$this->assertNotNull($range, (string) $header);
		$this->assertSame($expected, [$range->start, $range->end], (string) $header);
		$this->assertSame($expected[1] - $expected[0] + 1, $range->length());
		$this->assertSame("bytes {$expected[0]}-{$expected[1]}/{$size}", $range->contentRange());
	}

	public function testUnsatisfiableRanges(): void
	{
		foreach (['bytes=1000-' => 1000, 'bytes=1000-1200' => 1000, 'bytes=5-' => 0, 'bytes=-0' => 1000] as $header => $size) {
			try {
				ByteRange::resolve($header, $size);
				$this->fail("expected 416 for {$header}");
			} catch (ByteRangeUnsatisfiable $e) {
				$this->assertSame($size, $e->size, $header);
			}
		}
		$this->assertSame('bytes */1000', ByteRange::unsatisfiableRange(1000));
		$this->expectException(\InvalidArgumentException::class);
		new ByteRange(5, 4, 10);
	}

	public function testFileResponseHeaders(): void
	{
		$full = Response::file('/tmp/x.mp4', 'video/mp4', 1000);
		$this->assertSame(200, $full->getStatus());
		$this->assertSame('/tmp/x.mp4', $full->getPayload());
		$this->assertSame(['Content-Type' => 'video/mp4', 'Accept-Ranges' => 'bytes', 'Content-Length' => '1000'], $full->getHeaders());

		$partial = Response::file('/tmp/x.mp4', 'video/mp4', 1000, ByteRange::resolve('bytes=500-', 1000))
			->withHeader('Content-Disposition', 'attachment; filename="x.mp4"');
		$this->assertSame(206, $partial->getStatus());
		$this->assertSame([
			'Content-Type' => 'video/mp4',
			'Accept-Ranges' => 'bytes',
			'Content-Length' => '500',
			'Content-Range' => 'bytes 500-999/1000',
			'Content-Disposition' => 'attachment; filename="x.mp4"',
		], $partial->getHeaders());

		$notSatisfiable = Response::rangeNotSatisfiable(1000);
		$this->assertSame(416, $notSatisfiable->getStatus());
		$this->assertSame('bytes */1000', $notSatisfiable->getHeaders()['Content-Range']);
		$this->assertSame('0', $notSatisfiable->getHeaders()['Content-Length']);
	}

	public function testFileResponseSendsTheRequestedBytes(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'shpd_range_');
		file_put_contents($path, 'abcdefghij');
		try {
			ob_start();
			Response::file($path, 'video/mp4', 10, ByteRange::resolve('bytes=2-5', 10))->send();
			$body = ob_get_clean();
			$this->assertSame('cdef', $body);

			ob_start();
			Response::file($path, 'video/mp4', 10)->send();
			$this->assertSame('abcdefghij', ob_get_clean());
		} finally {
			unlink($path);
		}
	}
}
