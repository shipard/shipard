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
 * (config třídy se navzájem nevidí, záměrně).
 */
final class MailServiceFactory
{
    public static function create(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        ?ServerConfig $serverConfig = null,
    ): MailOutboxService {
        $relay = $dsConfig->getMailRelay() ?? self::serverRelay($serverConfig);

        $service = new MailOutboxService(
            $db,
            new TransportResolver($db, $dsConfig, $relay),
            new MailComposer(new AttachmentService($db, $dsConfig->getDataSourceDir())),
            new SettingsStore($db),
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
            if ($serverConfig === null) {
                $serverConfig = new ServerConfig();
                $serverConfig->load();
            }
            return $serverConfig->getMailRelay();
        } catch (\Throwable) {
            // Chybějící/rozbitý server.json nesmí položit DS operace —
            // bez relay skončí zpráva ve fail větvi s jasnou hláškou.
            return null;
        }
    }
}
