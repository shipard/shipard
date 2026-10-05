<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Sent\SentMessageOutboxListener;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;

/**
 * Jediný wiring point služby odchozí pošty — používají CLI příkazy
 * (mail-outbox-run, mail-send-test) a HTTP volající (auth Fáze 0b).
 * Tady se také mergí relay konfigurace: DS override ?? server default
 * (config třídy se navzájem nevidí, záměrně). Pojistka odchozí pošty
 * (`mail.safety`, #95) je jen ze serveru — zdroj dat ji přepsat nemůže.
 */
final class MailServiceFactory
{
    public static function create(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        ?ServerConfig $serverConfig = null,
    ): MailOutboxService {
        $loadError = null;
        if ($serverConfig === null) {
            try {
                $serverConfig = new ServerConfig();
                $serverConfig->load();
            } catch (\Throwable $e) {
                // Chybějící/rozbitý server.json nesmí položit DS operace —
                // bez relay skončí zpráva ve fail větvi s jasnou hláškou.
                // Pro pojistku to neplatí: bez konfigurace nic neodejde.
                $serverConfig = null;
                $loadError    = $e->getMessage();
            }
        }

        $relay  = $dsConfig->getMailRelay() ?? self::serverRelay($serverConfig);
        $safety = $serverConfig !== null
            ? $serverConfig->getMailSafety()
            : MailSafetyConfig::failClosed('server.json cannot be read: ' . $loadError);

        $service = new MailOutboxService(
            $db,
            new TransportResolver($db, $dsConfig, $relay),
            new MailComposer(new AttachmentService($db, $dsConfig->getDataSourceDir())),
            new SettingsStore($db),
            $safety,
        );

        // Odeslaná pošta si výsledek transportu propisuje k sobě (#90 D43).
        $service->addSourceListener(
            SentMessageStore::SOURCE_REF_PREFIX,
            new SentMessageOutboxListener(new SentMessageStore($db)),
        );

        return $service;
    }

    private static function serverRelay(?ServerConfig $serverConfig): ?MailRelayConfig
    {
        try {
            return $serverConfig?->getMailRelay();
        } catch (\Throwable) {
            // Chybný `mail.relay` se chová jako chybějící relay.
            return null;
        }
    }
}
