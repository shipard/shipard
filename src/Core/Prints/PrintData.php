<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Prints\Texts\PrintTextSlot;
use Shipard\Core\Utils\HexColor;

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
    /** Umístění loga v záhlaví (#90 D46); první je výchozí. */
    public const LOGO_PLACEMENTS = ['left', 'right'];

    /**
     * Výchozí akcent záhlaví — neutrální světle šedá. Tisk bez nastavené
     * barvy tak nenese žádnou firemní barvu.
     */
    public const DEFAULT_ACCENT_COLOR = '#c8c8c8';

    /**
     * Kontakty vlastní firmy, které zápatí dokladu smí tisknout (#162 D130,
     * D132) — pevný výčet v pořadí tisku: první řádek e-mail a telefon,
     * druhý web a datová schránka. Výchozí jsou všechny čtyři.
     */
    public const FOOTER_CONTACTS = ['email', 'phone', 'web', 'dataBox'];

    /**
     * @param ?string $logo Název assetu s logem (`logo.png`), null když
     *        v brandingu žádné není.
     * @param list<PrintMessage> $messages
     * @param array<string, mixed> $data
     * @param ?string $watermark Text přes každou stranu (D23, „STORNO“);
     *        null = bez vodoznaku.
     * @param string $logoPlacement Jedna z `LOGO_PLACEMENTS`.
     * @param string $accentColor Akcentová barva záhlaví jako `#rrggbb`.
     *        Šablona ji vkládá do stylů, proto obálka jiný tvar nepřijme.
     * @param array<string, string> $texts Uživatelské texty na tiscích (#90
     *        D50): slot (`PrintTextSlot`) → hotové HTML pro stránku tisku,
     *        u e-mailových slotů prostý text. Slot bez textu v mapě není.
     * @param list<string> $footerContacts Zapnuté kontakty v zápatí — hodnoty
     *        z `FOOTER_CONTACTS`; obálka je drží v pořadí výčtu a bez
     *        opakování. Tiskne se jen zapnutý a ve snapshotu vyplněný údaj.
     * @throws \InvalidArgumentException Umístění loga, barva nebo kontakty
     *         v zápatí nemají očekávaný tvar.
     */
    /** @var list<string> Zapnuté kontakty v zápatí, v pořadí `FOOTER_CONTACTS`. */
    public readonly array $footerContacts;

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
        public readonly ?string $watermark = null,
        public readonly string $logoPlacement = self::LOGO_PLACEMENTS[0],
        public readonly string $accentColor = self::DEFAULT_ACCENT_COLOR,
        public readonly array $texts = [],
        array $footerContacts = self::FOOTER_CONTACTS,
    ) {
        if (!in_array($logoPlacement, self::LOGO_PLACEMENTS, true)) {
            throw new \InvalidArgumentException(
                "Print data: 'branding.logoPlacement' must be one of " . implode('|', self::LOGO_PLACEMENTS),
            );
        }
        if (HexColor::normalize($accentColor) !== $accentColor) {
            throw new \InvalidArgumentException("Print data: 'branding.accentColor' must be a colour like '#rrggbb'");
        }
        foreach ($footerContacts as $contact) {
            if (!in_array($contact, self::FOOTER_CONTACTS, true)) {
                throw new \InvalidArgumentException(
                    "Print data: 'branding.footerContacts' must list only " . implode('|', self::FOOTER_CONTACTS),
                );
            }
        }
        $this->footerContacts = array_values(array_filter(
            self::FOOTER_CONTACTS,
            static fn (string $contact): bool => in_array($contact, $footerContacts, true),
        ));
    }

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
        $watermark = $meta['watermark'] ?? null;
        if ($watermark !== null && !is_string($watermark)) {
            throw new \InvalidArgumentException("Print data: 'meta.watermark' must be a string or null");
        }

        $generatedAt = $envelope['generatedAt'] ?? null;
        try {
            $generatedAt = is_string($generatedAt) ? new \DateTimeImmutable($generatedAt) : new \DateTimeImmutable();
        } catch (\Exception) {
            throw new \InvalidArgumentException("Print data: 'generatedAt' is not a date");
        }

        $branding = $envelope['branding'] ?? [];
        if (!is_array($branding)) {
            throw new \InvalidArgumentException("Print data: 'branding' must be an object");
        }
        $logo = $branding['logo'] ?? null;
        // JSON z doby před nastavením vzhledu (#90 D46) klíče nemá.
        $logoPlacement = $branding['logoPlacement'] ?? self::LOGO_PLACEMENTS[0];
        $accentColor   = $branding['accentColor'] ?? self::DEFAULT_ACCENT_COLOR;
        if (!is_string($logoPlacement) || !is_string($accentColor)) {
            throw new \InvalidArgumentException(
                "Print data: 'branding.logoPlacement' and 'branding.accentColor' must be strings",
            );
        }
        // JSON z doby před kontakty v zápatí (#162 D132) klíč nemá — tiskne
        // se všechno, co snapshot nese.
        $footerContacts = $branding['footerContacts'] ?? self::FOOTER_CONTACTS;
        if (!is_array($footerContacts)) {
            throw new \InvalidArgumentException("Print data: 'branding.footerContacts' must be a list");
        }
        $messages = $envelope['messages'] ?? [];
        if (!is_array($messages)) {
            throw new \InvalidArgumentException("Print data: 'messages' must be an array");
        }

        // Texty z JSON se berou tak, jak jsou (`print-run --data`, D28) —
        // výběr ani vykreslení textů se při renderu hotových dat neopakuje.
        $texts = [];
        $rawTexts = $envelope['texts'] ?? [];
        if (!is_array($rawTexts)) {
            throw new \InvalidArgumentException("Print data: 'texts' must be an object");
        }
        foreach ($rawTexts as $slot => $text) {
            if (!is_string($slot) || PrintTextSlot::tryFrom($slot) === null || !is_string($text)) {
                throw new \InvalidArgumentException(
                    "Print data: 'texts' must map text slots (" . implode('|', PrintTextSlot::ids()) . ') to strings',
                );
            }
            if ($text !== '') {
                $texts[$slot] = $text;
            }
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
            watermark: $watermark === '' ? null : $watermark,
            logoPlacement: $logoPlacement,
            accentColor: strtolower($accentColor),
            texts: $texts,
            footerContacts: array_values($footerContacts),
        );
    }

    /** Stejná data v jiném jazyce tisku — popisky v `data` zůstávají, jak jsou. */
    public function withLanguage(string $language): self
    {
        return $this->with(language: $language);
    }

    /**
     * Stejná obálka s vyplněnými sloty textů; hlášení (vynechané texty) se
     * přidají za stávající.
     *
     * @param array<string, string> $texts
     * @param list<PrintMessage> $messages
     */
    public function withTexts(array $texts, array $messages = []): self
    {
        return $this->with(texts: $texts, messages: [...$this->messages, ...$messages]);
    }

    /**
     * @param ?array<string, string> $texts
     * @param ?list<PrintMessage> $messages
     */
    private function with(?string $language = null, ?array $texts = null, ?array $messages = null): self
    {
        return new self(
            printId: $this->printId,
            version: $this->version,
            language: $language ?? $this->language,
            table: $this->table,
            recordId: $this->recordId,
            docState: $this->docState,
            generatedAt: $this->generatedAt,
            title: $this->title,
            fileName: $this->fileName,
            logo: $this->logo,
            messages: $messages ?? $this->messages,
            data: $this->data,
            watermark: $this->watermark,
            logoPlacement: $this->logoPlacement,
            accentColor: $this->accentColor,
            texts: $texts ?? $this->texts,
            footerContacts: $this->footerContacts,
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
                'title'     => $this->title,
                'fileName'  => $this->fileName,
                'watermark' => $this->watermark,
            ],
            'branding'    => [
                'logo'           => $this->logo,
                'logoPlacement'  => $this->logoPlacement,
                'accentColor'    => $this->accentColor,
                'footerContacts' => $this->footerContacts,
            ],
            'texts'       => $this->texts,
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
