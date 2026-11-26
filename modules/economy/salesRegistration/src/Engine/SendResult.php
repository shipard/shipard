<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Engine;

/**
 * Výsledek odeslání zprávy evidence tržeb (#171 D2, D12) — obecný napříč
 * typy evidence.
 *
 *  - `sent` — odešlo něco po síti? `false` u pojistky `drop`, u zprávy,
 *    kterou nešlo sestavit, a u chybějící identity;
 *  - `problem` — strojový kód místního důvodu (konstanty `PROBLEM_*`),
 *    `errorCode` / `errorText` jsou od služby správce daně (nebo text
 *    místní chyby);
 *  - `untrusted` — odpověď přišla, ale nelze jí věřit (podpis, certifikát,
 *    jiné UUID); kód potvrzení se z ní nepřebírá;
 *  - `requestBody` / `responseBody` — podepsaná zpráva tam a zpět pro žurnál
 *    pokusů. Do logu nepatří.
 */
final readonly class SendResult
{
    /** Typ evidence není ve zdroji dat dostupný nebo nemá použitelný engine. */
    public const string PROBLEM_TYPE = 'type';
    /** Typ evidence nemá adresu prostředí, kam zpráva měla jít. */
    public const string PROBLEM_ENVIRONMENT = 'environment';
    /** Registrace nemá certifikát. */
    public const string PROBLEM_NO_CERTIFICATE = 'noCertificate';
    /** Klíč registrace nejde dešifrovat nebo zapsat do dočasného souboru. */
    public const string PROBLEM_KEY = 'key';
    /** Typ evidence nemá testovací identitu. */
    public const string PROBLEM_NO_TEST_IDENTITY = 'noTestIdentity';
    /** Zpráva neodpovídá pravidlům typu (masky, doplňky) — nic neodešlo. */
    public const string PROBLEM_MESSAGE = 'message';
    /** Zprávu nešlo předat enginu (dočasný soubor klíče, chyba enginu) — nic se nepovažuje za odeslané. */
    public const string PROBLEM_INTERNAL = 'internal';
    /** Pojistka serveru — důvod je v `errorText` (kód `MessageRoute::DROP_*`). */
    public const string PROBLEM_SAFETY = 'safety';

    /**
     * @param array<int, string> $warnings kód varování → text
     */
    public function __construct(
        public SendStatus $status,
        public bool $sent,
        public ?string $confirmationCode = null,
        public ?int $errorCode = null,
        public ?string $errorText = null,
        public array $warnings = [],
        public ?string $transactionId = null,
        public ?string $requestBody = null,
        public ?string $responseBody = null,
        public ?int $durationMs = null,
        public bool $test = false,
        public bool $untrusted = false,
        public ?string $problem = null,
        public ?string $environment = null,
        public bool $testIdentity = false,
        public bool $verification = false,
    ) {
    }

    /** Nic neodešlo — místní důvod. */
    public static function notSent(SendStatus $status, string $problem, ?string $text = null): self
    {
        return new self($status, false, errorText: $text, problem: $problem);
    }

    /** Tentýž výsledek doplněný o trasu, kterou zpráva šla (doplňuje odesílač). */
    public function withRoute(string $environment, bool $testIdentity, bool $verification): self
    {
        return new self(
            $this->status,
            $this->sent,
            $this->confirmationCode,
            $this->errorCode,
            $this->errorText,
            $this->warnings,
            $this->transactionId,
            $this->requestBody,
            $this->responseBody,
            $this->durationMs,
            $this->test,
            $this->untrusted,
            $this->problem,
            $environment,
            $testIdentity,
            $verification,
        );
    }

    /**
     * Údaje do logu — bez těl zpráv, klíče a hesla.
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'status'        => $this->status->value,
            'sent'          => $this->sent,
            'environment'   => $this->environment,
            'testIdentity'  => $this->testIdentity,
            'verification'  => $this->verification,
            'confirmation'  => $this->confirmationCode,
            'errorCode'     => $this->errorCode,
            'problem'       => $this->problem,
            'warnings'      => array_keys($this->warnings),
            'transactionId' => $this->transactionId,
            'durationMs'    => $this->durationMs,
            'untrusted'     => $this->untrusted,
        ];
    }

    /** @return array<string, mixed> bez těl zpráv — ani do `var_dump` */
    public function __debugInfo(): array
    {
        return $this->logContext() + ['errorText' => $this->errorText];
    }
}
