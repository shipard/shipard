<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Klíč idempotence pokynu (#166 D46): druh zdroje a identifikátor dokladu
 * ve zdrojovém systému — `source.kind` + `source.ref` canonicalu, sloupce
 * `source_kind` + `source_ref` hlavičky.
 *
 * Jediné místo, které říká, kdy payload klíč nese: obě hodnoty jsou
 * neprázdné řetězce. Čte ho {@see DocumentApplier} ještě před kontrolou
 * schématu (opakovaný pokyn se vrací dřív, než se obsah posuzuje), proto
 * tvar ověřuje sám a nic nepředpokládá.
 */
final class SourceRef
{
    /** Délka sloupce `docs_core_heads.source_ref`. */
    public const MAX_LENGTH = 60;

    private function __construct(
        public readonly string $kind,
        public readonly string $ref,
    ) {}

    /**
     * @param array<string, mixed> $canonical
     * @return self|null null = payload klíč nenese (nebo ho nese ve tvaru,
     *         který odmítne schéma / validátor)
     */
    public static function fromCanonical(array $canonical): ?self
    {
        $source = $canonical['source'] ?? null;
        if (!is_array($source)) {
            return null;
        }
        $kind = $source['kind'] ?? null;
        $ref = $source['ref'] ?? null;
        if (!is_string($kind) || $kind === '' || !is_string($ref) || $ref === '') {
            return null;
        }
        if (mb_strlen($ref) > self::MAX_LENGTH) {
            return null;
        }
        return new self($kind, $ref);
    }

    /**
     * Identifikátor bez druhu zdroje — nejde dohledat, validátor ho hlásí
     * chybou `source_kind_missing`.
     *
     * @param array<string, mixed> $canonical
     */
    public static function hasRefWithoutKind(array $canonical): bool
    {
        $source = $canonical['source'] ?? null;
        if (!is_array($source) || !is_string($source['ref'] ?? null) || $source['ref'] === '') {
            return false;
        }
        $kind = $source['kind'] ?? null;
        return !is_string($kind) || $kind === '';
    }

    /** Shoda je přesná — velikost písmen i diakritika rozhodují. */
    public function matches(mixed $kind, mixed $ref): bool
    {
        return is_string($kind) && is_string($ref) && $kind === $this->kind && $ref === $this->ref;
    }

    /**
     * Jméno zámku pro souběžné pokyny se stejným klíčem (bez databáze —
     * tu doplní dotaz, pojmenované zámky MariaDB jsou společné serveru).
     */
    public function lockKey(): string
    {
        return 'docsrc:' . sha1($this->kind . "\0" . $this->ref);
    }
}
