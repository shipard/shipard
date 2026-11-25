<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

/**
 * Sekce `/etc/shipard/server.json`, kterou čte jen kód modulu (#172 D7, D10).
 * Modul ji deklaruje polem `serverConfig` v `module.jsonc` (`{key, class}`),
 * třída sekci načte a zkontroluje sama — `ServerConfig` dává jen surová data
 * přes {@see ServerConfig::getSection()}. Výchozí hodnoty a chybové hlášky
 * jsou věc třídy.
 *
 * Pravidla deklarací (třída v namespace modulu, shoda klíče, kolize cest,
 * klíče jádra) hlídá {@see \Shipard\Core\Module\ModuleServerConfigRules}.
 */
interface ServerConfigSection
{
    /**
     * Cesta sekce v `server.json` — segmenty oddělené tečkou
     * (`exchangeRates`, `ai.analysis`), tatáž jako `key` v deklaraci modulu.
     */
    public static function key(): string;

    /**
     * Načte sekci a zkontroluje ji. Dostává celý `ServerConfig`, aby mohla
     * číst i režim serveru (vzor `MailSafetyConfig`).
     *
     * @throws \RuntimeException sekce je chybná — vyhazuje se tady, při
     *         použití, ne v `ServerConfig::load()`: chybná sekce jednoho
     *         modulu nesmí rozbít načtení konfigurace ostatním
     */
    public static function fromServerConfig(ServerConfig $config): static;
}
