<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Jedna platforma AI připojení (cfgItem `core.ai.platforms`,
 * tasks/ai-functions.md D39, tasks/ai-bedrock-eu.md D57): dialekt API,
 * výchozí adresa, která rodina ID modelů z katalogu (`platformIds`) na ní
 * platí, prefix před ID (geografický profil Bedrocku `eu.`) a násobitel
 * ceny proti ceníku katalogu (D60).
 *
 * Readonly hodnotový objekt, továrna {@see fromArray()} s výchozími
 * hodnotami pro položku, která nese jen název (starší konfigurace,
 * testy); bez cfgItemu dává {@see fallback()} platformu bez adresy
 * a bez kontrol — resolver se pak chová jako před katalogem.
 */
final readonly class AiPlatform
{
    public const string CFG_ITEM = 'core.ai.platforms';
    public const string DIALECT_ANTHROPIC_MESSAGES = 'anthropic-messages';

    public function __construct(
        public string $id,
        public string $name,
        public string $dialect,
        public string $baseUrl,
        public string $modelIds,
        public string $modelIdPrefix,
        public float $priceMultiplier,
        public int $order,
    ) {
        if ($id === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1) {
            throw new \InvalidArgumentException("AiPlatform: invalid platform id '{$id}'");
        }
        if ($name === '') {
            throw new \InvalidArgumentException("AiPlatform {$id}: name is required");
        }
        if ($dialect === '') {
            throw new \InvalidArgumentException("AiPlatform {$id}: dialect is required");
        }
        if ($baseUrl !== '' && preg_match('#^https?://[^\s/]+#', $baseUrl) !== 1) {
            throw new \InvalidArgumentException("AiPlatform {$id}: baseUrl must be an http(s) URL");
        }
        if ($modelIds === '') {
            throw new \InvalidArgumentException("AiPlatform {$id}: modelIds must name a platformIds key of the model catalog");
        }
        if ($modelIdPrefix !== '' && preg_match('/^[a-z0-9-]+\.$/', $modelIdPrefix) !== 1) {
            throw new \InvalidArgumentException("AiPlatform {$id}: modelIdPrefix must look like 'eu.'");
        }
        if ($priceMultiplier <= 0) {
            throw new \InvalidArgumentException("AiPlatform {$id}: priceMultiplier must be positive");
        }
    }

    /**
     * Položka cfgItemu: `name`, `dialect`, `baseUrl`, `modelIds`,
     * `modelIdPrefix`, `priceMultiplier`, `order`. Chybějící pole =
     * výchozí hodnoty platformy Anthropic (dialekt Messages, bez adresy,
     * rodina ID = id platformy, bez prefixu, násobitel 1).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $id, array $data): self
    {
        $prefix = $data['modelIdPrefix'] ?? '';
        $multiplier = $data['priceMultiplier'] ?? 1;
        return new self(
            id: $id,
            name: is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : $id,
            dialect: is_string($data['dialect'] ?? null) && $data['dialect'] !== '' ? $data['dialect'] : self::DIALECT_ANTHROPIC_MESSAGES,
            baseUrl: is_string($data['baseUrl'] ?? null) ? rtrim(trim($data['baseUrl']), '/') : '',
            modelIds: is_string($data['modelIds'] ?? null) && $data['modelIds'] !== '' ? $data['modelIds'] : $id,
            modelIdPrefix: is_string($prefix) ? $prefix : '',
            priceMultiplier: is_numeric($multiplier) ? (float) $multiplier : 0.0,
            order: is_numeric($data['order'] ?? null) ? (int) $data['order'] : 1000,
        );
    }

    /**
     * Platforma bez cfgItemu (compiled config před `ds-upgrade`): bez
     * adresy (klient má výchozí), dialekt Messages, rodina ID = id.
     */
    public static function fallback(string $id): self
    {
        return self::fromArray($id, []);
    }

    /**
     * ID modelu z katalogu pro tuhle platformu: prefix + ID rodiny
     * `modelIds`; null = model na platformě není.
     */
    public function modelIdFor(ModelSpec $spec): ?string
    {
        $id = $spec->platformIds[$this->modelIds] ?? null;
        return $id !== null ? $this->modelIdPrefix . $id : null;
    }

    /**
     * Zadané ID už na platformu patří (rodina ID i prefix sedí) — jde ven
     * beze změny, i s datovou příponou. ID jiné rodiny nebo s jiným
     * prefixem (`anthropic.…` bez `eu.`, `us.…`) se překládá.
     */
    public function ownsModelId(string $modelId, ModelCatalog $catalog): bool
    {
        return $catalog->platformOf($modelId) === $this->modelIds
            && $catalog->geoPrefixOf($modelId) === $this->modelIdPrefix;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'dialect' => $this->dialect,
            'baseUrl' => $this->baseUrl,
            'modelIds' => $this->modelIds,
            'modelIdPrefix' => $this->modelIdPrefix,
            'priceMultiplier' => $this->priceMultiplier,
            'order' => $this->order,
        ];
    }
}
