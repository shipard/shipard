<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Partner došlé zprávy z návrhu — vrstva 1
 * (tasks/mail-message-title-partner.md D4, D5, D8).
 *
 * Partner = protistrana dokumentu (dodavatel u dokladu, `party` u registry
 * dokumentu), ne odesílatel e-mailu (`sender_person`). U zprávy bez dokladu
 * (`other`) je zdrojem protistrana z klasifikace
 * (`message_classification.party`, tasks/mail-other-attention.md D7 — od
 * koho zpráva skutečně je, ne kdo ji přeposlal) se stejnými pravidly.
 * Zapisuje se ve dvou sloupcích:
 *
 *   - `partner_name`   — jméno z canonicalu, přepisuje se, dokud zpráva nemá
 *                        `target_row` (po Použít je snapshot toho, co bylo
 *                        na dokladu, a už se nemění),
 *   - `partner_person` — Osoba jen při **deterministické shodě
 *                        identifikátorem** (IČO / DIČ / VAT ID,
 *                        `PartyResolver::resolve(…, identifiersOnly: true)`);
 *                        nikdy shoda jménem, nikdy create. Zapisuje se jen do
 *                        NULL (ruční volba ve formuláři má přednost) a jen
 *                        dokud `target_row IS NULL` (Použít je autoritativní).
 *
 * Sdílí ho `/result` (AI — canonical i klasifikace) a `IsdocImportService`
 * (deterministický import, obchází AI). Volá se **uvnitř otevřené
 * transakce volajícího**; vlastní selhání resolveru polyká (partner je
 * best-effort, výsledek analýzy se kvůli němu nesmí ztratit).
 */
final class MessagePartnerWriter
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';

    /** Délka sloupce `partner_name`. */
    public const NAME_MAX_LENGTH = 200;

    public function __construct(
        private readonly ?PartyResolver $partyResolver,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * Produkční wiring — resolver nad danou Dibi connection (stejný vzor
     * jako `AnalysisController::buildProposalApplier`).
     */
    public static function create(\Dibi\Connection $dibi, ?ConfigRuntime $config = null): self
    {
        return new self(new PartyResolver($dibi, new OwnCompanyResolver($dibi)), $config);
    }

    /**
     * Zapíše `partner_name` a best-effort `partner_person` z canonicalu.
     * Nic nedělá pro `null` canonical, forenzní wrapper (`_validationError`)
     * a canonical bez protistrany.
     *
     * @param array<string, mixed>|null $canonical  parsovaný canonical návrhu
     * @param string                    $proposedType klíč `core.mail.primaryTypes`
     */
    public function writeFromCanonical(
        \Dibi\Connection $dibi,
        int $messageNdx,
        ?array $canonical,
        string $proposedType,
    ): void {
        $party = self::partyOf($canonical, $proposedType, $this->config);
        if ($party === null) {
            return;
        }

        $this->writeParty($dibi, $messageNdx, $party);
    }

    /**
     * Zapíše partnera z protistrany klasifikace zprávy bez dokladu
     * (`message_classification.party {name, companyId, email}`,
     * tasks/mail-other-attention.md D7) — stejná pravidla jako u canonicalu:
     * jméno dokud `target_row IS NULL`, Osoba jen shodou identifikátorem
     * (IČO; e-mail se na Osobu nepáruje), nikdy jménem, nikdy create.
     * Prázdná protistrana nic nedělá.
     *
     * @param array<string, mixed> $party
     */
    public function writeFromClassification(\Dibi\Connection $dibi, int $messageNdx, array $party): void
    {
        if ($party === []) {
            return;
        }

        $this->writeParty($dibi, $messageNdx, $party);
    }

    /**
     * Společný zápis `partner_name` + best-effort `partner_person`.
     *
     * @param array<string, mixed> $party
     */
    private function writeParty(\Dibi\Connection $dibi, int $messageNdx, array $party): void
    {
        $name = self::normalizeName($party['name'] ?? null);
        if ($name !== null) {
            $dibi->update(self::MESSAGES_TABLE, ['partner_name' => $name])
                ->where('id = %i', $messageNdx)
                ->where('target_row IS NULL')
                ->execute();
        }

        $personId = $this->resolvePerson($party, $messageNdx);
        if ($personId !== null) {
            $dibi->update(self::MESSAGES_TABLE, ['partner_person' => $personId])
                ->where('id = %i', $messageNdx)
                ->where('target_row IS NULL')
                ->where('partner_person IS NULL')
                ->execute();
        }
    }

    /**
     * Protistrana canonicalu podle targetu typu: docs → strana, která nejsme
     * my (`selfParty` je u přijatých dokladů vždy `customer`, protistrana
     * tedy `supplier`; pro jistotu se při `selfParty = supplier` bere
     * `customer`); registry → `party`.
     *
     * @param array<string, mixed>|null $canonical
     * @return array<string, mixed>|null
     */
    public static function partyOf(?array $canonical, string $proposedType, ?ConfigRuntime $config): ?array
    {
        if ($canonical === null || isset($canonical['_validationError'])) {
            return null;
        }

        if (PrimaryTypes::targetFor($config, $proposedType) === PrimaryTypes::TARGET_REGISTRY) {
            $party = $canonical['party'] ?? null;
        } else {
            $side = ($canonical['selfParty'] ?? 'customer') === 'supplier' ? 'customer' : 'supplier';
            $party = $canonical[$side] ?? null;
        }

        return is_array($party) && $party !== [] ? $party : null;
    }

    /**
     * Trim, sjednocení bílých znaků, oříznutí na délku sloupce; prázdné → null.
     */
    public static function normalizeName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($name === '') {
            return null;
        }
        return mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    /**
     * Osoba jen podle identifikátorů — do resolveru jde payload **bez jména**,
     * takže ani při `identifiersOnly` nevznikne canCreate návrh. Cokoli jiného
     * než Matched (notFound, výjimka) → null.
     *
     * @param array<string, mixed> $party
     */
    private function resolvePerson(array $party, int $messageNdx): ?int
    {
        if ($this->partyResolver === null) {
            return null;
        }

        $identifiers = [];
        foreach (['companyId', 'vatId', 'taxId'] as $key) {
            $value = $party[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $identifiers[$key] = trim($value);
            }
        }
        if ($identifiers === []) {
            return null;
        }

        try {
            $result = $this->partyResolver->resolve($identifiers, identifiersOnly: true);
        } catch (\Throwable $e) {
            ErrorLogger::warn('MessagePartnerWriter: party resolve failed, partner_person left untouched', [
                'messageNdx' => $messageNdx,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $result->status === ResolveStatus::Matched && $result->matchedId !== null
            ? $result->matchedId
            : null;
    }
}
