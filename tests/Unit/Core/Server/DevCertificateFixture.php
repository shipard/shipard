<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

/**
 * Self-signed certifikáty pro testy https vstupů (#183) — přes ext-openssl,
 * bez `openssl` CLI. Platnost se v testech posouvá časem `now`, ne certifikátem.
 */
final class DevCertificateFixture
{
    /**
     * Zapíše `fullchain.pem` a `privkey.pem` do adresáře.
     *
     * @param list<string> $ips
     * @param list<string> $names
     * @return array{cert: string, key: string} cesty souborů
     */
    public static function write(string $dir, array $ips = [], array $names = [], int $days = 397): array
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        [$cert, $key] = self::pem($ips, $names, $days);
        file_put_contents($dir . '/fullchain.pem', $cert);
        file_put_contents($dir . '/privkey.pem', $key);
        return ['cert' => $dir . '/fullchain.pem', 'key' => $dir . '/privkey.pem'];
    }

    /**
     * @param list<string> $ips
     * @param list<string> $names
     * @return array{0: string, 1: string} PEM certifikátu a klíče
     */
    public static function pem(array $ips = [], array $names = [], int $days = 397): array
    {
        $san = [];
        foreach ($ips as $i => $ip) {
            $san[] = 'IP.' . ($i + 1) . ' = ' . $ip;
        }
        foreach ($names as $i => $name) {
            $san[] = 'DNS.' . ($i + 1) . ' = ' . $name;
        }
        $config = "[req]\ndistinguished_name = dn\nx509_extensions = ext\nprompt = no\n"
            . "[dn]\nCN = test\n"
            . "[ext]\nbasicConstraints = critical, CA:FALSE\n"
            . ($san === [] ? '' : "subjectAltName = @san\n[san]\n" . implode("\n", $san) . "\n");
        $configFile = tempnam(sys_get_temp_dir(), 'shpd-cert-');
        file_put_contents($configFile, $config);

        try {
            $options = ['config' => $configFile, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext'];
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $configFile]);
            $csr = openssl_csr_new(['commonName' => 'test'], $key, $options);
            $cert = openssl_csr_sign($csr, null, $key, $days, $options);
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem, null, ['config' => $configFile]);
        } finally {
            @unlink($configFile);
        }
        return [(string) $certPem, (string) $keyPem];
    }
}
