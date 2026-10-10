<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní provisioning systémového uživatele `_ai_analyzer`, default
 * AI backendu a default profilu. Volá se z:
 *   - `ds-upgrade` (auto-hook na konci, vedle MailRouterProvisioner)
 *   - `ai-analyzer-bootstrap` (manuální spuštění z Fáze C)
 *   - `ai-analyzer-setup` (musí zajistit existenci uživatele před tokenem)
 *
 * Spec: tasks/mail-phase3a.md §6.1, §6.2, §11.
 */
class AIAnalyzerProvisioner
{
    public const ANALYZER_LOGIN = '_ai_analyzer';
    public const DEFAULT_BACKEND_ID = 'default';

    /**
     * Model nově zakládaného výchozího backendu (tasks/ai-models-phase0.md
     * F0-D8): Sonnet 4.6 — stejná cena a tokenizer jako 4.5, přijímá
     * `temperature`, bez parametru nepřemýšlí; aktivní nejméně do
     * 17. 2. 2027. Řada 5 se zkouší přes druhý backend (F0-D6).
     */
    public const DEFAULT_MODEL = 'claude-sonnet-4-6';

    /**
     * Vyřazené modely → náhrada (F0-D8). Klíč sedí na přesné ID i na ID
     * s datovou příponou (`claude-sonnet-4-5-20250929`); ID s prefixem
     * platformy (`anthropic.claude-sonnet-4-5` na Bedrocku) se nemění —
     * partnerské platformy mají vlastní termíny. Připraveno na další řádky.
     *
     * @var array<string, string>
     */
    public const RETIRED_MODELS = [
        'claude-sonnet-4-5' => 'claude-sonnet-4-6',
    ];
    public const DEFAULT_PROFILE_ID = 'czech_general';
    public const DEFAULT_PROFILE_TEMPLATE = __DIR__ . '/../profiles/czech_general.jsonc';

    /** Původní id default profilu — jen pro jednorázový rename krok. */
    private const LEGACY_PROFILE_ID = 'czech_invoices';
    private const DEFAULT_PROFILE_NAME = 'Obecná analýza pošty (česky)';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * @return array{
     *     user: array{id: int, created: bool},
     *     backend: array{id: int, created: bool},
     *     retired: list<array{id: int, backend_id: string, from: string, to: string}>,
     *     profile_rename: array{renamed: int},
     *     profile: array{id: int, profile_id: string, created: bool, skipped_reason?: string},
     *     queue_fix: array{fixed: int}
     * }
     */
    public function provision(): array
    {
        $user = $this->ensureAnalyzerUser();
        $backend = $this->ensureDefaultBackend();
        $retired = $this->retireModels();
        $renamed = $this->renameLegacyProfile();
        $profile = $this->ensureDefaultProfile($backend['id']);
        $queueFix = $this->fixQueuedArchivedMessages();

        return [
            'user' => $user,
            'backend' => $backend,
            'retired' => $retired,
            'profile_rename' => ['renamed' => $renamed],
            'profile' => $profile,
            'queue_fix' => ['fixed' => $queueFix],
        ];
    }

    /**
     * Náhrada vyřazeného modelu podle {@see RETIRED_MODELS}, null = model
     * se nemění. Shoda na přesné ID nebo na ID s datovou příponou
     * (`<id>-YYYYMMDD`); prefix platformy před ID shodu vylučuje.
     */
    public static function retiredReplacement(string $model): ?string
    {
        foreach (self::RETIRED_MODELS as $retired => $replacement) {
            if ($model === $retired || str_starts_with($model, $retired . '-')) {
                return $replacement;
            }
        }
        return null;
    }

    /**
     * Jednorázový idempotentní přepis vyřazených modelů u **všech** backendů
     * (F0-D8) — i u backendu hostovaného DS, který míří na AI gateway
     * (gateway model jen předává dál). Po přepisu navždy matchne 0 řádků.
     *
     * @return list<array{id: int, backend_id: string, from: string, to: string}> Změněné backendy.
     */
    public function retireModels(): array
    {
        $rows = $this->db->fetchAll('SELECT id, backend_id, model FROM core_ai_backends');
        $changed = [];
        foreach ($rows as $row) {
            $model = (string) ($row['model'] ?? '');
            $replacement = self::retiredReplacement($model);
            if ($replacement === null) {
                continue;
            }
            $this->db->updateWhere(
                'core_ai_backends',
                ['model' => $replacement, 'modified' => date('Y-m-d H:i:s')],
                'id = %i',
                (int) $row['id'],
            );
            $changed[] = [
                'id' => (int) $row['id'],
                'backend_id' => (string) ($row['backend_id'] ?? ''),
                'from' => $model,
                'to' => $replacement,
            ];
        }
        return $changed;
    }

