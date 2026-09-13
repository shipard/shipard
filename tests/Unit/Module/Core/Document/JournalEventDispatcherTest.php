<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Document\JournalEventDispatcher;

/**
 * Mechanismus JournalEventDispatcher — registrace, filtr událostí, lazy
 * instanciace, chybová sémantika (výjimka handleru se spolkne).
 */
class JournalEventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        SpyJournalHandler::$calls = [];
        ThrowingJournalHandler::$called = false;
    }

    public function testDispatchesToRegisteredHandler(): void
    {
        $dispatcher = new JournalEventDispatcher([
            ['class' => SpyJournalHandler::class, 'events' => ['journalWritten']],
        ]);

        $dispatcher->dispatchJournalWritten('doc', 7);

        $this->assertSame([['doc', 7]], SpyJournalHandler::$calls);
    }

    public function testHandlerNotSubscribedToEventIsSkipped(): void
    {
        $dispatcher = new JournalEventDispatcher([
            ['class' => SpyJournalHandler::class, 'events' => ['somethingElse']],
        ]);

        $dispatcher->dispatchJournalWritten('doc', 1);

        $this->assertSame([], SpyJournalHandler::$calls);
    }

    public function testInstanceIsReusedAcrossDispatches(): void
    {
        $dispatcher = new JournalEventDispatcher([
            ['class' => SpyJournalHandler::class, 'events' => ['journalWritten']],
        ]);

        $dispatcher->dispatchJournalWritten('bankTransaction', 3);
        $dispatcher->dispatchJournalWritten('bankTransaction', 4);

        $this->assertSame([['bankTransaction', 3], ['bankTransaction', 4]], SpyJournalHandler::$calls);
    }

    public function testExceptionInHandlerIsSwallowedAndLaterHandlersRun(): void
    {
        // Throwing handler první, spy druhý → spy se i tak zavolá, dispatch
        // nepropaguje výjimku (commit deníku už proběhl, účtování nesmí spadnout).
        $dispatcher = new JournalEventDispatcher([
            ['class' => ThrowingJournalHandler::class, 'events' => ['journalWritten']],
            ['class' => SpyJournalHandler::class, 'events' => ['journalWritten']],
        ]);

        $dispatcher->dispatchJournalWritten('doc', 9);

        $this->assertTrue(ThrowingJournalHandler::$called);
        $this->assertSame([['doc', 9]], SpyJournalHandler::$calls);
    }

    public function testInjectsItselfIntoAbstractHandlers(): void
    {
        // Handler, který sám účtuje (ClearingRerouteHandler), musí engine
        // postavit s týmž dispatcherem, aby re-derivace ledgeru proběhla.
        DispatcherAwareJournalHandler::$received = null;
        $dispatcher = new JournalEventDispatcher([
            ['class' => DispatcherAwareJournalHandler::class, 'events' => ['journalWritten']],
        ]);

        $dispatcher->dispatchJournalWritten('doc', 5);

        $this->assertSame($dispatcher, DispatcherAwareJournalHandler::$received);
    }

    public function testReentrantDispatchFromHandlerIsSafe(): void
    {
        SpyJournalHandler::$calls = [];
        ReentrantJournalHandler::$fired = false;
        $dispatcher = new JournalEventDispatcher([
            ['class' => ReentrantJournalHandler::class, 'events' => ['journalWritten']],
            ['class' => SpyJournalHandler::class, 'events' => ['journalWritten']],
        ]);

        $dispatcher->dispatchJournalWritten('doc', 5);

        // Vnořená událost proběhne celá (oba handlery) ještě před spy voláním
        // pro původní událost; vnořený handler na bankTransaction končí hned.
        $this->assertSame([['bankTransaction', 99], ['doc', 5]], SpyJournalHandler::$calls);
    }

    public function testThrowsWhenClassDoesNotImplementInterface(): void
    {
        $dispatcher = new JournalEventDispatcher([
            ['class' => NotAJournalHandler::class, 'events' => ['journalWritten']],
        ]);

        $this->expectException(\LogicException::class);
        $dispatcher->dispatchJournalWritten('doc', 1);
    }
}

class SpyJournalHandler extends AbstractJournalEventHandler
{
    /** @var list<array{0: string, 1: int}> */
    public static array $calls = [];

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$calls[] = [$sourceKind, $sourceId];
    }
}

class ThrowingJournalHandler extends AbstractJournalEventHandler
{
    public static bool $called = false;

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$called = true;
        throw new \RuntimeException('saldo handler boom');
    }
}

class DispatcherAwareJournalHandler extends AbstractJournalEventHandler
{
    public static ?JournalEventDispatcher $received = null;

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$received = $this->journalEvents;
    }
}

class ReentrantJournalHandler extends AbstractJournalEventHandler
{
    public static bool $fired = false;

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        if ($sourceKind !== 'doc' || self::$fired) {
            return;
        }
        self::$fired = true;
        $this->journalEvents?->dispatchJournalWritten('bankTransaction', 99);
    }
}

class NotAJournalHandler
{
}
