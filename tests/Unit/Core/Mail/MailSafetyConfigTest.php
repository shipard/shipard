<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Mail\MailSafetyConfig;

/** Pojistka odchozí pošty — `mail.safety` v `server.json` a výchozí stavy (#95 D1, D2). */
class MailSafetyConfigTest extends TestCase
{
    /** @param array<string, mixed>|null $section */
    private function config(?array $section, string $mode = 'production'): MailSafetyConfig
    {
        $data = ['mode' => $mode];
        if ($section !== null) {
            $data['mail'] = ['safety' => $section];
        }
        return MailSafetyConfig::fromServerData($data);
    }

    // ── výchozí stav bez sekce ──────────────────────────────────────

    public function testProductionWithoutSectionIsOff(): void
    {
        $config = $this->config(null, 'production');

        $this->assertSame(MailSafetyConfig::MODE_OFF, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_DEFAULT, $config->source);
        $this->assertFalse($config->isActive());
        $this->assertNull($config->problem);
    }

    public function testDevelopmentWithoutSectionDrops(): void
    {
        $config = $this->config(null, 'development');

        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_DEFAULT, $config->source);
        $this->assertTrue($config->isActive());
    }

    public function testUnknownServerModeWithoutSectionDrops(): void
    {
        // Bez pojistky posílá jen server výslovně označený jako produkční.
        $this->assertSame(MailSafetyConfig::MODE_DROP, $this->config(null, 'staging')->mode);
        $this->assertSame(MailSafetyConfig::MODE_DROP, MailSafetyConfig::fromServerData([])->mode);
    }

    public function testRelayAloneDoesNotCountAsSafetySection(): void
    {
        $config = MailSafetyConfig::fromServerData([
            'mode' => 'development',
            'mail' => ['relay' => ['host' => 'smtp.example.com']],
        ]);

        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_DEFAULT, $config->source);
    }

    // ── platná sekce ────────────────────────────────────────────────

    public function testRedirect(): void
    {
        $config = $this->config(['mode' => 'redirect', 'redirectTo' => ' testy@example.com ']);

        $this->assertSame(MailSafetyConfig::MODE_REDIRECT, $config->mode);
        $this->assertSame('testy@example.com', $config->redirectTo);
        $this->assertSame(MailSafetyConfig::SOURCE_CONFIGURED, $config->source);
        $this->assertTrue($config->isActive());
    }

    public function testAllowlistNormalizesEntries(): void
    {
        $config = $this->config([
            'mode'       => 'allowlist',
            'redirectTo' => 'testy@example.com',
            'allow'      => ['@Example.com', ' Jan@Example.org ', '@example.com'],
        ]);

        $this->assertSame(MailSafetyConfig::MODE_ALLOWLIST, $config->mode);
        $this->assertSame(['@example.com', 'jan@example.org'], $config->allow);
        $this->assertSame('testy@example.com', $config->redirectTo);
    }

    public function testAllowlistWithoutRedirectTo(): void
    {
        $config = $this->config(['mode' => 'allowlist', 'allow' => ['@example.com']]);

        $this->assertSame(MailSafetyConfig::MODE_ALLOWLIST, $config->mode);
        $this->assertNull($config->redirectTo);
    }

    public function testExplicitOffOnDevelopmentServer(): void
    {
        $config = $this->config(['mode' => 'off'], 'development');

        $this->assertSame(MailSafetyConfig::MODE_OFF, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_CONFIGURED, $config->source);
    }

    public function testExplicitDropOnProductionServer(): void
    {
        $config = $this->config(['mode' => 'drop'], 'production');

        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_CONFIGURED, $config->source);
    }

    // ── chybná sekce = nic neodejde ─────────────────────────────────

    /** @return array<string, array{mixed, string}> */
    public static function invalidSections(): array
    {
        return [
            'section is not an object'  => ['redirect', "/'mail\\.safety' must be an object/"],
            'missing mode'              => [['redirectTo' => 'testy@example.com'], '/mode/'],
            'unknown mode'              => [['mode' => 'forward'], '/mode/'],
            'mode is not a string'      => [['mode' => true], '/mode/'],
            'redirect without address'  => [['mode' => 'redirect'], '/redirectTo.*required/'],
            'invalid redirectTo'        => [['mode' => 'redirect', 'redirectTo' => 'testy'], '/redirectTo/'],
            'redirectTo is not a string' => [['mode' => 'redirect', 'redirectTo' => ['a@example.com']], '/redirectTo/'],
            'allowlist without allow'   => [['mode' => 'allowlist'], '/allow.*required/'],
            'allowlist with empty allow' => [['mode' => 'allowlist', 'allow' => []], '/allow.*required/'],
            'allow is not a list'       => [['mode' => 'allowlist', 'allow' => '@example.com'], '/allow/'],
            'invalid address in allow'  => [['mode' => 'allowlist', 'allow' => ['@example.com', 'jan@']], "/'jan@'/"],
            'invalid domain in allow'   => [['mode' => 'allowlist', 'allow' => ['@']], '/allow/'],
            'bare domain in allow'      => [['mode' => 'allowlist', 'allow' => ['example.com']], '/allow/'],
            'non-string in allow'       => [['mode' => 'allowlist', 'allow' => [12]], '/allow/'],
            // Neplatná adresa shodí i režim, který ji nepotřebuje.
            'off with invalid redirect' => [['mode' => 'off', 'redirectTo' => 'testy'], '/redirectTo/'],
        ];
    }

    #[DataProvider('invalidSections')]
    public function testInvalidSectionDrops(mixed $section, string $problemPattern): void
    {
        // I na produkčním serveru: chybná pojistka nesmí znamenat „bez pojistky“.
        $config = MailSafetyConfig::fromServerData(['mode' => 'production', 'mail' => ['safety' => $section]]);

        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_INVALID, $config->source);
        $this->assertMatchesRegularExpression($problemPattern, (string) $config->problem);
    }

    public function testMailKeyThatIsNotAnObjectDrops(): void
    {
        $config = MailSafetyConfig::fromServerData(['mode' => 'production', 'mail' => 'relay']);

        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_INVALID, $config->source);
    }

    // ── nečitelný server.json ───────────────────────────────────────

    public function testUnreadableServerConfigDrops(): void
    {
        $config = MailSafetyConfig::forServer(new ServerConfig('/nonexistent/shipard/server.json'));
        // Nenačtený config nemá režim serveru ani sekci.
        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);

        $throwing = $this->createMock(ServerConfig::class);
        $throwing->method('getMailSafety')->willThrowException(new \RuntimeException('Invalid JSON'));
        $config = MailSafetyConfig::forServer($throwing);

        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_INVALID, $config->source);
        $this->assertStringContainsString('Invalid JSON', (string) $config->problem);
    }

    public function testForServerReadsLoadedServerConfig(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shpd-safety-');
        file_put_contents($path, json_encode([
            'host' => 'localhost', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'x',
            'mode' => 'development',
            'mail' => ['safety' => ['mode' => 'redirect', 'redirectTo' => 'testy@example.com']],
        ]));

        try {
            $serverConfig = new ServerConfig($path);
            $serverConfig->load();

            $config = MailSafetyConfig::forServer($serverConfig);
        } finally {
            unlink($path);
        }

        $this->assertSame(MailSafetyConfig::MODE_REDIRECT, $config->mode);
        $this->assertSame('testy@example.com', $config->redirectTo);
    }

    public function testFailClosed(): void
    {
        $config = MailSafetyConfig::failClosed('server.json cannot be read');

        $this->assertSame(MailSafetyConfig::MODE_DROP, $config->mode);
        $this->assertSame(MailSafetyConfig::SOURCE_INVALID, $config->source);
        $this->assertSame('server.json cannot be read', $config->problem);
    }

    // ── povolené adresy ─────────────────────────────────────────────

    public function testAllowsExactAddressAndDomainCaseInsensitively(): void
    {
        $config = $this->config(['mode' => 'allowlist', 'allow' => ['@example.com', 'jan@example.org']]);

        $this->assertTrue($config->allows('kdokoli@example.com'));
        $this->assertTrue($config->allows('Kdokoli@EXAMPLE.com'));
        $this->assertTrue($config->allows('JAN@example.org'));
        $this->assertFalse($config->allows('petr@example.org'));
        // Doména se porovnává celá, ne jako přípona.
        $this->assertFalse($config->allows('kdokoli@sub.example.com'));
        $this->assertFalse($config->allows('kdokoli@notexample.com'));
    }
}
