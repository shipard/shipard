<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Společná obálka dat tisku (#90 D3, D12) — z jednoho `PrintData` vzniká
 * PDF, název souboru a později předmět a tělo e-mailu; JSON slouží k ladění
 * a testům bez renderu. Sekce `data` je specifická pro tisk (kontrakt
 * builderu), zbytek je stejný pro všechny tisky.
 *
 * Šablona dostává `toArray()` — jen pole, žádné objekty (#90 D6).
 */
final class PrintData implements \JsonSerializable
{
    /**
     * @param ?string $logo Název assetu s logem (`logo.png`), null když
     *        v brandingu žádné není.
     * @param list<PrintMessage> $messages
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $printId,
        public readonly int $version,
        public readonly string $language,
        public readonly string $table,
        public readonly int $recordId,
        public readonly int $docState,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly string $title,
        public readonly string $fileName,
        public readonly ?string $logo,
        public readonly array $messages,
        public readonly array $data,
    ) {}

    /**
     * Obálka z JSON (`print-run --data`, #90 D28) — tvar `toArray()`.
     * Povinné jsou `printId`, `version`, `language`, `record`, `meta`
     * a `data`; zbytek má výchozí hodnoty, ať jde použít i ručně psaný
     * soubor.
     *
     * @param array<string, mixed> $envelope
     * @param ?int $maxVersion Nejvyšší verze kontraktu `data`, kterou zná
     *        builder tisku; novější data = chyba.
     * @throws \InvalidArgumentException Obálka nemá očekávaný tvar.
     */
    public static function fromArray(array $envelope, ?int $maxVersion = null): self
    {
        foreach (['printId', 'version', 'language', 'record', 'meta', 'data'] as $key) {
            if (!array_key_exists($key, $envelope)) {
                throw new \InvalidArgumentException("Print data: missing '{$key}'");
            }
        }

        $printId  = $envelope['printId'];
        $version  = $envelope['version'];
        $language = $envelope['language'];
        $record   = $envelope['record'];
        $meta     = $envelope['meta'];
        $data     = $envelope['data'];
        if (!is_string($printId) || $printId === '' || !is_int($version) || !is_string($language)
            || !is_array($record) || !is_array($meta) || !is_array($data)
        ) {
            throw new \InvalidArgumentException('Print data: envelope has an unexpected shape');
        }
        if ($maxVersion !== null && $version > $maxVersion) {
            throw new \InvalidArgumentException(
                "Print data: version {$version} is newer than version {$maxVersion} supported by print '{$printId}'",
            );
        }
        if (!is_string($record['table'] ?? null) || !is_int($record['id'] ?? null)
            || !is_int($record['docState'] ?? null)
        ) {
            throw new \InvalidArgumentException("Print data: 'record' must carry 'table', 'id' and 'docState'");
        }
        if (!is_string($meta['title'] ?? null) || !is_string($meta['fileName'] ?? null)) {
            throw new \InvalidArgumentException("Print data: 'meta' must carry 'title' and 'fileName'");
        }

        $generatedAt = $envelope['generatedAt'] ?? null;
        try {
            $generatedAt = is_string($generatedAt) ? new \DateTimeImmutable($generatedAt) : new \DateTimeImmutable();
        } catch (\Exception) {
            throw new \InvalidArgumentException("Print data: 'generatedAt' is not a date");
        }

        $logo = $envelope['branding']['logo'] ?? null;
        $messages = $envelope['messages'] ?? [];
        if (!is_array($messages)) {
            throw new \InvalidArgumentException("Print data: 'messages' must be an array");
        }

        return new self(
            printId: $printId,
            version: $version,
            language: $language,
            table: $record['table'],
            recordId: $record['id'],
            docState: $record['docState'],
            generatedAt: $generatedAt,
            title: $meta['title'],
            fileName: $meta['fileName'],
            logo: is_string($logo) && $logo !== '' ? $logo : null,
            messages: array_values(array_map(
                static fn (mixed $message): PrintMessage => PrintMessage::fromArray(is_array($message) ? $message : []),
                $messages,
            )),
            data: $data,
        );
    }

    /** Stejná data v jiném jazyce tisku — popisky v `data` zůstávají, jak jsou. */
    public function withLanguage(string $language): self
    {
        return new self(
            printId: $this->printId,
            version: $this->version,
            language: $language,
            table: $this->table,
            recordId: $this->recordId,
            docState: $this->docState,
            generatedAt: $this->generatedAt,
            title: $this->title,
            fileName: $this->fileName,
            logo: $this->logo,
            messages: $this->messages,
            data: $this->data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'printId'     => $this->printId,
            'version'     => $this->version,
            'language'    => $this->language,
            'record'      => [
                'table'    => $this->table,
                'id'       => $this->recordId,
                'docState' => $this->docState,
            ],
            'generatedAt' => $this->generatedAt->format(\DateTimeInterface::ATOM),
            'meta'        => [
                'title'    => $this->title,
                'fileName' => $this->fileName,
            ],
            'branding'    => ['logo' => $this->logo],
            // Sloty textů na tiscích (#90 D9) plní až fáze 3.
            'texts'       => [],
            'messages'    => array_map(
                static fn (PrintMessage $message): array => $message->toArray(),
                $this->messages,
            ),
            'data'        => $this->data,
        ];
    }

    /**
     * Stejný tvar jako `toArray()`, jen `texts` je v JSONu objekt i když je
     * prázdný — kontrakt ho drží jako mapu slot → text.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $array = $this->toArray();
        $array['texts'] = (object) $array['texts'];
        return $array;
    }
}
