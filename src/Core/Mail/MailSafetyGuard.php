<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Uplatní pojistku odchozí pošty na sestavený e-mail (#95 D3–D5) — čistá
 * funkce: původní e-mail nemění, upravený vrací ve výsledku. Mění se jen
 * obálka (Komu, Kopie, skrytá kopie); řádek fronty si nechává původní
 * adresy.
 *
 * Když se aspoň jedna adresa změní, zpráva dostane stopu: předmět
 * s prefixem `[TEST]` a původní příjemce v hlavičkách
 * `X-Shipard-Original-To` / `X-Shipard-Original-Cc`. Tělo ani přílohy se
 * nemění.
 */
final class MailSafetyGuard
{
    public const SUBJECT_PREFIX = '[TEST] ';

    public const HEADER_ORIGINAL_TO = 'X-Shipard-Original-To';
    public const HEADER_ORIGINAL_CC = 'X-Shipard-Original-Cc';

    public static function apply(Email $email, MailSafetyConfig $config): MailSafetyResult
    {
        if ($config->mode === MailSafetyConfig::MODE_OFF) {
            return new MailSafetyResult(MailSafetyResult::ACTION_NONE, $email);
        }
        if ($config->mode === MailSafetyConfig::MODE_DROP) {
            return new MailSafetyResult(MailSafetyResult::ACTION_DROPPED, $email);
        }

        $original = [
            'To'  => self::addresses($email->getTo()),
            'Cc'  => self::addresses($email->getCc()),
            'Bcc' => self::addresses($email->getBcc()),
        ];

        $target = null;
        if ($config->mode === MailSafetyConfig::MODE_REDIRECT) {
            $target = $config->redirectTo;
            $final  = ['To' => [(string) $target], 'Cc' => [], 'Bcc' => []];
        } else {
            $final   = [];
            $removed = false;
            foreach ($original as $field => $addresses) {
                $final[$field] = array_values(array_filter($addresses, $config->allows(...)));
                $removed       = $removed || count($final[$field]) !== count($addresses);
            }
            // Nepovolené adresy nahradí jedna adresa přesměrování v „Komu“.
            if ($removed && $config->redirectTo !== null) {
                $target = $config->redirectTo;
                if (!self::contains($final['To'], $target)) {
                    $final['To'][] = $target;
                }
            }
            if ($final['To'] === [] && $final['Cc'] === [] && $final['Bcc'] === []) {
                return new MailSafetyResult(MailSafetyResult::ACTION_DROPPED, $email);
            }
        }

        if (self::same($original, $final)) {
            return new MailSafetyResult(MailSafetyResult::ACTION_NONE, $email);
        }

        $safe    = clone $email;
        $headers = $safe->getHeaders();
        foreach ($final as $field => $addresses) {
            $headers->remove($field);
            if ($addresses !== []) {
                $headers->addMailboxListHeader($field, Address::createArray($addresses));
            }
        }

        // Prefix jen jednou — zpráva, která ho už nese, další nedostane.
        $subject = (string) $safe->getSubject();
        if (!str_starts_with($subject, self::SUBJECT_PREFIX)) {
            $safe->subject(self::SUBJECT_PREFIX . $subject);
        }

        $headers->remove(self::HEADER_ORIGINAL_TO);
        $headers->remove(self::HEADER_ORIGINAL_CC);
        $headers->addTextHeader(self::HEADER_ORIGINAL_TO, AddressList::format($original['To']));
        if ($original['Cc'] !== []) {
            $headers->addTextHeader(self::HEADER_ORIGINAL_CC, AddressList::format($original['Cc']));
        }

        return new MailSafetyResult(MailSafetyResult::ACTION_REDIRECTED, $safe, $target);
    }

    /**
     * @param Address[] $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $a): string => $a->getAddress(), $addresses));
    }

    /** @param list<string> $addresses */
    private static function contains(array $addresses, string $address): bool
    {
        return in_array(mb_strtolower($address), array_map(mb_strtolower(...), $addresses), true);
    }

    /**
     * Stejní příjemci na stejných místech, bez ohledu na velikost písmen.
     *
     * @param array<string, list<string>> $a
     * @param array<string, list<string>> $b
     */
    private static function same(array $a, array $b): bool
    {
        foreach ($a as $field => $addresses) {
            if (array_map(mb_strtolower(...), $addresses) !== array_map(mb_strtolower(...), $b[$field])) {
                return false;
            }
        }
        return true;
    }
}
