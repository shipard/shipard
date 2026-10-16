<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Feed;

/**
 * Úplnost katalogů textů feedu (#101 D16) — cfgItemy `*.feedTexts`;
 * pravidla v {@see TextsCatalogTestBase}.
 */
final class FeedTextsCatalogTest extends TextsCatalogTestBase
{
    protected static function suffix(): string
    {
        return '.feedTexts';
    }

    protected static function expected(): array
    {
        return ['core.mail.feedTexts', 'core.alerts.feedTexts', 'core.exchange.feedTexts'];
    }

    protected static function sources(): array
    {
        return [
            'core.mail.feedTexts' => [
                'modules/core/mail/src/Feed/MailSuggestionsSource.php',
                'modules/core/mail/src/Feed/MailDigestSource.php',
            ],
            'core.alerts.feedTexts'   => ['modules/core/alerts/src/Feed/AlertsSource.php'],
            'core.exchange.feedTexts' => ['modules/core/exchange/src/Dashboard/ContentTagSuggestionsSource.php'],
        ];
    }
}