    /**
     * Jednorázový idempotentní rename default profilu `czech_invoices` →
     * `czech_general` (spec tasks/mail-ai-profile-rename.md D2). Profil už
     * dávno není fakturový — analyzuje došlou poštu obecně. Přepisuje se
     * i `name`, jen v rámci tohoto kroku — běžný sync uživatelské úpravy
     * názvu nepřepisuje. Po přejmenování navždy matchne 0 řádků.
     *
     * @return int Počet přejmenovaných řádků (po prvním běhu 0 = no-op).
     */
    public function renameLegacyProfile(): int
    {
        $this->db->execute(
            'UPDATE core_mail_ai_profiles
                SET profile_id = %s, name = %s, modified = %s
              WHERE profile_id = %s',
            self::DEFAULT_PROFILE_ID,
            self::DEFAULT_PROFILE_NAME,
            date('Y-m-d H:i:s'),
            self::LEGACY_PROFILE_ID,
        );

        return $this->db->getAffectedRows();
    }

    /**
     * Idempotentní datová oprava: zprávy v Archivu/Koši (docState 80/90)
     * s analysis_state=10 (Ve frontě) nemají ve frontě co dělat — `/queue`
     * je nikdy nevydá a hrozí hromadná analýza při odarchivování. Vznikaly
     * zrcadlením archivní pošty před zavedením pravidla docState
     * v `IncomingMessageDocument::resolveInitialAnalysisState` (spec
     * tasks/mail-analysis-schema-fixes.md). Hotovo (40) do WHERE nepatří —
     * tam mohla zpráva dojít legálně workflow cestou.
     *
     * @return int Počet opravených řádků (po prvním běhu 0 = no-op).
     */
    public function fixQueuedArchivedMessages(): int
    {
        $this->db->execute(
            'UPDATE core_mail_incoming_messages SET analysis_state = %i
              WHERE analysis_state = %i AND docState IN %in',
            0,
            10,
            [80, 90],
        );

        return $this->db->getAffectedRows();
    }

    /**
     * @return array{id: int, created: bool}
     */
    public function ensureAnalyzerUser(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_system_users WHERE login = %s',
            self::ANALYZER_LOGIN,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }

