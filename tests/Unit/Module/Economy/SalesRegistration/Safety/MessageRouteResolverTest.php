<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Safety;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRoute;
use Shipard\Module\Economy\SalesRegistration\Safety\MessageRouteResolver;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;

/**
 * Tabulka „kam zpráva jde“ (#171 D9, D10, tasks/sales-registration-phase1.md
 * krok 4) — pojistka serveru × režim registrace × ověření žádané zprávou.
 */
class MessageRouteResolverTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool, ?string, ?string, bool}>
     *         pojistka, režim registrace, zpráva žádá ověření → prostředí, identita, ověření
     */
    public static function routes(): array
    {
        return [
            // pojistka off — rozhoduje režim registrace
            'off / production'                  => ['off', 'production', false, 'production', 'registration', false],
            'off / production / message asks'   => ['off', 'production', true, 'production', 'registration', true],
            'off / verification'                => ['off', 'verification', false, 'production', 'registration', true],
            'off / verification / message asks' => ['off', 'verification', true, 'production', 'registration', true],
            'off / playground'                  => ['off', 'playground', false, 'playground', 'test', false],
            'off / playground / message asks'   => ['off', 'playground', true, 'playground', 'test', true],
            // pojistka playground — vždy playground a testovací identita
            'playground / production'                => ['playground', 'production', false, 'playground', 'test', false],
            'playground / production / message asks' => ['playground', 'production', true, 'playground', 'test', true],
            'playground / verification'              => ['playground', 'verification', false, 'playground', 'test', true],
            'playground / playground'                => ['playground', 'playground', false, 'playground', 'test', false],
            // pojistka drop — nic
            'drop / production'   => ['drop', 'production', false, null, null, false],
            'drop / verification' => ['drop', 'verification', true, null, null, false],
            'drop / playground'   => ['drop', 'playground', false, null, null, false],
        ];
    }

    #[DataProvider('routes')]
    public function testRouteTable(
        string $safetyMode,
        string $registrationMode,
        bool $verificationRequested,
        ?string $environment,
        ?string $identity,
        bool $verification,
    ): void {
        $route = MessageRouteResolver::resolve(
            SalesRegistrationServerConfig::ofMode($safetyMode),
            $registrationMode,
            $verificationRequested,
        );

        $this->assertSame($environment === null, $route->dropped);
        $this->assertSame($environment, $route->environment);
        $this->assertSame($identity, $route->identity);
        $this->assertSame($verification, $route->verification);
        $this->assertSame($identity === MessageRoute::IDENTITY_TEST, $route->usesTestIdentity());
        if ($environment === 'production') {
            $this->assertSame(MessageRoute::IDENTITY_REGISTRATION, $route->identity, 'do produkce jen certifikát registrace');
        }
        if ($environment === 'playground') {
            $this->assertSame(MessageRoute::IDENTITY_TEST, $route->identity, 'na playground nikdy certifikát zdroje dat');
        }
    }

    public function testDropCarriesTheReason(): void
    {
        $configured = MessageRouteResolver::resolve(SalesRegistrationServerConfig::ofMode('drop'), 'production');
        $this->assertSame(MessageRoute::DROP_CONFIGURED, $configured->dropReason);
        $this->assertNull($configured->problem);

        $invalid = MessageRouteResolver::resolve(SalesRegistrationServerConfig::failClosed("'mode' is wrong"), 'production');
        $this->assertSame(MessageRoute::DROP_INVALID, $invalid->dropReason);
        $this->assertSame("'mode' is wrong", $invalid->problem);

        $this->assertNull(MessageRouteResolver::resolve(SalesRegistrationServerConfig::ofMode('off'), 'production')->dropReason);
    }

    public function testUnknownRegistrationModeIsNeverSent(): void
    {
        foreach (['off', 'playground'] as $safety) {
            $route = MessageRouteResolver::resolve(SalesRegistrationServerConfig::ofMode($safety), 'live');

            $this->assertTrue($route->dropped, $safety);
            $this->assertSame(MessageRoute::DROP_UNKNOWN_MODE, $route->dropReason);
        }
    }
}
