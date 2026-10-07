<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\PostAnalysisDisposer;
use Shipard\Module\Core\Mail\SenderRuleConfirmedHandler;

/** Handler s podstrčeným disposerem (D8). */
class TestableSenderRuleConfirmedHandler extends SenderRuleConfirmedHandler
{
    public function __construct(private readonly PostAnalysisDisposer $stub)
    {
    }

    protected function disposer(): PostAnalysisDisposer
    {
        return $this->stub;
    }
}

/**
 * Potvrzení pravidla odesílatele → `PostAnalysisDisposer::applyToWaiting`
 * jen při přechodu do stavu 40 (z libovolného stavu), s id pravidla.
 */
final class SenderRuleConfirmedHandlerTest extends TestCase
{
    /** @return array{TestableSenderRuleConfirmedHandler, PostAnalysisDisposer&\PHPUnit\Framework\MockObject\MockObject} */
    private function handler(): array
    {
        $disposer = $this->createMock(PostAnalysisDisposer::class);
        $handler = new TestableSenderRuleConfirmedHandler($disposer);
        $handler->setDb($this->createMock(Connection::class));
        return [$handler, $disposer];
    }

    public function testTransitionToConfirmedAppliesRuleToWaitingMessages(): void
    {
        [$handler, $disposer] = $this->handler();
        $disposer->expects($this->once())->method('applyToWaiting')->with(42)->willReturn(3);

        $handler->onStateChanged('core_mail_sender_rules', ['id' => 42, 'pattern' => 'x@example.com'], 10, 40);
    }

    public function testTransitionFromRepairToConfirmedAppliesToo(): void
    {
        [$handler, $disposer] = $this->handler();
        $disposer->expects($this->once())->method('applyToWaiting')->with(42);

        $handler->onStateChanged('core_mail_sender_rules', ['id' => 42], 80, 40);
    }

    public function testOtherTransitionsAreIgnored(): void
    {
        [$handler, $disposer] = $this->handler();
        $disposer->expects($this->never())->method('applyToWaiting');

        $handler->onStateChanged('core_mail_sender_rules', ['id' => 42], 10, 90);
        $handler->onStateChanged('core_mail_sender_rules', ['id' => 42], 40, 80);
        $handler->onStateChanged('core_mail_sender_rules', ['id' => 42], 40, 10);
    }

    public function testMissingIdOrDbDoesNothing(): void
    {
        [$handler, $disposer] = $this->handler();
        $disposer->expects($this->never())->method('applyToWaiting');
        $handler->onStateChanged('core_mail_sender_rules', [], 10, 40);

        $noDb = new TestableSenderRuleConfirmedHandler($disposer);
        $noDb->onStateChanged('core_mail_sender_rules', ['id' => 42], 10, 40);
    }
}
