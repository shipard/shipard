<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\MailSafetyConfig;
use Shipard\Core\Mail\MailSafetyGuard;
use Shipard\Core\Mail\MailSafetyResult;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Uplatnění pojistky odchozí pošty na sestavený e-mail (#95 D4, D5). */
class MailSafetyGuardTest extends TestCase
{
    /** @param array<string, mixed> $section */
    private function config(array $section): MailSafetyConfig
    {
        return MailSafetyConfig::fromServerData(['mode' => 'production', 'mail' => ['safety' => $section]]);
    }

    private function email(): Email
    {
        return (new Email())
            ->from(new Address('fakturace@firma.cz', 'Naše firma s.r.o.'))
            ->to('ucetni@odberatel.cz', 'jednatel@odberatel.cz')
            ->cc('obchod@firma.cz')
            ->bcc('archiv@odberatel.cz')
            ->subject('Faktura 2260011')
            ->text('Dobrý den, v příloze posíláme fakturu.')
            ->html('<p>Dobrý den, v příloze posíláme fakturu.</p>')
            ->attach('%PDF-1.7', 'faktura-2260011.pdf', 'application/pdf');
    }

    /**
     * @param Address[] $addresses
     * @return list<string>
     */
    private function list(array $addresses): array
    {
        return array_values(array_map(static fn (Address $a): string => $a->getAddress(), $addresses));
    }

    // ── off ─────────────────────────────────────────────────────────

    public function testOffLeavesEmailUntouched(): void
    {
        $email  = $this->email();
        $result = MailSafetyGuard::apply($email, MailSafetyConfig::off());

        $this->assertSame(MailSafetyResult::ACTION_NONE, $result->action);
        $this->assertSame($email, $result->email);
        $this->assertNull($result->target);
        $this->assertFalse($result->intervened());
        $this->assertSame('Faktura 2260011', $result->email->getSubject());
        $this->assertFalse($result->email->getHeaders()->has(MailSafetyGuard::HEADER_ORIGINAL_TO));
    }

    // ── drop ────────────────────────────────────────────────────────

    public function testDropSendsNothing(): void
    {
        $result = MailSafetyGuard::apply($this->email(), $this->config(['mode' => 'drop']));

        $this->assertSame(MailSafetyResult::ACTION_DROPPED, $result->action);
        $this->assertTrue($result->isDropped());
        $this->assertTrue($result->intervened());
        $this->assertNull($result->target);
    }

    // ── redirect ────────────────────────────────────────────────────

    public function testRedirectReplacesAllRecipients(): void
    {
        $result = MailSafetyGuard::apply(
            $this->email(),
            $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']),
        );

        $this->assertSame(MailSafetyResult::ACTION_REDIRECTED, $result->action);
        $this->assertSame('testy@firma.cz', $result->target);
        $this->assertSame(['testy@firma.cz'], $this->list($result->email->getTo()));
        $this->assertSame([], $result->email->getCc());
        $this->assertSame([], $result->email->getBcc());
        // Prázdná hlavička by ve zprávě zůstala jako „Cc:“ bez adres.
        $this->assertFalse($result->email->getHeaders()->has('Cc'));
        $this->assertFalse($result->email->getHeaders()->has('Bcc'));
    }

    public function testRedirectLeavesTraceInSubjectAndHeaders(): void
    {
        $result = MailSafetyGuard::apply(
            $this->email(),
            $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']),
        );
        $headers = $result->email->getHeaders();

        $this->assertSame('[TEST] Faktura 2260011', $result->email->getSubject());
        $this->assertSame(
            'ucetni@odberatel.cz, jednatel@odberatel.cz',
            $headers->get(MailSafetyGuard::HEADER_ORIGINAL_TO)->getBodyAsString(),
        );
        $this->assertSame(
            'obchod@firma.cz',
            $headers->get(MailSafetyGuard::HEADER_ORIGINAL_CC)->getBodyAsString(),
        );
        // Skrytá kopie se do hlaviček nepropisuje.
        $this->assertStringNotContainsString('archiv@odberatel.cz', $headers->toString());
    }

    public function testRedirectWithoutCopiesHasNoOriginalCcHeader(): void
    {
        $email  = (new Email())->from('a@firma.cz')->to('ucetni@odberatel.cz')->subject('Faktura')->text('x');
        $result = MailSafetyGuard::apply($email, $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']));

        $this->assertTrue($result->email->getHeaders()->has(MailSafetyGuard::HEADER_ORIGINAL_TO));
        $this->assertFalse($result->email->getHeaders()->has(MailSafetyGuard::HEADER_ORIGINAL_CC));
    }

    public function testRedirectKeepsSenderBodyAndAttachments(): void
    {
        $email  = $this->email();
        $result = MailSafetyGuard::apply($email, $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']));

        $this->assertSame('fakturace@firma.cz', $result->email->getFrom()[0]->getAddress());
        $this->assertSame('Naše firma s.r.o.', $result->email->getFrom()[0]->getName());
        $this->assertSame($email->getTextBody(), $result->email->getTextBody());
        $this->assertSame($email->getHtmlBody(), $result->email->getHtmlBody());
        $this->assertCount(1, $result->email->getAttachments());
        $this->assertSame('faktura-2260011.pdf', $result->email->getAttachments()[0]->getFilename());
        $this->assertSame('%PDF-1.7', $result->email->getAttachments()[0]->getBody());
    }

    public function testOriginalEmailIsNotModified(): void
    {
        $email = $this->email();
        MailSafetyGuard::apply($email, $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']));

        $this->assertSame(['ucetni@odberatel.cz', 'jednatel@odberatel.cz'], $this->list($email->getTo()));
        $this->assertSame(['obchod@firma.cz'], $this->list($email->getCc()));
        $this->assertSame('Faktura 2260011', $email->getSubject());
        $this->assertFalse($email->getHeaders()->has(MailSafetyGuard::HEADER_ORIGINAL_TO));
    }

    public function testSubjectPrefixIsAddedOnlyOnce(): void
    {
        $config = $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']);
        $email  = (new Email())->from('a@firma.cz')->to('ucetni@odberatel.cz')->subject('[TEST] Faktura')->text('x');

        $result = MailSafetyGuard::apply($email, $config);

        $this->assertSame('[TEST] Faktura', $result->email->getSubject());
    }

    public function testRedirectToTheOnlyRecipientChangesNothing(): void
    {
        $email  = (new Email())->from('a@firma.cz')->to('Testy@Firma.cz')->subject('Faktura')->text('x');
        $result = MailSafetyGuard::apply($email, $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']));

        // Žádná adresa se nezměnila — zpráva nenese stopu.
        $this->assertSame(MailSafetyResult::ACTION_NONE, $result->action);
        $this->assertSame('Faktura', $result->email->getSubject());
    }

    // ── allowlist ───────────────────────────────────────────────────

    public function testAllowlistWithAllRecipientsAllowedChangesNothing(): void
    {
        $email  = $this->email();
        $result = MailSafetyGuard::apply($email, $this->config([
            'mode'       => 'allowlist',
            'redirectTo' => 'testy@firma.cz',
            'allow'      => ['@odberatel.cz', 'obchod@firma.cz'],
        ]));

        $this->assertSame(MailSafetyResult::ACTION_NONE, $result->action);
        $this->assertSame($email, $result->email);
        $this->assertSame('Faktura 2260011', $result->email->getSubject());
    }

    public function testAllowlistKeepsAllowedInPlaceAndRedirectsTheRest(): void
    {
        $result = MailSafetyGuard::apply($this->email(), $this->config([
            'mode'       => 'allowlist',
            'redirectTo' => 'testy@firma.cz',
            // Přesná adresa (jinou velikostí písmen) a doména.
            'allow'      => ['UCETNI@odberatel.cz', '@Firma.cz'],
        ]));

        $this->assertSame(MailSafetyResult::ACTION_REDIRECTED, $result->action);
        $this->assertSame('testy@firma.cz', $result->target);
        // Povolená zůstala v „Komu“, dvě nepovolené nahradila jedna adresa.
        $this->assertSame(['ucetni@odberatel.cz', 'testy@firma.cz'], $this->list($result->email->getTo()));
        $this->assertSame(['obchod@firma.cz'], $this->list($result->email->getCc()));
        $this->assertSame([], $result->email->getBcc());
        $this->assertSame('[TEST] Faktura 2260011', $result->email->getSubject());
        $this->assertSame(
            'ucetni@odberatel.cz, jednatel@odberatel.cz',
            $result->email->getHeaders()->get(MailSafetyGuard::HEADER_ORIGINAL_TO)->getBodyAsString(),
        );
    }

    public function testAllowlistDoesNotDuplicateRedirectAddress(): void
    {
        $email = (new Email())->from('a@firma.cz')->to('testy@firma.cz', 'ucetni@odberatel.cz')->subject('Faktura')->text('x');

        $result = MailSafetyGuard::apply($email, $this->config([
            'mode'       => 'allowlist',
            'redirectTo' => 'Testy@firma.cz',
            'allow'      => ['@firma.cz'],
        ]));

        $this->assertSame(['testy@firma.cz'], $this->list($result->email->getTo()));
        $this->assertSame(MailSafetyResult::ACTION_REDIRECTED, $result->action);
    }

    public function testAllowlistWithoutRedirectDropsDisallowedAddresses(): void
    {
        $result = MailSafetyGuard::apply($this->email(), $this->config([
            'mode'  => 'allowlist',
            'allow' => ['ucetni@odberatel.cz'],
        ]));

        // Část příjemců vypadla a nikam se nepřesměrovala.
        $this->assertSame(MailSafetyResult::ACTION_REDIRECTED, $result->action);
        $this->assertNull($result->target);
        $this->assertSame(['ucetni@odberatel.cz'], $this->list($result->email->getTo()));
        $this->assertSame([], $result->email->getCc());
        $this->assertSame([], $result->email->getBcc());
        $this->assertSame('[TEST] Faktura 2260011', $result->email->getSubject());
    }

    public function testAllowlistWithNobodyLeftDrops(): void
    {
        $result = MailSafetyGuard::apply($this->email(), $this->config([
            'mode'  => 'allowlist',
            'allow' => ['@jinde.cz'],
        ]));

        $this->assertSame(MailSafetyResult::ACTION_DROPPED, $result->action);
        $this->assertNull($result->target);
    }

    public function testAllowlistWithNobodyAllowedButRedirectStillSends(): void
    {
        $result = MailSafetyGuard::apply($this->email(), $this->config([
            'mode'       => 'allowlist',
            'redirectTo' => 'testy@firma.cz',
            'allow'      => ['@jinde.cz'],
        ]));

        $this->assertSame(MailSafetyResult::ACTION_REDIRECTED, $result->action);
        $this->assertSame(['testy@firma.cz'], $this->list($result->email->getTo()));
        $this->assertSame([], $result->email->getCc());
    }

    public function testRedirectedEmailIsStillSendable(): void
    {
        $result = MailSafetyGuard::apply(
            $this->email(),
            $this->config(['mode' => 'redirect', 'redirectTo' => 'testy@firma.cz']),
        );

        // Sestavení MIME ověří, že zpráva má odesílatele, příjemce a tělo.
        $mime = $result->email->toString();
        $this->assertStringContainsString('To: testy@firma.cz', $mime);
        $this->assertStringContainsString('X-Shipard-Original-To: ucetni@odberatel.cz, jednatel@odberatel.cz', $mime);
    }
}
