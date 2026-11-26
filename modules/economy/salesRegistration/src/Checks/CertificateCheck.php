<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\I18n\DisplayDate;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Společný základ upozornění na pokladní certifikát (#171 D13): hlídají se
 * jen **platné registrace** — ve stavu V pořádku a v době své platnosti
 * (`valid_from` / `valid_to`). Koncept, registrace v opravě, archiv
 * a registrace mimo platnost upozornění nenesou.
 *
 * Jeden nález na registraci (`findingKey` = id registrace). Texty jsou
 * z katalogu doplňku (kompilace konfigurace je dává v jazyce zdroje dat).
 * Chráněné metody jsou šev pro testy.
 */
abstract class CertificateCheck extends AlertCheck
{
    public const int SUBJECT_TABLE_ID = 468;
    public const string VIEWER_ID = 'economy.salesRegistration.registrations';

    /** Kolik dní před koncem platnosti certifikátu začíná varování. */
    public const int EXPIRING_DAYS = 30;

    /**
     * Nález pro jednu platnou registraci, nebo null.
     *
     * @param array<string, mixed> $registration
     */
    abstract protected function finding(array $registration, \DateTimeImmutable $now, Texts $t): ?AlertFinding;

    public function run(): array
    {
        $now = $this->now();
        $today = $now->format('Y-m-d');
        $t = Texts::of($this->config);

        $findings = [];
        foreach ($this->registrations() as $registration) {
            $from = RegistrationDocument::dateOf($registration['valid_from'] ?? null);
            $to = RegistrationDocument::dateOf($registration['valid_to'] ?? null);
            if (($from !== '' && $from > $today) || ($to !== '' && $to < $today)) {
                continue;
            }
            $finding = $this->finding($registration, $now, $t);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /** @param array<string, mixed> $registration */
    protected function hasCertificate(array $registration): bool
    {
        return !empty($registration['has_certificate']) && self::time($registration['cert_valid_to'] ?? null) !== null;
    }

    /** Konec platnosti certifikátu v místním čase serveru, null = neznámý. */
    protected static function time(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $registration
     * @param array<string, scalar> $params
     */
    protected function build(array $registration, string $severity, string $title, string $message, array $params, Texts $t): AlertFinding
    {
        $id = (int) $registration['id'];
        $type = SalesRegistrationTypes::fromConfig($this->config)->get((string) ($registration['type'] ?? ''));
        $params += [
            'name'       => (string) ($registration['name'] ?? ''),
            'label'      => $type?->taxpayerIdLabel ?? '',
            'taxpayerId' => trim((string) ($registration['taxpayer_id'] ?? '')),
        ];
        $replace = static function (string $text) use ($params): string {
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string) $value, $text);
            }
            return preg_replace('/\s{2,}/', ' ', $text) ?? $text;
        };

        return new AlertFinding(
            findingKey: (string) $id,
            title: $replace($title),
            message: $replace($message),
            severity: $severity,
            subjectTableId: self::SUBJECT_TABLE_ID,
            subjectRowId: $id,
            actions: [[
                'id'      => 'open_registrations',
                'label'   => $t->t('alert.open', 'Open registrations'),
                'kind'    => 'open_viewer',
                'primary' => true,
                'target'  => ['viewerId' => self::VIEWER_ID],
            ]],
            context: ['registration' => $id, 'certValidTo' => self::time($registration['cert_valid_to'] ?? null)?->format('Y-m-d H:i:s')],
        );
    }

    protected function displayDate(\DateTimeImmutable $time): string
    {
        return (string) DisplayDate::date($time, $this->language);
    }

    /**
     * Registrace ve stavu V pořádku — bez soukromého klíče (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function registrations(): array
    {
        return $this->db->fetchAll(
            'SELECT `id`, `type`, `name`, `taxpayer_id`, `valid_from`, `valid_to`, `cert_valid_to`,'
            . ' (`cert_pem` IS NOT NULL AND `cert_pem` <> \'\') AS `has_certificate`'
            . ' FROM `' . RegistrationDocument::TABLE . '` WHERE `docState` = %i ORDER BY `id` ASC',
            RegistrationDocument::STATE_CONFIRMED,
        );
    }

    /** Teď v místním čase serveru — v něm je uložený konec platnosti (přepsatelné v testech). */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
