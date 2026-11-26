<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Safety;

use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Trasa zprávy slovy pro uživatele (#171 D9) — jedna věta pro detail
 * registrace i pro výsledek akcí: kam zprávy jdou, pod jakou identitou,
 * a když nikam, proč.
 */
final class MessageRouteText
{
    public const string TONE_INFO = 'info';
    public const string TONE_WARNING = 'warning';

    /**
     * @return array{tone: string, title: string, text: string}
     */
    public static function describe(MessageRoute $route, ?SalesRegistrationType $type, Texts $t): array
    {
        if ($route->dropped) {
            return [
                'tone'  => self::TONE_WARNING,
                'title' => $t->t('route.droppedTitle', 'Messages are not sent'),
                'text'  => match ($route->dropReason) {
                    MessageRoute::DROP_DEFAULT => $t->t(
                        'route.dropDefault',
                        'Server safety switch: this server is not marked as production and has no salesRegistration section in server.json — nothing is sent.',
                    ),
                    MessageRoute::DROP_INVALID => $t->t(
                        'route.dropInvalid',
                        'Server safety switch: the salesRegistration section in server.json is invalid ({problem}) — nothing is sent.',
                        ['problem' => (string) $route->problem],
                    ),
                    MessageRoute::DROP_UNKNOWN_MODE => $t->t(
                        'route.dropUnknownMode',
                        'The registration has an unknown mode — nothing is sent.',
                    ),
                    default => $t->t(
                        'route.dropConfigured',
                        'Server safety switch is set to drop — nothing is sent.',
                    ),
                },
            ];
        }

        if ($route->usesTestIdentity()) {
            $identity = $type !== null ? PlaygroundIdentity::forType($type->id) : null;
            return [
                'tone'  => self::TONE_INFO,
                'title' => $t->t('route.playgroundTitle', 'Messages go to the playground'),
                'text'  => $identity !== null
                    ? $t->t(
                        'route.playground',
                        'Messages go to the non-production environment under the shared test identity {taxpayerId}, never under the certificate of this registration. Nothing is registered.',
                        ['taxpayerId' => $identity->taxpayerId()],
                    )
                    : $t->t(
                        'route.playgroundNoIdentity',
                        'Messages should go to the non-production environment, but this registration type has no test identity — nothing can be sent.',
                    ),
            ];
        }

        return $route->verification
            ? [
                'tone'  => self::TONE_INFO,
                'title' => $t->t('route.verificationTitle', 'Messages go to production in verification mode'),
                'text'  => $t->t(
                    'route.verification',
                    'Messages are signed with the certificate of this registration and sent to the production service in verification mode. Nothing is registered and the obligation is not fulfilled.',
                ),
            ]
            : [
                'tone'  => self::TONE_INFO,
                'title' => $t->t('route.productionTitle', 'Messages go to production'),
                'text'  => $t->t(
                    'route.production',
                    'Messages are signed with the certificate of this registration and registered with the tax authority.',
                ),
            ];
    }
}
