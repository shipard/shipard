<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\Posting\AssetPostingDocLockProvider;

/**
 * Zámek účetního dokladu majetku (D53): doklad s řádky `asset.*` spravuje
 * Majetek; zápis služby (marker `_systemOperations`) provider pouští.
 */
class AssetPostingDocLockProviderTest extends TestCase
{
    private function provider(bool $hasAssetRows): TestableAssetPostingDocLockProvider
    {
        $provider = new TestableAssetPostingDocLockProvider();
        $provider->hasRows = $hasAssetRows;
        return $provider;
    }

    private const DOC = ['id' => 950, 'doc_type' => 'cmnbkp', 'docState' => 40];

    public function testDocumentWithAssetRowsIsLockedInEveryState(): void
    {
        foreach ([40, 30] as $state) {
            $doc = ['docState' => $state] + self::DOC;
            $reasons = $this->provider(true)->lockReasons('docs_core_heads', ['doc_text' => 'oprava'] + $doc, $doc);

            $this->assertCount(1, $reasons);
            $this->assertSame(AssetPostingDocLockProvider::SOURCE, $reasons[0]->source);
            $this->assertSame('Doklad spravuje Majetek', $reasons[0]->title);
        }
    }

    public function testServiceWriteWithMarkerPasses(): void
    {
        $provider = $this->provider(true);

        $this->assertSame([], $provider->lockReasons(
            'docs_core_heads',
            ['docState' => 30, '_systemOperations' => true] + self::DOC,
            self::DOC,
        ));
        $this->assertSame([], $provider->queries, 'služba se na řádky neptá');
    }

    public function testOrdinaryDocumentsAreNotTouched(): void
    {
        // Nový doklad, jiný typ dokladu, účetní doklad bez řádků majetku.
        $this->assertSame([], $this->provider(true)->lockReasons('docs_core_heads', self::DOC, null));

        $invoice = ['doc_type' => 'invni'] + self::DOC;
        $provider = $this->provider(true);
        $this->assertSame([], $provider->lockReasons('docs_core_heads', $invoice, $invoice));
        $this->assertSame([], $provider->queries, 'jiný typ dokladu se na řádky neptá');

        $provider = $this->provider(false);
        $this->assertSame([], $provider->lockReasons('docs_core_heads', self::DOC, self::DOC));
        $this->assertSame([950], $provider->queries);
    }
}

class TestableAssetPostingDocLockProvider extends AssetPostingDocLockProvider
{
    public bool $hasRows = false;
    /** @var list<int> */
    public array $queries = [];

    protected function hasAssetRows(int $docHeadId): bool
    {
        $this->queries[] = $docHeadId;
        return $this->hasRows;
    }
}
