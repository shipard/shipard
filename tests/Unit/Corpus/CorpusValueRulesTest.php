<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Values\CanonicalValueNormalizer;
use Shipard\Module\Core\Exchange\Values\CanonicalValueValidator;

/**
 * Strážce korpusu pro pravidla hodnot (#118 D17): každé očekávání
 * (`tests/corpus/cz/expected/**`) je už v kanonickém tvaru — normalizátor
 * je nad ním identita (žádná změna, žádná oprava) a validátor nevrátí
 * nález. Jinak by evaluace měřila tvar očekávání místo modelu.
 */
final class CorpusValueRulesTest extends TestCase
{
    private const EXPECTED_DIR = __DIR__ . '/../../corpus/cz/expected';

    /** @return iterable<string, array{0: string}> */
    public static function expectedFiles(): iterable
    {
        $files = glob(self::EXPECTED_DIR . '/*/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            yield basename(dirname($file)) . '/' . basename($file) => [$file];
        }
    }

    public function testCorpusHasExpectedFiles(): void
    {
        $this->assertGreaterThan(30, iterator_count(self::expectedFiles()));
    }

    #[DataProvider('expectedFiles')]
    public function testNormalizerIsIdentityAndValidatorIsSilent(string $file): void
    {
        $doc = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($doc, CanonicalValueNormalizer::normalize($doc), 'očekávání není v kanonickém tvaru');
        $this->assertSame([], CanonicalValueValidator::validate($doc), 'očekávání nese neplatný identifikátor');
    }
}
