<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Nginx;

use PHPUnit\Framework\TestCase;

/**
 * Pravidla šablon nginx v `docs/nginx/` (#183 D4, D5, U6, U7, U14) — site
 * soubory na serverech z nich vznikají a verzované includy si berou přímo
 * z checkoutu, takže chyba v šabloně je chyba na každém serveru po `git pull`.
 */
class NginxTemplatesTest extends TestCase
{
    private const string INCLUDE_PREFIX = '/opt/shipard/shpd/docs/nginx/';

    private function read(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/docs/nginx/' . $name;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /** Obsah bez komentářů — zakomentovaná direktiva se nepočítá. */
    private function directives(string $name): string
    {
        return preg_replace('/#[^\n]*/', '', $this->read($name)) ?? '';
    }

    /** @return list<string> jména souborů includovaných z checkoutu */
    private function includes(string $name): array
    {
        preg_match_all('#include\s+' . preg_quote(self::INCLUDE_PREFIX, '#') . '([^;\s]+);#', $this->directives($name), $m);
        return array_values(array_unique($m[1]));
    }

    public function testObsoleteCombinedTemplateIsGone(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 3) . '/docs/nginx/app.conf');
    }

    public function testEveryVersionedIncludeExists(): void
    {
        foreach (['production.conf', 'development.conf', 'development-https.conf', 'shipard-tls.conf'] as $template) {
            foreach ($this->includes($template) as $included) {
                $this->assertFileExists(
                    dirname(__DIR__, 3) . '/docs/nginx/' . $included,
                    "{$template} includuje neexistující {$included}",
                );
            }
        }
    }

    public function testTlsPolicyKeepsNameAndAddsHstsToParams(): void
    {
        // U7: produkční site soubory includují shipard-tls.conf a nesmí se měnit.
        $this->assertSame(['shipard-tls-params.conf'], $this->includes('shipard-tls.conf'));
        $this->assertStringContainsString('Strict-Transport-Security', $this->directives('shipard-tls.conf'));
        $this->assertContains('shipard-tls.conf', $this->includes('production.conf'));
    }

    public function testTlsParamsCarryNoHsts(): void
    {
        $params = $this->directives('shipard-tls-params.conf');
        $this->assertStringNotContainsString('Strict-Transport-Security', $params);
        $this->assertStringNotContainsString('add_header', $params);
        $this->assertMatchesRegularExpression('/ssl_protocols\s+TLSv1\.3;/', $params);
        $this->assertStringContainsString('ssl_session_cache', $params);
        $this->assertStringContainsString('ssl_ecdh_curve', $params);
        // Parametry jsou jen v jednom souboru.
        $this->assertStringNotContainsString('ssl_protocols', $this->directives('shipard-tls.conf'));
    }

    public function testDevelopmentHttpsHasNoHsts(): void
    {
        // D5: vývoj bere jen parametry TLS.
        $includes = $this->includes('development-https.conf');
        $this->assertContains('shipard-tls-params.conf', $includes);
        $this->assertNotContains('shipard-tls.conf', $includes);
        $this->assertStringNotContainsString('Strict-Transport-Security', $this->directives('development-https.conf'));
        $this->assertStringNotContainsString('Strict-Transport-Security', $this->directives('development-locations.conf'));
    }

    public function testDevelopmentHttpHasNoTls(): void
    {
        $http = $this->directives('development.conf');
        $this->assertStringNotContainsString('ssl', $http);
        $this->assertSame(
            ['shipard-common.conf', 'development-locations.conf'],
            $this->includes('development.conf'),
        );
    }

    public function testSharedLocationsAreOnlyInTheInclude(): void
    {
        // D4: bloky location společné pro http i https jsou jen v includu…
        $locations = $this->directives('development-locations.conf');
        $this->assertStringContainsString('location /app/', $locations);
        $this->assertStringContainsString('location = /app', $locations);
        $this->assertStringContainsString('location / {', $locations);
        // …a bez bloku PHP — socket FPM do verzovaného souboru nepatří (U6).
        $this->assertStringNotContainsString('fastcgi_pass', $locations);
        $this->assertStringNotContainsString('server {', $locations);

        foreach (['development.conf', 'development-https.conf'] as $template) {
            $this->assertContains('development-locations.conf', $this->includes($template));
            preg_match_all('/location\s+([^{]+)\{/', $this->directives($template), $m);
            $this->assertSame(
                ['~ \.php$'],
                array_values(array_unique(array_map('trim', $m[1]))),
                "{$template} smí mít vlastní jen blok PHP",
            );
        }
    }

    public function testDevelopmentPhpBlocksPassHostWithPort(): void
    {
        // U14: $host port zahazuje, základní adresa ho potřebuje.
        foreach (['development.conf', 'development-https.conf'] as $template) {
            $content = $this->directives($template);
            $this->assertStringContainsString('fastcgi_param HTTP_HOST $http_host;', $content, $template);
            $this->assertStringNotContainsString('fastcgi_param HTTP_HOST $host;', $content, $template);
            $this->assertSame(
                substr_count($content, 'location ~ \.php$'),
                substr_count($content, 'fastcgi_param HTTP_HOST $http_host;'),
                $template,
            );
        }
    }

    public function testDevelopmentHttpsBlocksAreDelimitedByMarkers(): void
    {
        $lines = explode("\n", $this->read('development-https.conf'));
        foreach (['https-ip', 'https-host'] as $block) {
            $this->assertCount(1, array_filter($lines, fn (string $l) => $l === "# >>> {$block}"), "začátek bloku {$block}");
            $this->assertCount(1, array_filter($lines, fn (string $l) => $l === "# <<< {$block}"), "konec bloku {$block}");
        }

        $ip = $this->block('https-ip');
        $host = $this->block('https-host');

        // Blok pro IP: default_server na 443 (bez SNI rozhoduje výchozí server), certifikát dev-ip.
        $this->assertMatchesRegularExpression('/listen 443 ssl default_server;/', $ip);
        $this->assertStringContainsString('/etc/shipard/tls/dev-ip/fullchain.pem', $ip);
        $this->assertStringContainsString('/etc/shipard/tls/dev-ip/privkey.pem', $ip);
        $this->assertStringNotContainsString('dev-host', $ip);

        // Blok pro jméno: jméno dosadí generátor, žádný catch-all ani default_server.
        $this->assertStringContainsString('server_name __SERVER_NAME__;', $host);
        $this->assertStringNotContainsString('default_server', preg_replace('/#[^\n]*/', '', $host) ?? '');
        $this->assertStringContainsString('/etc/shipard/tls/dev-host/fullchain.pem', $host);
        $this->assertStringContainsString('/etc/shipard/tls/dev-host/privkey.pem', $host);
        $this->assertStringNotContainsString('dev-ip', $host);

        // Každý blok je celý server blok s vlastním blokem PHP.
        foreach ([$ip, $host] as $body) {
            $this->assertSame(1, substr_count($body, "\nserver {"));
            $this->assertSame(1, substr_count($body, 'fastcgi_pass unix:/run/php/'));
        }

        // Mimo značky není žádná direktiva — vypnutý vstup nesmí nic zanechat.
        $outside = str_replace([$ip, $host], '', $this->read('development-https.conf'));
        $this->assertSame('', trim(preg_replace('/#[^\n]*/', '', $outside) ?? ''));
    }

    public function testIpv6ListenLinesAreMarked(): void
    {
        // U6: `listen [::]:443` jen se zapnutým IPv6 — řádek nese značku pro generátor.
        $ipv6 = 0;
        foreach (explode("\n", $this->read('development-https.conf')) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (str_contains($line, '[::]')) {
                $this->assertMatchesRegularExpression('/;\s*# ipv6$/', $line);
                $ipv6++;
            } else {
                $this->assertStringNotContainsString('# ipv6', $line);
            }
        }
        $this->assertSame(2, $ipv6);
    }

    /** Tělo bloku mezi značkami (bez řádků značek). */
    private function block(string $name): string
    {
        $content = $this->read('development-https.conf');
        $start = strpos($content, "\n# >>> {$name}\n");
        $end = strpos($content, "\n# <<< {$name}\n");
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertGreaterThan($start, $end);
        $from = $start + strlen("\n# >>> {$name}");
        return substr($content, $from, $end - $from + 1);
    }
}
