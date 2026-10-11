<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Config\ConfigRuntime;

/**
 * Texty k AI volání pro uživatele (tasks/ai-settings-section.md D51, D52)
 * z cfgItem `core.ai.texts` (`modules/core/ai/config/texts.jsonc`,
 * lokalizovaný kompilací konfigurace): chyby resolveru
 * ({@see AiFunctionException}) s radou, kde to opravit, úpravy požadavku
 * ({@see RequestChange}), původ hodnot a druhy chyb API. **Jediný zdroj
 * vět** pro detail AI funkce v Nastavení, `ai-test` i chybu asistenta.
 *
 * Bez katalogu (compiled config před `ds-upgrade`, testy) vrací
 * anglický originál — zprávu výjimky, `(string) $change`, surový kód;
 * žádný druhý anglický zdroj v PHP (vzor `PlanMessageTexts`).
 */
final class AiTexts
{
    public const string CFG_ITEM = 'core.ai.texts';

    public function __construct(
        private readonly ?ConfigRuntime $config,
        private readonly ?AiFunctionRegistry $registry = null,
    ) {}

    /**
     * Text klíče katalogu s doplněnými `{param}`; null = katalog nebo klíč chybí.
     *
     * @param array<string, scalar|null> $params
     */
    public function t(string $key, array $params = []): ?string
    {
        $catalog = $this->config?->cfgItem(self::CFG_ITEM);
        $text = is_array($catalog) ? ($catalog[$key]['text'] ?? null) : null;
        if (!is_string($text)) {
            return null;
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = $value === null ? '' : (string) $value;
        }
        return strtr($text, $replace);
    }

    /**
     * Věta chyby nastavení; s `$withHint` i rada, kde to opravit. Bez
     * katalogu původní anglická zpráva výjimky.
     */
    public function error(AiFunctionException $e, bool $withHint = true): string
    {
        $params = $this->errorParams($e);
        $text = null;
        foreach ($this->errorKeys($e) as $key) {
            $text = $this->t('error.' . $key, $params);
            if ($text !== null) {
                break;
            }
        }
        if ($text === null) {
            return $e->getMessage();
        }
        $hint = $withHint ? $this->hint($e) : null;
        return $hint !== null ? $text . ' ' . $hint : $text;
    }

    /** Rada, kde chybu opravit; null bez katalogu nebo bez rady. */
    public function hint(AiFunctionException $e): ?string
    {
        $params = $this->errorParams($e);
        foreach ($this->errorKeys($e) as $key) {
            $text = $this->t('hint.' . $key, $params);
            if ($text !== null) {
                return $text;
            }
        }
        return null;
    }

    /** Popis úpravy požadavku; bez katalogu anglická věta shaperu. */
    public function change(RequestChange $change): string
    {
        return $this->t('change.' . $change->code, $change->params) ?? (string) $change;
    }

    /** Původ hodnoty (`ResolvedAiCall::ORIGIN_*`); bez katalogu surový kód. */
    public function origin(string $origin): string
    {
        return $this->t('origin.' . $origin) ?? $origin;
    }

    /**
     * Druh chyby API podle stavu / kódu (neplatný klíč, model na platformě
     * není, vyčerpaný limit útraty…); bez katalogu `HTTP <stav> <typ>: <zpráva>`.
     */
    public function apiError(LlmApiException $e, ?string $model = null): string
    {
        $key = match (true) {
            $e->isSpendLimitReached() => 'spendLimit',
            $e->statusCode === 401 => 'authentication',
            $e->statusCode === 403 => 'forbidden',
            $e->statusCode === 404 => 'notFound',
            $e->statusCode === 429 => 'rateLimit',
            $e->statusCode === 0 => 'transport',
            $e->statusCode >= 500 => 'server',
            default => 'other',
        };
        $params = ['status' => $e->statusCode, 'type' => $e->errorType, 'model' => $model ?? '', 'message' => $e->getMessage()];
        return $this->t('api.' . $key, $params)
            ?? "HTTP {$e->statusCode} {$e->errorType}: {$e->getMessage()}";
    }

    /**
     * Klíče od nejkonkrétnějšího: varianta podle detailů (zvolené připojení,
     * `model_like`), pak holý kód.
     *
     * @return list<string>
     */
    private function errorKeys(AiFunctionException $e): array
    {
        $variant = match ($e->errorCode) {
            AiFunctionException::NO_CONNECTION => isset($e->details['connectionId']) ? 'selected' : null,
            AiFunctionException::UNKNOWN_MODEL => isset($e->details['modelLike']) ? 'like' : null,
            default => null,
        };
        return $variant !== null ? [$e->errorCode . '.' . $variant, $e->errorCode] : [$e->errorCode];
    }

    /**
     * Detaily výjimky jako parametry textu + `function` = název funkce
     * z registru (bez registru její id).
     *
     * @return array<string, scalar|null>
     */
    private function errorParams(AiFunctionException $e): array
    {
        $params = [];
        foreach ($e->details as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $params[(string) $name] = $value;
            }
        }
        $functionId = (string) ($params['functionId'] ?? '');
        $params['function'] = $functionId !== ''
            ? ($this->registry?->get($functionId)?->name ?? $functionId)
            : '';
        return $params;
    }
}
