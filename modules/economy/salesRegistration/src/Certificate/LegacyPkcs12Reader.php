<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Certificate;

use Shipard\Core\DevUpdate\UpdateEnvironment;

/**
 * Záložní čtení `.p12` se zastaralou šifrou (#171 D11): `openssl pkcs12
 * -legacy` jako podproces. PHP na OpenSSL 3 šifru RC2-40 (mají ji `.p12`
 * playgroundu EET) bez legacy provideru odmítne, příkazová řádka ji
 * s `-legacy` přečte.
 *
 * Heslo jde **jen na stdin** (`-passin stdin`) — nikdy do argumentů
 * (viditelné v `ps`), proměnných prostředí ani logu. Standardní výstup nese
 * nešifrovaný soukromý klíč: vrací se volajícímu a nikam jinam. Prostředí
 * podprocesu je pevné (PHP-FPM s `clear_env` nedává `PATH`).
 */
class LegacyPkcs12Reader
{
    public const string OUTCOME_OK = 'ok';
    public const string OUTCOME_WRONG_PASSWORD = 'wrongPassword';
    public const string OUTCOME_UNREADABLE = 'unreadable';
    public const string OUTCOME_UNAVAILABLE = 'unavailable';

    private const float TIMEOUT_SECONDS = 10.0;
    private const int MAX_OUTPUT_BYTES = 262144;

    /**
     * @return array{outcome: string, pem: string} `pem` = klíč a certifikáty v PEM (jen u `ok`)
     */
    public function read(
        string $path,
        #[\SensitiveParameter]
        string $password,
    ): array {
        $binary = $this->findBinary();
        if ($binary === null) {
            return ['outcome' => self::OUTCOME_UNAVAILABLE, 'pem' => ''];
        }
        // `-passin stdin` čte první řádek — heslo s koncem řádku by se
        // předalo jiné, než jaké uživatel zadal.
        if (str_contains($password, "\n") || str_contains($password, "\r")) {
            return ['outcome' => self::OUTCOME_WRONG_PASSWORD, 'pem' => ''];
        }

        $result = $this->run(
            [$binary, 'pkcs12', '-legacy', '-nodes', '-clcerts', '-in', $path, '-passin', 'stdin'],
            $password . "\n",
        );
        if ($result === null) {
            return ['outcome' => self::OUTCOME_UNAVAILABLE, 'pem' => ''];
        }
        if ($result['exitCode'] === 0 && str_contains($result['stdout'], '-----BEGIN ')) {
            return ['outcome' => self::OUTCOME_OK, 'pem' => $result['stdout']];
        }
        // „Mac verify error: invalid password?“ — hlášení openssl při špatném hesle.
        $wrongPassword = stripos($result['stderr'], 'mac verify') !== false
            || stripos($result['stderr'], 'invalid password') !== false;

        return ['outcome' => $wrongPassword ? self::OUTCOME_WRONG_PASSWORD : self::OUTCOME_UNREADABLE, 'pem' => ''];
    }

    /** Cesta k `openssl`, null = na serveru není (přepsatelné v testech). */
    protected function findBinary(): ?string
    {
        return UpdateEnvironment::findTool('openssl');
    }

    /**
     * Spustí příkaz bez shellu, zapíše `$stdin` a přečte oba výstupy.
     * Null = proces nešel spustit nebo nedoběhl včas.
     *
     * @param list<string> $argv
     * @return array{exitCode: int, stdout: string, stderr: string}|null
     */
    protected function run(
        array $argv,
        #[\SensitiveParameter]
        string $stdin,
    ): ?array {
        $process = @proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => UpdateEnvironment::DEFAULT_PATH, 'LC_ALL' => 'C'],
        );
        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $timedOut = false;
        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }
            $read = array_values($open);
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) min(200000, $remaining * 1000000)) === false) {
                break;
            }
            foreach ($read as $stream) {
                $index = array_search($stream, $open, true);
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$index]);
                    }
                    continue;
                }
                if (strlen($output[$index]) < self::MAX_OUTPUT_BYTES) {
                    $output[$index] .= $chunk;
                }
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        if ($timedOut) {
            proc_terminate($process, 9);
            proc_close($process);
            return null;
        }

        return ['exitCode' => proc_close($process), 'stdout' => $output[1], 'stderr' => $output[2]];
    }
}
