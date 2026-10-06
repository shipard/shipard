<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Sent\SentMessageTransportInfo;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Stav transportu pro rozhraní (#90 D43, #104 D4–D5): stav `unknown`
 * z číselníku jako každý jiný, importovaná zpráva bez Odeslat znovu.
 */
class SentMessageTransportInfoTest extends TestCase
{
    private function info(bool $withConfig = true): SentMessageTransportInfo
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        return new SentMessageTransportInfo($db, $withConfig ? ConfigRuntimeFactory::fromItems([
            'core.mail.transportStates' => [
                'queued'  => ['name' => 'Ve frontě', 'style' => 'warning'],
                'sent'    => ['name' => 'Odesláno', 'style' => 'success'],
                'failed'  => ['name' => 'Neodesláno', 'style' => 'danger'],
                'unknown' => ['name' => 'Nezjištěno', 'style' => 'neutral'],
            ],
        ]) : null);
    }

    /** @return array<string, mixed> */
    private function imported(array $overrides = []): array
    {
        return $overrides + [
            'id'              => 7,
            'docState'        => 40,
            'send_trigger'    => 'import',
            'transport_state' => 'unknown',
            'sent_at'         => null,
            'send_count'      => 0,
            'safety_action'   => 'redirected',
        ];
    }

    public function testUnknownStateComesFromTheCodebookWithoutSafetyBadge(): void
    {
        $state = $this->info()->state($this->imported());

        $this->assertSame('unknown', $state['state']);
        $this->assertSame('Nezjištěno', $state['stateLabel']);
        $this->assertSame('neutral', $state['stateStyle']);
        // Stopa pojistky se ukazuje jen u `sent`.
        $this->assertNull($state['safety']);
    }

    public function testUnknownStateWithoutConfigFallsBackToCodeAndNeutral(): void
    {
        $state = $this->info(false)->state($this->imported());

        $this->assertSame('unknown', $state['stateLabel']);
        $this->assertSame('neutral', $state['stateStyle']);
    }

    public function testImportedMessageIsFlaggedAndCannotBeResent(): void
    {
        $described = $this->info()->describe($this->imported(['transport_state' => 'sent', 'sent_at' => '2021-06-01 09:12:33', 'send_count' => 1]));

        $this->assertTrue($described['imported']);
        $this->assertFalse($described['canResend']);
        $this->assertSame('01.06.2021 09:12', $described['sentAt']);
        $this->assertSame(1, $described['sendCount']);
    }

    public function testSentMessageCanBeResentAndIsNotImported(): void
    {
        $described = $this->info()->describe($this->imported(['send_trigger' => 'manual', 'transport_state' => 'sent']));

        $this->assertFalse($described['imported']);
        $this->assertTrue($described['canResend']);
    }
}
