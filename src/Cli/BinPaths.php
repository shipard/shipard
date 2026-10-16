<?php

declare(strict_types=1);

namespace Shipard\Cli;

/**
 * Absolutní cesty k binárkám repozitáře — pro kód, který spouští
 * `shpd-ds` / `shpd-server` jako podproces (cron dispatcher, detached
 * runnery). Jediné místo s odvozením od umístění zdrojáků.
 */
final class BinPaths
{
    public static function shpdDs(): string
    {
        return self::root() . '/bin/shpd-ds';
    }

    public static function shpdServer(): string
    {
        return self::root() . '/bin/shpd-server';
    }

    /**
     * Argv prefix pro spuštění `shpd-ds`: explicitní CLI interpret, když
     * existuje — pod php-fpm je PHP_BINARY samotný fpm a shebang skriptu
     * spoléhá na `php` v PATH, který web worker mít nemusí.
     *
     * @return list<string>
     */
    public static function shpdDsCommand(): array
    {
        $php = PHP_BINDIR . '/php';
        return is_executable($php) ? [$php, self::shpdDs()] : [self::shpdDs()];
    }

    /**
     * Argv prefix pro spuštění `shpd-server` — stejné pravidlo jako
     * {@see shpdDsCommand()} (obal úloh vývojářského dashboardu z php-fpm).
     *
     * @return list<string>
     */
    public static function shpdServerCommand(): array
    {
        $php = PHP_BINDIR . '/php';
        return is_executable($php) ? [$php, self::shpdServer()] : [self::shpdServer()];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
