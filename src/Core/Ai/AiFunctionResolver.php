<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\Exception\DevAiKeyException;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;

/**
 * Jeden resolver volání AI funkce (tasks/ai-functions.md D40): z deklarace
 * funkce ({@see AiFunctionRegistry}), řádku nastavení funkce
 * (`core_ai_function_settings`), mapy úrovní a katalogu modelů
 * ({@see ModelCatalog}), připojení (`core_ai_backends`) a přepsání
 * volajícího ({@see AiCallOverride}) složí {@see ResolvedAiCall}.
 *
 * Pořadí zdrojů pro každou hodnotu zvlášť: přepsání → řádek funkce →
 * deklarace funkce / mapa úrovní. Připojení: přepsání (id, nebo kód —
 * `server` = serverové připojení pro vývoj) → řádek → výchozí aktivní
 * připojení zdroje dat (bez vlastního klíče serverové, má-li zdroj dat
 * příznak — {@see AiBackendResolver::defaultBackend()}, původ `server`;
 * tasks/testing-phase4.md D40). Model: vlastní ID mimo katalog vyžaduje
 * `model_like` (D43); ID pro platformu skládá {@see AiPlatform} (prefix
 * geografického profilu + ID rodiny `modelIds` z `platformIds`,
 * tasks/ai-bedrock-eu.md D57), zadané ID jde ven beze změny, pokud na
 * platformu patří (rodina i prefix sedí, i s datovou příponou).
 *
 * Chyby = {@see AiFunctionException} s kódem; všechno jsou chyby
 * nastavení, ne běhu. Bez compiled configu (prázdný katalog) se model
 * neověřuje a jde ven tak, jak je — jako dřív, chybu vrátí API.
 */
class AiFunctionResolver
{
    public const string SETTINGS_TABLE = 'core_ai_function_settings';
    public const string PLATFORMS_CFG_ITEM = AiPlatform::CFG_ITEM;
    public const string DEFAULT_PLATFORM = ModelCatalog::PLATFORM_ANTHROPIC;

    private ?AiFunctionRegistry $registry;
    private ?ModelCatalog $catalog;
    private ?AiPlatforms $platforms = null;
    private AiBackendResolver $backends;

