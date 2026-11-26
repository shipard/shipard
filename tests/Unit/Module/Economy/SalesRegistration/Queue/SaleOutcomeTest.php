<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Queue;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleOutcome;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;

/**
 * Stav tržby podle výsledku enginu (#171 U5) a odstupy opakování (D7).
 */
class SaleOutcomeTest extends TestCase
{
    public function testFinalStatesFollowTheResultOfTheEngine(): void
    {
        foreach ([
            'potvrzeno'          => [ScriptedSender::confirmed(), SaleState::CONFIRMED],
            'ověřovací režim'    => [ScriptedSender::verified(), SaleState::VERIFIED],
            'pojistka serveru'   => [ScriptedSender::dropped(), SaleState::DROPPED],
            'odmítnuto službou'  => [ScriptedSender::rejectedByService(), SaleState::FAILED],
            'neplatná zpráva'    => [ScriptedSender::ourProblem(SendResult::PROBLEM_MESSAGE), SaleState::FAILED],
        ] as $label => [$result, $state]) {
            $outcome = SaleOutcome::of($result);
            $this->assertSame($state, $outcome->state, $label);
            $this->assertFalse($outcome->retry, $label);
        }
    }

    public function testOutageUnclearAnswerAndProblemsOnOurSideGoBackToPending(): void
    {
        $results = [
            'výpadek služby'  => ScriptedSender::retryLater(),
            'nejasná odpověď' => ScriptedSender::unknown(),
        ];
        foreach ([
            SendResult::PROBLEM_NO_CERTIFICATE, SendResult::PROBLEM_KEY, SendResult::PROBLEM_NO_TEST_IDENTITY,
            SendResult::PROBLEM_TYPE, SendResult::PROBLEM_ENVIRONMENT, SendResult::PROBLEM_INTERNAL,
            SaleOutcome::PROBLEM_REGISTRATION,
        ] as $problem) {
            $results['problém ' . $problem] = ScriptedSender::ourProblem($problem);
        }

        foreach ($results as $label => $result) {
            $outcome = SaleOutcome::of($result);
            $this->assertSame(SaleState::PENDING, $outcome->state, $label);
            $this->assertTrue($outcome->retry, $label);
        }
    }

    public function testBackoffGrowsToAnHourAndThenStaysThere(): void
    {
        $this->assertSame(
            [60, 300, 900, 3600, 3600, 3600],
            array_map(SaleOutcome::backoffSeconds(...), [1, 2, 3, 4, 5, 200]),
            '1 min → 5 min → 15 min → 1 h, dál po hodině bez konce',
        );
        $this->assertSame(60, SaleOutcome::backoffSeconds(0));
    }

    public function testOnlyOutageAndUnclearAnswerOpenTheCircuit(): void
    {
        $this->assertTrue(SaleOutcome::isOutage(ScriptedSender::retryLater()));
        $this->assertTrue(SaleOutcome::isOutage(ScriptedSender::unknown()));
        foreach ([ScriptedSender::confirmed(), ScriptedSender::rejectedByService(), ScriptedSender::dropped(), ScriptedSender::ourProblem(SendResult::PROBLEM_KEY)] as $result) {
            $this->assertFalse(SaleOutcome::isOutage($result));
        }
    }
}