        $randomPassword = bin2hex(random_bytes(32));
        $id = $this->db->insertRow('core_system_users', [
            'login' => self::ANALYZER_LOGIN,
            'password_hash' => password_hash($randomPassword, PASSWORD_DEFAULT),
            'full_name' => 'AI Analyzer (system)',
            'email' => null,
            'is_active' => 1,
            'is_system' => 1,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /**
     * Vytvoří (pokud chybí) default backend `default` (Anthropic Claude,
     * model {@see DEFAULT_MODEL}). `api_key` zůstává NULL, `is_active`
     * = false — admin doplní klíč přes `ai-analyzer-set-key` (Fáze C).
     * `temperature` NULL = parametr se neposílá (F0-D1); `thinking`
     * a `effort` nechává na DB defaultu `auto`.
     *
     * @return array{id: int, created: bool, skipped_reason?: string}
     */
    public function ensureDefaultBackend(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_ai_backends WHERE backend_id = %s',
            self::DEFAULT_BACKEND_ID,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }

        $existingDefault = $this->db->fetchRow(
            'SELECT id, backend_id FROM core_ai_backends WHERE is_default = %i',
            1,
        );

        if ($existingDefault !== null) {
            return [
                'id' => (int) $existingDefault['id'],
                'created' => false,
                'skipped_reason' => "Another backend is already marked as default: {$existingDefault['backend_id']}",
            ];
        }

        $now = date('Y-m-d H:i:s');
        $id = $this->db->insertRow('core_ai_backends', [
            'backend_id' => self::DEFAULT_BACKEND_ID,
            'name' => 'Anthropic Claude',
            'provider' => 'anthropic',
            'model' => self::DEFAULT_MODEL,
            'api_key' => null,
            'base_url' => null,
            // 0 = nenastaveno — limit se rozhoduje kaskádou profil → backend
            // → default v provideru analyzéru (jediné místo se skutečným číslem).
            'max_tokens' => 0,
            'temperature' => null,
            'is_default' => 1,
            'is_active' => 0,
            'docState' => 40,
            'docStateMain' => 3,
            'created' => $now,
            'modified' => $now,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /**
     * Vytvoří default profil `czech_general` ze šablony
     * `modules/core/mail/profiles/czech_general.jsonc`. Když profil tohoto
     * kódu existuje, beze změny ho přeskočí (admin může mít upravený
     * prompt). `profile_id` ve výsledku je kód provisionovaného default
     * profilu (pro výpisy v ds-upgrade), ne kód případného cizího defaultu.
     *
     * @return array{id: int, profile_id: string, created: bool, skipped_reason?: string}
     */
    public function ensureDefaultProfile(int $backendId): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_mail_ai_profiles WHERE profile_id = %s',
            self::DEFAULT_PROFILE_ID,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'profile_id' => self::DEFAULT_PROFILE_ID, 'created' => false];
        }

        $existingDefault = $this->db->fetchRow(
            'SELECT id, profile_id FROM core_mail_ai_profiles WHERE is_default = %i',
            1,
        );

        if ($existingDefault !== null) {
            return [
                'id' => (int) $existingDefault['id'],
                'profile_id' => self::DEFAULT_PROFILE_ID,
                'created' => false,
                'skipped_reason' => "Another profile is already marked as default: {$existingDefault['profile_id']}",
            ];
        }

        $template = self::loadProfileTemplate();
        $now = date('Y-m-d H:i:s');

        $id = $this->db->insertRow('core_mail_ai_profiles', [
            'profile_id' => (string) $template['profile_id'],
            'name' => (string) $template['name'],
            'backend' => $backendId,
            'supported_doc_types' => json_encode(
                $template['supported_doc_types'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'language' => (string) $template['language'],
            'prompt_version' => (string) $template['prompt_version'],
            'prompt_template' => (string) $template['prompt_template'],
            'output_schema' => json_encode(
                $template['output_schema'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'confidence_thresholds' => json_encode(
                $template['confidence_thresholds'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'is_default' => 1,
            'is_active' => 1,
            'docState' => 40,
            'docStateMain' => 3,
            'created' => $now,
            'modified' => $now,
        ]);

        return ['id' => $id, 'profile_id' => (string) $template['profile_id'], 'created' => true];
    }

    /**
     * Synchronizuje obsahová pole existujícího profilu z JSONC šablony,
     * pokud je šablona novější (SemVer na prompt_version). Admin pole
     * (name, is_default, is_active, backend) nechává netknutá — repo
     * šablona je source of truth jen pro obsahová pole. Bez $force nikdy
     * nedowngraduje ani nepřepisuje shodnou verzi; s $force zapíše vždy
     * (používá `ai-profile-reload --force`). Chybějící profil nevytváří —
     * od toho je ensureDefaultProfile().
     *
     * @return array{
     *     status: 'updated'|'up_to_date'|'db_newer'|'not_found',
     *     profile_id: string,
     *     id?: int,
     *     old_version?: string,
     *     new_version?: string
     * }
     */
    public function syncProfileFromTemplate(?string $templatePath = null, bool $force = false): array
    {
        $template = self::loadProfileTemplate($templatePath);
        $profileCode = (string) $template['profile_id'];

        $row = $this->db->fetchRow(
            'SELECT id, prompt_version FROM core_mail_ai_profiles WHERE profile_id = %s',
            $profileCode,
        );
        if ($row === null) {
            return ['status' => 'not_found', 'profile_id' => $profileCode];
        }

        $result = [
            'profile_id' => $profileCode,
            'id' => (int) $row['id'],
            'old_version' => (string) $row['prompt_version'],
            'new_version' => (string) $template['prompt_version'],
        ];

        if (!$force) {
            $cmp = self::compareVersions($result['new_version'], $result['old_version']);
            if ($cmp === 0) {
                return ['status' => 'up_to_date'] + $result;
            }
            if ($cmp < 0) {
                return ['status' => 'db_newer'] + $result;
            }
        }

        $this->db->updateWhere(
            'core_mail_ai_profiles',
            [
                'prompt_template' => (string) $template['prompt_template'],
                'prompt_version' => $result['new_version'],
                'language' => (string) $template['language'],
                'supported_doc_types' => json_encode(
                    $template['supported_doc_types'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                'output_schema' => json_encode(
                    $template['output_schema'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                'confidence_thresholds' => json_encode(
                    $template['confidence_thresholds'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                'modified' => date('Y-m-d H:i:s'),
            ],
            'id = %i',
            $result['id'],
        );

        return ['status' => 'updated'] + $result;
    }

    /**
     * SemVer compare s tolerancí na "v" prefix (např. "v1.1.0" vs "1.1.0").
     * version_compare zachází s nezvyklým prefixem nepředvídatelně, takže
     * ho odstříhneme manuálně. Public — používá i AiProfileReloadCommand
     * pro guard hlášky a dry-run před samotným zápisem.
     */
    public static function compareVersions(string $a, string $b): int
    {
        return version_compare(ltrim($a, 'vV'), ltrim($b, 'vV'));
    }

    /**
     * @return array{
     *     profile_id: string,
     *     name: string,
     *     language: string,
     *     prompt_version: string,
     *     prompt_template: string,
     *     supported_doc_types: array<int, string>,
     *     output_schema: array<string, mixed>,
     *     confidence_thresholds: array<string, float>
     * }
     */
    public static function loadProfileTemplate(?string $templatePath = null): array
    {
        return JsoncParser::parseFile($templatePath ?? self::DEFAULT_PROFILE_TEMPLATE);
    }
}
