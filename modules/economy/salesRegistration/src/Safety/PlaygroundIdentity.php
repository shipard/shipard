<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Safety;

/**
 * Sdílená testovací identita neprodukčního prostředí (#171 D9, U2) — klíč
 * a certifikát z veřejného balíku testovacích certifikátů, uložené
 * v `resources/playground/` (zdroj, platnost a obnova v tamním README).
 *
 * S testovací identitou jde ve zprávě **její** identifikátor poplatníka, ne
 * identifikátor registrace — jinak služba vrací varování o neshodě
 * s certifikátem. Certifikát zdroje dat se s ní nikdy nepoužije.
 */
final class PlaygroundIdentity
{
    public const string DIRECTORY = __DIR__ . '/../../resources/playground';

    /** Typ evidence → jméno souborů testovací identity (bez přípony). */
    public const array FILES = [
        'cz.eet2' => 'CZ00000019',
    ];

    private function __construct(
        private readonly string $keyPath,
        private readonly string $certificatePath,
    ) {
    }

    /** Testovací identita typu, nebo null, když ji typ nemá nebo soubory chybí. */
    public static function forType(string $typeId, string $directory = self::DIRECTORY): ?self
    {
        $name = self::FILES[$typeId] ?? null;
        if ($name === null) {
            return null;
        }
        $identity = new self($directory . '/' . $name . '.key', $directory . '/' . $name . '.pem');

        return is_file($identity->keyPath) && is_file($identity->certificatePath) ? $identity : null;
    }

    public function keyPem(): string
    {
        return (string) file_get_contents($this->keyPath);
    }

    public function certificatePem(): string
    {
        return (string) file_get_contents($this->certificatePath);
    }

    /** Identifikátor poplatníka testovací identity = CN certifikátu. */
    public function taxpayerId(): string
    {
        $commonName = $this->parsed()['subject']['CN'] ?? '';
        return is_array($commonName) ? (string) reset($commonName) : (string) $commonName;
    }

    public function validTo(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . (int) ($this->parsed()['validTo_time_t'] ?? 0));
    }

    /** @return array<string, mixed> */
    private function parsed(): array
    {
        $parsed = @openssl_x509_parse($this->certificatePem());
        return is_array($parsed) ? $parsed : [];
    }
}
