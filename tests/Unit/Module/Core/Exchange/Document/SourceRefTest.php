<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\SourceRef;

class SourceRefTest extends TestCase
{
    public function testKeyNeedsBothKindAndRef(): void
    {
        $key = SourceRef::fromCanonical(['source' => ['kind' => 'api', 'ref' => 'A-1']]);
        $this->assertNotNull($key);
        $this->assertSame(['api', 'A-1'], [$key->kind, $key->ref]);

        foreach ([
            [],
            ['source' => null],
            ['source' => ['kind' => 'api']],
            ['source' => ['kind' => 'api', 'ref' => null]],
            ['source' => ['kind' => 'api', 'ref' => '']],
            ['source' => ['kind' => 'api', 'ref' => 42]],
            ['source' => ['kind' => '', 'ref' => 'A-1']],
            ['source' => ['ref' => 'A-1']],
            ['source' => ['kind' => 'api', 'ref' => str_repeat('x', 61)]],
        ] as $canonical) {
            $this->assertNull(SourceRef::fromCanonical($canonical), json_encode($canonical));
        }
        // Délka se počítá ve znacích jako sloupec varchar(60).
        $this->assertNotNull(SourceRef::fromCanonical(['source' => ['kind' => 'api', 'ref' => str_repeat('ř', 60)]]));
    }

    public function testRefWithoutKindIsRecognised(): void
    {
        $this->assertTrue(SourceRef::hasRefWithoutKind(['source' => ['ref' => 'A-1']]));
        $this->assertTrue(SourceRef::hasRefWithoutKind(['source' => ['kind' => '', 'ref' => 'A-1']]));
        $this->assertFalse(SourceRef::hasRefWithoutKind(['source' => ['kind' => 'api', 'ref' => 'A-1']]));
        $this->assertFalse(SourceRef::hasRefWithoutKind(['source' => ['kind' => 'api']]));
        $this->assertFalse(SourceRef::hasRefWithoutKind(['source' => ['ref' => null]]));
        $this->assertFalse(SourceRef::hasRefWithoutKind([]));
    }

    public function testMatchAndLockKeyAreExact(): void
    {
        $key = SourceRef::fromCanonical(['source' => ['kind' => 'api', 'ref' => 'A-1']]);
        $this->assertTrue($key->matches('api', 'A-1'));
        $this->assertFalse($key->matches('api', 'a-1'));
        $this->assertFalse($key->matches('API', 'A-1'));
        $this->assertFalse($key->matches('api', null));

        $other = SourceRef::fromCanonical(['source' => ['kind' => 'api', 'ref' => 'a-1']]);
        $this->assertNotSame($key->lockKey(), $other->lockKey());
        // Hranice mezi druhem a identifikátorem je součást klíče.
        $shifted = SourceRef::fromCanonical(['source' => ['kind' => 'ap', 'ref' => 'iA-1']]);
        $this->assertNotSame($key->lockKey(), $shifted->lockKey());
        $this->assertSame($key->lockKey(), SourceRef::fromCanonical(['source' => ['kind' => 'api', 'ref' => 'A-1']])->lockKey());
    }
}
