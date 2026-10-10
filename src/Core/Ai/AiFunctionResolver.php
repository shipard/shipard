<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\AiFunctionException;
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
 * deklarace funkce / mapa úrovní. Připojení: přepsání → řádek → výchozí
 * aktivní připojení zdroje dat. Model: vlastní ID mimo katalog vyžaduje
 * `model_like` (D43); ID pro platformu bere z `platformIds`, zadané ID
 * jde ven beze změny, pokud na platformu patří (i s datovou příponou).
 *
 * Chyby = {@see AiFunctionException} s kódem; všechno jsou chyby
 * nastavení, ne běhu. Bez compiled configu (prázdný katalog) se model
 * neověřuje a jde ven tak, jak je — jako dřív, chybu vrátí API.
 */
class AiFunctionResolver
{
    public const string SETTINGS_TABLE = 'core_ai_function_settings';
    public const string PLATFORMS_CFG_ITEM = 'core.ai.platforms';
    public const string DEFAULT_PLATFORM = ModelCatalog::PLATFORM_ANTHROPIC;

    private ?AiFunctionRegistry $registry;
    private ?ModelCatalog $catalog;
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

    /**
     * @throws AiFunctionException
     */
    public function resolve(string $functionId, ?AiCallOverride $override = null): ResolvedAiCall
    {
        $override ??= AiCallOverride::none();
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
        $platform = trim((string) ($connection['provider'] ?? '')) ?: self::DEFAULT_PLATFORM;
        $platforms = $this->config?->cfgItem(self::PLATFORMS_CFG_ITEM);
        if (is_array($platforms) && $platforms !== [] && !isset($platforms[$platform])) {
            throw new AiFunctionException(
                AiFunctionException::UNKNOWN_PLATFORM,
                "AI connection '{$connection['backend_id']}' uses unknown platform '{$platform}'",
                ['platform' => $platform, 'connectionId' => (int) $connection['id']],
            );
        }
        $baseUrl = trim((string) ($connection['base_url'] ?? ''));
        if ($baseUrl === '' && is_array($platforms) && is_string($platforms[$platform]['baseUrl'] ?? null)) {
            $baseUrl = trim($platforms[$platform]['baseUrl']);
        }

        // --- model -------------------------------------------------------
        [$model, $origins['model']] = $this->pickModel($function, $override, $row);
        $modelLike = $override->modelLike ?? self::text($row['model_like'] ?? null);
        [$platformModel, $shapeAs, $catalogModel] = $this->placeModel($function, $model, $modelLike, $platform, $origins['model']);

        // --- ladění ------------------------------------------------------
        [$thinking, $origins['thinking']] = $this->pickTuning('thinking', $override->thinking, $row);
        [$effort, $origins['effort']] = $this->pickTuning('effort', $override->effort, $row);
        [$maxTokens, $origins['maxTokens']] = $this->pickMaxTokens($function, $override, $row);

        // --- klíč --------------------------------------------------------
        $apiKey = $this->apiKey($connection);

        return new ResolvedAiCall(
            functionId: $functionId,
            platform: $platform,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
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
        );
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
        return [$connection, $origin];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string} zadané ID modelu + původ
     */
    private function pickModel(AiFunction $function, AiCallOverride $override, array $row): array
    {
        if ($override->model !== null) {
            return [$override->model, ResolvedAiCall::ORIGIN_OVERRIDE];
        }
        $rowModel = self::text($row['model'] ?? null);
        if ($rowModel !== null) {
            return [$rowModel, ResolvedAiCall::ORIGIN_FUNCTION];
        }
        $tierModel = $this->catalog()->defaultModel($function->tier);
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
     * kontrol (chybu vrátí API, jako dřív).
     *
     * @return array{0: string, 1: ?string, 2: ?string} [platformModel, shapeAs, catalogModel]
     */
    private function placeModel(AiFunction $function, string $model, ?string $modelLike, string $platform, string $origin): array
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
            $this->assertSupported($function, $likeSpec, $model);
            return [$model, $likeSpec->id, null];
        }

        if ($catalog->platformOf($model) === $platform) {
            $platformModel = $model; // zadané ID na platformu patří — beze změny (i s datovou příponou)
        } elseif (isset($spec->platformIds[$platform])) {
            $platformModel = $spec->platformIds[$platform];
        } else {
            throw new AiFunctionException(
                AiFunctionException::MODEL_NOT_ON_PLATFORM,
                "Model '{$spec->id}' of AI function '{$function->id}' is not available on platform '{$platform}'",
                ['functionId' => $function->id, 'model' => $spec->id, 'platform' => $platform],
            );
        }
        $this->assertSupported($function, $spec, $platformModel);

        if ($origin === ResolvedAiCall::ORIGIN_TIER && $spec->isRetiring()
            && !isset(self::$retiringWarned[$spec->id])
        ) {
            // Modely z mapy úrovní hlídá repo, ne zdroj dat (D42) — jen log.
            self::$retiringWarned[$spec->id] = true;
            ErrorLogger::warn('AiFunctionResolver: tier model is being retired — update core.ai.tiers', [
                'model' => $spec->id,
                'tier' => $function->tier,
                'retiresAt' => $spec->retiresAt,
                'replacedBy' => $spec->replacedBy,
            ]);
        }

        return [$platformModel, $spec->id, $spec->id];
    }

    /** Zobecněná pojistka D35: funkce s tool smyčkou a model, který ji bez thinking bloků neumí. */
    private function assertSupported(AiFunction $function, ModelSpec $spec, string $model): void
    {
        if ($function->requiresTools() && $spec->chatToolLoop === false) {
            throw new AiFunctionException(
                AiFunctionException::MODEL_NOT_SUPPORTED_FOR_FUNCTION,
                "Model '{$model}' cannot serve AI function '{$function->id}' yet (tool loop needs thinking blocks returned — phase 4)",
                ['functionId' => $function->id, 'model' => $model, 'requires' => AiFunction::REQUIRES_TOOLS],
            );
        }
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

    /** @param array<string, mixed> $connection */
    private function apiKey(array $connection): string
    {
        $code = (string) ($connection['backend_id'] ?? '');
        $details = ['connectionId' => (int) ($connection['id'] ?? 0), 'connectionCode' => $code];
        try {
            $key = $this->backends->apiKey($connection);
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
