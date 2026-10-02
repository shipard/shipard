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
