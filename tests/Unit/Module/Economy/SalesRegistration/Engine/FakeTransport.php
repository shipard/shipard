<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\Engine;

use Ondrejnov\EET\Exceptions\TransportException;
use Ondrejnov\EET\Http\HttpResponse;
use Ondrejnov\EET\Http\Transport;

/**
 * Falešný transport knihovny EET: nic neposílá, zaznamená požadavek a vrátí
 * připravenou odpověď — tělo, funkci nad požadavkem, nebo vyhodí výjimku.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, body: string, action: string}> */
    public array $requests = [];

    /** @param string|\Closure(string): string|TransportException $reply */
    public function __construct(
        private readonly string|\Closure|TransportException $reply,
        private readonly ?string $transactionId = 'tx-test-1',
    ) {
    }

    public function post(string $url, string $body, string $soapAction): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'body' => $body, 'action' => $soapAction];
        if ($this->reply instanceof TransportException) {
            throw $this->reply;
        }
        $content = $this->reply instanceof \Closure ? ($this->reply)($body) : $this->reply;

        return new HttpResponse(200, $content, $this->transactionId);
    }

    /** Atribut prvku zprávy z odeslaného požadavku (`Hlavicka`, `Data`). */
    public function attribute(string $element, string $attribute, int $request = 0): ?string
    {
        $document = new \DOMDocument();
        $document->loadXML($this->requests[$request]['body']);
        $node = $document->getElementsByTagNameNS('http://fs.gov.cz/eet/schema/v4', $element)->item(0);

        return $node instanceof \DOMElement && $node->hasAttribute($attribute) ? $node->getAttribute($attribute) : null;
    }

    /** Nepodepsaná odpověď s chybou — tak je služba posílá. */
    public static function errorReply(int $code, string $text, string $extra = ''): \Closure
    {
        return static function (string $request) use ($code, $text, $extra): string {
            preg_match('/uuid_zpravy="([^"]+)"/', $request, $m);
            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<soapenv:Envelope xmlns:eet="http://fs.gov.cz/eet/schema/v4" xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
                . '<soapenv:Header/><soapenv:Body><eet:Odpoved>'
                . '<eet:Hlavicka uuid_zpravy="' . ($m[1] ?? '') . '" dat_odmit="2027-03-04T18:25:21+01:00"/>'
                . '<eet:Chyba kod="' . $code . '" test="true">' . htmlspecialchars($text, ENT_XML1) . '</eet:Chyba>'
                . $extra
                . '</eet:Odpoved></soapenv:Body></soapenv:Envelope>';
        };
    }
}