    /** @var array<string, true> modely úrovní, u kterých už padlo varování o konci */
    private static array $retiringWarned = [];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig = null,
        ?AiFunctionRegistry $registry = null,
        ?ModelCatalog $catalog = null,
        ?AiBackendResolver $backends = null,
    ) {
        $this->registry = $registry;
        $this->catalog = $catalog;
        $this->backends = $backends ?? new AiBackendResolver($db, $dsConfig);
    }

    public function registry(): AiFunctionRegistry
    {
        return $this->registry ??= AiFunctionRegistry::fromConfig($this->config);
    }

    public function catalog(): ModelCatalog
    {
        return $this->catalog ??= ModelCatalog::fromConfig($this->config);
    }

    public function platforms(): AiPlatforms
    {
        return $this->platforms ??= AiPlatforms::fromConfig($this->config);
    }

    /**
     * Plán volání **bez klíče** (tasks/ai-settings-section.md D51): tytéž
     * kroky jako {@see resolve()}, klíč připojení se jen ověří jako uložený
     * (jinak `KEY_MISSING`), nedešifruje — pro detail AI funkce v Nastavení
     * a `ai-test`. `SECRETS_UNAVAILABLE` / `KEY_CORRUPTED` vzniknou až při
     * dešifrování v `resolve()`.
     *
     * @throws AiFunctionException
     */
    public function plan(string $functionId, ?AiCallOverride $override = null): AiCallPlan
    {
        return $this->decide($functionId, $override ?? AiCallOverride::none())['plan'];
    }

    /**
     * Hotové volání = plán + dešifrovaný klíč. Model z mapy úrovní, který
     * končí, zaloguje jedno varování za proces (D42) — jen tady, náhled
     * v Nastavení neloguje.
     *
     * @throws AiFunctionException
     */
    public function resolve(string $functionId, ?AiCallOverride $override = null): ResolvedAiCall
    {
        $decision = $this->decide($functionId, $override ?? AiCallOverride::none());
        $this->warnRetiringTierModel($decision['plan'], $decision['function']);
        return ResolvedAiCall::fromPlan($decision['plan'], $this->apiKey($decision['connection']));
    }

    /**
     * „Co by se stalo“ (D51): plán + úpravy, které by {@see RequestShaper}
     * pro efektivní model provedl, nebo zachycená chyba nastavení. Prázdný
     * katalog / model mimo katalog = bez úprav (jako v klientovi). Čte
     * detail AI funkce v Nastavení a `ai-test`.
     */
    public function preview(string $functionId, ?AiCallOverride $override = null): AiCallPreview
    {
        try {
            $plan = $this->plan($functionId, $override);
        } catch (AiFunctionException $e) {
            return AiCallPreview::failed($e);
        }
        $spec = $this->catalog()->find($plan->shapeAs ?? $plan->model);
        $changes = $spec !== null ? RequestShaper::shape($plan->previewParams(), $spec)[1] : [];
        return AiCallPreview::ok($plan, $changes);
    }

    /**
     * Rozhodnutí bez klíče + řádek připojení a deklarace funkce pro
     * {@see resolve()}.
     *
     * @return array{plan: AiCallPlan, connection: array<string, mixed>, function: AiFunction}
     * @throws AiFunctionException
     */
    private function decide(string $functionId, AiCallOverride $override): array
    {
        $function = $this->registry()->get($functionId);
        if ($function === null) {
            throw new AiFunctionException(
                AiFunctionException::UNKNOWN_FUNCTION,
                "AI function '{$functionId}' is not declared by any active module (run ds-upgrade?)",
                ['functionId' => $functionId],
            );
        }
        $row = $this->settingsRow($functionId);
        $origins = [];

        // --- připojení ---------------------------------------------------
        [$connection, $origins['connection']] = $this->resolveConnection($functionId, $override, $row);
        $platformId = trim((string) ($connection['provider'] ?? '')) ?: self::DEFAULT_PLATFORM;
        $platforms = $this->platforms();
        if (!$platforms->isEmpty() && !$platforms->has($platformId)) {
            throw new AiFunctionException(
                AiFunctionException::UNKNOWN_PLATFORM,
                "AI connection '{$connection['backend_id']}' uses unknown platform '{$platformId}'",
                ['platform' => $platformId, 'connectionId' => (int) $connection['id'], 'connectionCode' => (string) ($connection['backend_id'] ?? '')],
            );
        }
        // Bez cfgItemu platforem (před ds-upgrade) záložní platforma bez adresy — klient má výchozí.
        $platform = $platforms->getOrFallback($platformId);
        $baseUrl = trim((string) ($connection['base_url'] ?? '')) ?: $platform->baseUrl;

        // --- model -------------------------------------------------------
        [$model, $origins['model']] = $this->pickModel($function, $override, $row, $platform);
        $modelLike = $override->modelLike ?? self::text($row['model_like'] ?? null);
        [$platformModel, $shapeAs, $catalogModel] = $this->placeModel($function, $model, $modelLike, $platform, $origins['model']);

        // --- ladění ------------------------------------------------------
        [$thinking, $origins['thinking']] = $this->pickTuning('thinking', $override->thinking, $row);
        [$effort, $origins['effort']] = $this->pickTuning('effort', $override->effort, $row);
        [$maxTokens, $origins['maxTokens']] = $this->pickMaxTokens($function, $override, $row);

        // --- klíč: jen přítomnost, dešifruje až resolve() -----------------
        $this->assertKeyStored($connection);

        $plan = new AiCallPlan(
            functionId: $functionId,
            platform: $platform->id,
            baseUrl: $baseUrl,
            model: $platformModel,
            shapeAs: $shapeAs,
            catalogModel: $catalogModel,
            maxTokens: $maxTokens,
            temperature: $function->temperature,
            thinking: $thinking,
            effort: $effort,
            connectionId: (int) $connection['id'],
            connectionCode: (string) ($connection['backend_id'] ?? ''),
            origins: $origins,
            dialect: $platform->dialect,
            priceMultiplier: $platform->priceMultiplier,
        );
        return ['plan' => $plan, 'connection' => $connection, 'function' => $function];
    }

    /**
     * Model z mapy úrovní, který končí, hlídá repo, ne zdroj dat (D42) —
     * jedno varování za proces, jen při skutečném volání.
     */
    private function warnRetiringTierModel(AiCallPlan $plan, AiFunction $function): void
    {
        if (($plan->origins['model'] ?? null) !== ResolvedAiCall::ORIGIN_TIER || $plan->catalogModel === null) {
            return;
        }
        $spec = $this->catalog()->find($plan->catalogModel);
        if ($spec === null || !$spec->isRetiring() || isset(self::$retiringWarned[$spec->id])) {
            return;
        }
        self::$retiringWarned[$spec->id] = true;
        ErrorLogger::warn('AiFunctionResolver: tier model is being retired — update core.ai.tiers', [
            'model' => $spec->id,
            'tier' => $function->tier,
            'retiresAt' => $spec->retiresAt,
            'replacedBy' => $spec->replacedBy,
        ]);
    }

    /** @return array<string, mixed> řádek `core_ai_function_settings`, prázdný před ds-upgrade */
    private function settingsRow(string $functionId): array
    {
        try {
            $row = $this->db->fetchRow(
                'SELECT * FROM `' . self::SETTINGS_TABLE . '` WHERE `function_id` = %s LIMIT 1',
                $functionId,
            );
        } catch (\Throwable $e) {
            // Tabulka před ds-upgrade neexistuje — chovej se jako bez přepsání.
            ErrorLogger::warn('AiFunctionResolver: function settings unavailable — using declaration defaults', [
                'functionId' => $functionId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
        return $row ?? [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: array<string, mixed>, 1: string} řádek připojení + původ
     */
    private function resolveConnection(string $functionId, AiCallOverride $override, array $row): array
    {
        $wanted = null;
        $origin = ResolvedAiCall::ORIGIN_DEFAULT;
        if ($override->connectionId !== null && $override->connectionId > 0) {
            $wanted = $override->connectionId;
            $origin = ResolvedAiCall::ORIGIN_OVERRIDE;
        } elseif ($override->connectionCode !== null) {
            $code = $override->connectionCode;
            $connection = $this->backends->backendByCode($code);
            if ($connection === null) {
                throw new AiFunctionException(
                    AiFunctionException::NO_CONNECTION,
                    $code === ServerAiConnection::CODE
                        ? "The server AI connection selected for '{$functionId}' (override) is not available — a development server with a dev-ai-key and a free data source (dataClass) are required"
                        : "AI connection '{$code}' selected for '{$functionId}' (override) is missing or inactive",
                    ['functionId' => $functionId, 'connectionCode' => $code, 'origin' => ResolvedAiCall::ORIGIN_OVERRIDE],
                );
            }
            return [$connection, ResolvedAiCall::ORIGIN_OVERRIDE];
        } elseif (is_numeric($row['connection'] ?? null) && (int) $row['connection'] > 0) {
            $wanted = (int) $row['connection'];
            $origin = ResolvedAiCall::ORIGIN_FUNCTION;
        }

        if ($wanted !== null) {
            $connection = $this->backends->backendByNdx($wanted);
            if ($connection === null) {
                throw new AiFunctionException(
                    AiFunctionException::NO_CONNECTION,
                    "AI connection #{$wanted} selected for '{$functionId}' ({$origin}) is missing or inactive",
                    ['functionId' => $functionId, 'connectionId' => $wanted, 'origin' => $origin],
                );
            }
            return [$connection, $origin];
        }

        $connection = $this->backends->defaultBackend();
        if ($connection === null) {
            throw new AiFunctionException(
                AiFunctionException::NO_CONNECTION,
                'No active default AI connection is configured',
                ['functionId' => $functionId],
            );
        }
        if (ServerAiConnection::isServerRow($connection)) {
            $origin = ResolvedAiCall::ORIGIN_SERVER;
        }
        return [$connection, $origin];
    }

    /**
     * Model úrovně z mapy úrovní platí pro platformu připojení
     * (`platformModels`, tasks/ai-bedrock-eu.md D58) — původ zůstává `tier`.
     *
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string} zadané ID modelu + původ
     */
    private function pickModel(AiFunction $function, AiCallOverride $override, array $row, AiPlatform $platform): array
    {
        if ($override->model !== null) {
            return [$override->model, ResolvedAiCall::ORIGIN_OVERRIDE];
        }
        $rowModel = self::text($row['model'] ?? null);
        if ($rowModel !== null) {
            return [$rowModel, ResolvedAiCall::ORIGIN_FUNCTION];
        }
        $tierModel = $this->catalog()->defaultModel($function->tier, $platform->id);
        if ($tierModel === null) {
            throw new AiFunctionException(
                AiFunctionException::NO_MODEL,
                "No model for tier '{$function->tier}' of AI function '{$function->id}' (cfgItem "
                . ModelCatalog::TIERS_CFG_ITEM . ' missing — run ds-upgrade?)',
                ['functionId' => $function->id, 'tier' => $function->tier],
            );
        }
        return [$tierModel, ResolvedAiCall::ORIGIN_TIER];
    }

    /**
     * ID modelu pro platformu, model katalogu pro tvarování a položka
     * katalogu (null u vlastního ID). Prázdný katalog = beze změny a bez
     * kontrol (chybu vrátí API, jako dřív). Zadané ID, které na platformu
     * patří (rodina `modelIds` i prefix), odchází beze změny — i s datovou
     * příponou; ID jiné rodiny nebo s jiným geografickým prefixem
     * (`anthropic.…` bez `eu.`, `us.…` na EU) se přeloží na ID platformy.
     *
     * @return array{0: string, 1: ?string, 2: ?string} [platformModel, shapeAs, catalogModel]
     */
    private function placeModel(AiFunction $function, string $model, ?string $modelLike, AiPlatform $platform, string $origin): array
    {
        $catalog = $this->catalog();
        if ($catalog->isEmpty()) {
            return [$model, null, null];
        }

        $spec = $catalog->find($model);
        if ($spec === null) {
            // Vlastní ID (D43): tvaruje se podle model_like, odchází beze změny.
            if ($modelLike === null) {
                throw new AiFunctionException(
                    AiFunctionException::UNKNOWN_MODEL,
                    "Model '{$model}' of AI function '{$function->id}' is not in the model catalog and no catalog model to shape the request after is set (model_like)",
                    ['functionId' => $function->id, 'model' => $model, 'origin' => $origin],
                );
            }
            $likeSpec = $catalog->find($modelLike);
            if ($likeSpec === null) {
                throw new AiFunctionException(
                    AiFunctionException::UNKNOWN_MODEL,
                    "Model '{$modelLike}' to shape requests of AI function '{$function->id}' after is not in the model catalog",
                    ['functionId' => $function->id, 'model' => $model, 'modelLike' => $modelLike],
                );
            }
            return [$model, $likeSpec->id, null];
        }

        if ($platform->ownsModelId($model, $catalog)) {
            $platformModel = $model; // zadané ID na platformu patří — beze změny (i s datovou příponou)
        } else {
            $platformModel = $platform->modelIdFor($spec);
            if ($platformModel === null) {
                throw new AiFunctionException(
                    AiFunctionException::MODEL_NOT_ON_PLATFORM,
                    "Model '{$spec->id}' of AI function '{$function->id}' is not available on platform '{$platform->id}'",
                    ['functionId' => $function->id, 'model' => $spec->id, 'platform' => $platform->id],
                );
            }
        }
        return [$platformModel, $spec->id, $spec->id];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: ?string, 1: string} hodnota pro API (null = neposílat) + původ
     */
    private function pickTuning(string $column, ?string $overrideValue, array $row): array
    {
        $value = AiCallOverride::none()->tuningValue($overrideValue);
        if ($value !== null) {
            return [$value === 'auto' ? null : $value, ResolvedAiCall::ORIGIN_OVERRIDE];
        }
        $rowValue = AiCallOverride::none()->tuningValue(self::text($row[$column] ?? null));
        if ($rowValue !== null) {
            return [$rowValue === 'auto' ? null : $rowValue, ResolvedAiCall::ORIGIN_FUNCTION];
        }
        // Deklarace funkce thinking / effort nemá (D36) = neposílat.
        return [null, ResolvedAiCall::ORIGIN_DECLARATION];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: int, 1: string}
     */
    private function pickMaxTokens(AiFunction $function, AiCallOverride $override, array $row): array
    {
        if ($override->maxTokens !== null && $override->maxTokens > 0) {
            return [$override->maxTokens, ResolvedAiCall::ORIGIN_OVERRIDE];
        }
        if (is_numeric($row['max_tokens'] ?? null) && (int) $row['max_tokens'] > 0) {
            return [(int) $row['max_tokens'], ResolvedAiCall::ORIGIN_FUNCTION];
        }
        return [$function->maxTokens, ResolvedAiCall::ORIGIN_DECLARATION];
    }

    /**
     * Klíč je uložený (zašifrovaný text v řádku připojení ze `SELECT *`) —
     * bez dešifrování; totéž, co {@see apiKey()} hlásí po dešifrování.
     *
     * @param array<string, mixed> $connection
     */
    private function assertKeyStored(array $connection): void
    {
        $stored = $connection['api_key'] ?? null;
        if ($stored === null || $stored === '') {
            $code = (string) ($connection['backend_id'] ?? '');
            throw new AiFunctionException(
                AiFunctionException::KEY_MISSING,
                "AI connection '{$code}' has no API key set. Run ai-backend-set-key.",
                ['connectionId' => (int) ($connection['id'] ?? 0), 'connectionCode' => $code],
            );
        }
    }

    /** @param array<string, mixed> $connection */
    private function apiKey(array $connection): string
    {
        $code = (string) ($connection['backend_id'] ?? '');
        $details = ['connectionId' => (int) ($connection['id'] ?? 0), 'connectionCode' => $code];
        try {
            $key = $this->backends->apiKey($connection);
        } catch (DevAiKeyException $e) {
            // Serverové připojení pro vývoj (D40): klíč v /etc/shipard/secrets chybí nebo nejde číst.
            throw new AiFunctionException(
                AiFunctionException::SECRETS_UNAVAILABLE,
                'Server AI key cannot be read: ' . $e->getMessage(),
                $details,
                $e,
            );
        } catch (SecretsKeyMissingException | SecretsKeyInsecureException $e) {
            throw new AiFunctionException(
                AiFunctionException::SECRETS_UNAVAILABLE,
                'Server cannot decrypt the AI connection API key: ' . $e->getMessage(),
                $details,
                $e,
            );
        } catch (InvalidCiphertextException $e) {
            throw new AiFunctionException(
                AiFunctionException::KEY_CORRUPTED,
                "Stored API key of AI connection '{$code}' cannot be decrypted (corrupted or wrong secrets.key)",
                $details,
                $e,
            );
        } catch (\RuntimeException $e) {
            throw new AiFunctionException(
                AiFunctionException::SECRETS_UNAVAILABLE,
                'Server cannot decrypt the AI connection API key: ' . $e->getMessage(),
                $details,
                $e,
            );
        }
        if ($key === null || $key === '') {
            throw new AiFunctionException(
                AiFunctionException::KEY_MISSING,
                "AI connection '{$code}' has no API key set. Run ai-backend-set-key.",
                $details,
            );
        }
        return $key;
    }

    private static function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        return $text === '' ? null : $text;
    }

    /** Zapomene, u kterých modelů úrovní už varoval (testy). */
    public static function resetWarningsForTesting(): void
    {
        self::$retiringWarned = [];
    }
}
