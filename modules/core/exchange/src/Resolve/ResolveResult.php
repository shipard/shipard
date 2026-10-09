<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

/**
 * Immutable result returned by every resolver. Designed to be serialized
 * straight into the `_resolve.<key>` slot of the canonical payload.
 */
class ResolveResult
{
    /**
     * @param array<int, array<string, mixed>> $candidates Used only for Ambiguous.
     * @param array<string, mixed>             $createPayload Used only for CanCreate;
     *                                                       pre-built input for the
     *                                                       corresponding *Document::saveDocument.
     * @param bool $authoritativeRefresh Used only for Matched; true means the
     *                                   applier MUST overwrite the matched
     *                                   record's fields from payload even
     *                                   under `mergeStrategy = mergeAdd`.
     *                                   AddressResolver sets this for matches
     *                                   on `(place_reg_type, place_reg_id)` —
     *                                   the registry is authoritative for
     *                                   provozovna / zařízení addresses.
     */
    public function __construct(
        public readonly ResolveStatus $status,
        public readonly ?int $matchedId = null,
        public readonly ?string $matchedBy = null,
        public readonly array $candidates = [],
        public readonly array $createPayload = [],
        public readonly bool $authoritativeRefresh = false,
    ) {}

    /**
     * @param array<string, mixed> $createPayload Předvyplnění formuláře
     *        nové entity i u napárovaného výsledku (ItemResolver, #111 D5):
     *        applier ho u `matched` nečte, slouží jen review modalu.
     */
    public static function matched(int $id, string $by, bool $authoritativeRefresh = false, array $createPayload = []): self
    {
        return new self(
            ResolveStatus::Matched,
            matchedId: $id,
            matchedBy: $by,
            createPayload: $createPayload,
            authoritativeRefresh: $authoritativeRefresh,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(ResolveStatus::Ambiguous, candidates: $candidates);
    }

    public static function notFound(): self
    {
        return new self(ResolveStatus::NotFound);
    }

    /**
     * @param array<string, mixed> $createPayload
     */
    public static function canCreate(array $createPayload): self
    {
        return new self(ResolveStatus::CanCreate, createPayload: $createPayload);
    }

    /**
     * Serialize for inclusion in the canonical `_resolve` block. The
     * specific key (`personId`, `itemId`, `unitId`) is added by the caller
     * since it differs per resolver.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['status' => $this->status->value];
        if ($this->matchedId !== null) {
            $out['matchedId'] = $this->matchedId;
        }
        if ($this->matchedBy !== null) {
            $out['matchedBy'] = $this->matchedBy;
        }
        if ($this->candidates !== []) {
            $out['candidates'] = $this->candidates;
        }
        if ($this->createPayload !== []) {
            $out['createPayload'] = $this->createPayload;
        }
        if ($this->authoritativeRefresh) {
            $out['authoritativeRefresh'] = true;
        }
        return $out;
    }
}
