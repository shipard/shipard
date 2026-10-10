<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní provisioning AI analýzy pošty: výchozí AI připojení
 * (`core_ai_backends`, bez modelu — model určuje AI funkce
 * `mail.extraction`, tasks/ai-functions.md D39), výchozí profil (bez
 * připojení = podle funkce, D4), převod dřívějšího ladění backendu do
 * profilů (D41, pravidlo 1), přepis vyřazených modelů u profilů
 * a jednorázové datové opravy. Volá ho `ds-upgrade` bezpodmínečně (i pod
 * `skipProvisioning`, tasks/ai-provisioning-unconditional.md) po
 * AiFunctionProvisioneru a `ai-profile-reload` (sync profilu ze šablony).
 *
 * Převod profilů (D41, jednorázově; připojení s vyplněným `model` je
 * značka „nepřevedeno“, kterou po obou provisionerech vynuluje
 * AiFunctionProvisioner::finishLegacyConversion, D46): profil navázaný
 * na takové připojení dostane jeho model, liší-li se od modelu úrovně
 * funkce, a `thinking` / `effort` jiné než `auto`; vazba na výchozí
 * připojení se vynuluje (= podle funkce). `max_tokens` připojení se
 * nepřevádí — jen poznámka, že strop analýzy je teď podle funkce.
 *
 * Náhrady vyřazených modelů určuje katalog modelů (`ModelCatalog`,
 * `lifecycle.replacedBy`); bez katalogu se nic nepřepisuje.
 *
 * Systémový uživatel `_ai_analyzer` (externí analyzer, zrušen #85 D20–D21,
 * tasks/ai-analyzer-removal.md) se už nezakládá; existující účet se při
 * `ds-upgrade` deaktivuje a jeho API klíče zneplatní. Nemaže se — běhy
 * analýz z té doby na něj odkazují v `created_by`.
 *
 * Spec: tasks/mail-phase3a.md §6.1, §6.2, §11; tasks/ai-analyzer-removal.md.
 */
class MailAiProvisioner
{
    /** Login zrušeného systémového uživatele externího analyzeru. */
    public const LEGACY_ANALYZER_LOGIN = '_ai_analyzer';
    public const DEFAULT_BACKEND_ID = 'default';

    /**
     * Úroveň funkce `mail.extraction` (deklarace v module.jsonc): model
     * připojení shodný s modelem této úrovně se do profilu nepřevádí.
     */
    public const EXTRACTION_TIER = ModelSpec::TIER_BALANCED;
    public const EXTRACTION_MAX_TOKENS = 32768;
    public const DEFAULT_PROFILE_ID = 'czech_general';
    public const DEFAULT_PROFILE_TEMPLATE = __DIR__ . '/../profiles/czech_general.jsonc';

    /** Původní id default profilu — jen pro jednorázový rename krok. */
    private const LEGACY_PROFILE_ID = 'czech_invoices';
    private const DEFAULT_PROFILE_NAME = 'Obecná analýza pošty (česky)';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ModelCatalog $catalog,
    ) {}

    /**
     * @return array{
     *     backend: array{id: int, created: bool, skipped_reason?: string},
     *     converted: list<array{profile_id: string, changes: list<string>, notes: list<string>}>,
     *     retired: list<array{id: int, profile_id: string, from: string, to: string}>,
     *     profile_rename: array{renamed: int},
     *     profile: array{id: int, profile_id: string, created: bool, skipped_reason?: string},
     *     queue_fix: array{fixed: int},
     *     legacy_analyzer: array{users: int, keys: int}
     * }
     */
    public function provision(): array
    {
        $backend = $this->ensureDefaultBackend();
        $renamed = $this->renameLegacyProfile();
        $profile = $this->ensureDefaultProfile();
        // Převod před přepisem vyřazených modelů: model z připojení skončí
        // v profilu a případná náhrada se udělá tam.
        $converted = $this->convertLegacyProfileTuning();
        $retired = $this->retireProfileModels();
        $queueFix = $this->fixQueuedArchivedMessages();
        $legacy = $this->deactivateLegacyAnalyzer();

        return [
            'backend' => $backend,
            'converted' => $converted,
            'retired' => $retired,
            'profile_rename' => ['renamed' => $renamed],
            'profile' => $profile,
            'queue_fix' => ['fixed' => $queueFix],
            'legacy_analyzer' => $legacy,
        ];
    }

    /**
     * Pravidlo 1 převodu (D41): profily navázané na dosud nepřevedené
     * připojení (`core_ai_backends.model IS NOT NULL`). Zapisuje jen do
     * polí profilu, která jsou ještě prázdné / `default` — druhý běh nic
     * nemění. Sloupce ladění připojení se čtou, jen pokud v databázi
     * existují (nový zdroj dat je nemá).
     *
     * @return list<array{profile_id: string, changes: list<string>, notes: list<string>}>
     */
    public function convertLegacyProfileTuning(): array
    {
        $columns = $this->db->getTableColumns('core_ai_backends');
        $hasTuning = isset($columns['thinking']) && isset($columns['effort']);
        $hasMaxTokens = isset($columns['max_tokens']);

        $select = 'SELECT p.id, p.profile_id, p.backend, p.model, p.thinking, p.effort, p.max_tokens,'
            . ' b.backend_id, b.is_default, b.model AS backend_model'
            . ($hasTuning ? ', b.thinking AS backend_thinking, b.effort AS backend_effort' : '')
            . ($hasMaxTokens ? ', b.max_tokens AS backend_max_tokens' : '')
            . ' FROM core_mail_ai_profiles p JOIN core_ai_backends b ON b.id = p.backend'
            . ' WHERE b.model IS NOT NULL ORDER BY p.id';
        $tierModel = $this->catalog->defaultModel(self::EXTRACTION_TIER);

        $converted = [];
        foreach ($this->db->fetchAll($select) as $row) {
            $code = (string) ($row['backend_id'] ?? '');
            $update = [];
            $changes = [];
            $notes = [];

            $model = trim((string) ($row['backend_model'] ?? ''));
            if ($model !== '' && $model !== $tierModel && self::blank($row['model'] ?? null)) {
                $update['model'] = $model;
                $changes[] = "model {$model} (from connection '{$code}')";
            }
            if ($hasTuning) {
                foreach (['thinking', 'effort'] as $column) {
                    $value = trim((string) ($row['backend_' . $column] ?? ''));
                    if ($value !== '' && $value !== 'auto' && $value !== 'default'
                        && trim((string) ($row[$column] ?? 'default')) === 'default'
                    ) {
                        $update[$column] = $value;
                        $changes[] = "{$column} {$value} (from connection '{$code}')";
                    }
                }
            }
            if (!empty($row['is_default'])) {
                $update['backend'] = null;
                $changes[] = "connection '{$code}' is the default → by function";
            }
            if ($hasMaxTokens && (int) ($row['backend_max_tokens'] ?? 0) > 0 && (int) ($row['max_tokens'] ?? 0) === 0) {
                $notes[] = sprintf(
                    "extraction max_tokens %d (connection '%s') → %d (function default; set max_tokens on the profile to keep the old ceiling)",
                    (int) $row['backend_max_tokens'],
                    $code,
                    self::EXTRACTION_MAX_TOKENS,
                );
            }

            if ($update !== []) {
                $this->db->updateWhere(
                    'core_mail_ai_profiles',
                    $update + ['modified' => date('Y-m-d H:i:s')],
                    'id = %i',
                    (int) $row['id'],
                );
            }
            if ($changes !== [] || $notes !== []) {
                $converted[] = ['profile_id' => (string) ($row['profile_id'] ?? ''), 'changes' => $changes, 'notes' => $notes];
            }
        }
        return $converted;
    }

    /**
     * Jednorázový idempotentní přepis vyřazených modelů u profilů pošty
     * (F0-D8, D33) podle `lifecycle.replacedBy` katalogu. Shoda na přesné
     * ID nebo na ID s datovou příponou; ID s prefixem platformy
     * (`anthropic.…` na Bedrocku) se nemění — partnerské platformy mají
     * vlastní termíny. Po přepisu navždy matchne 0 řádků; bez katalogu nic.
     *
     * @return list<array{id: int, profile_id: string, from: string, to: string}> Změněné profily.
     */
    public function retireProfileModels(): array
    {
        if ($this->catalog->isEmpty()) {
            return [];
        }
        $rows = $this->db->fetchAll('SELECT id, profile_id, model FROM core_mail_ai_profiles WHERE model IS NOT NULL');
        $changed = [];
        foreach ($rows as $row) {
            $model = (string) ($row['model'] ?? '');
            $replacement = $this->catalog->retiredReplacement($model);
            if ($replacement === null) {
                continue;
            }
            $this->db->updateWhere(
                'core_mail_ai_profiles',
                ['model' => $replacement, 'modified' => date('Y-m-d H:i:s')],
                'id = %i',
                (int) $row['id'],
            );
            $changed[] = [
                'id' => (int) $row['id'],
                'profile_id' => (string) ($row['profile_id'] ?? ''),
                'from' => $model,
                'to' => $replacement,
            ];
        }
        return $changed;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
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
     * s analysis_state=10 (Ve frontě) nemají ve frontě co dělat — fronta
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
     * Jednorázová idempotentní deaktivace systémového uživatele
     * `_ai_analyzer` a zneplatnění jeho API klíčů (tasks/ai-analyzer-removal.md
     * D21) — externí analyzer už nemá kam volat. UPDATE jen nad aktivními
     * řádky, po prvním běhu navždy 0 / 0. Uživatel se **nemaže**:
     * `core_mail_message_analyses.created_by` starších běhů na něj odkazuje.
     *
     * @return array{users: int, keys: int} Počty právě deaktivovaných řádků.
     */
    public function deactivateLegacyAnalyzer(): array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'UPDATE core_system_api_keys SET is_active = %i, modified = %s
              WHERE is_active = %i
                AND user_id IN (SELECT id FROM core_system_users WHERE login = %s)',
            0,
            $now,
            1,
            self::LEGACY_ANALYZER_LOGIN,
        );
        $keys = $this->db->getAffectedRows();

        $this->db->execute(
            'UPDATE core_system_users SET is_active = %i WHERE login = %s AND is_active = %i',
            0,
            self::LEGACY_ANALYZER_LOGIN,
            1,
        );
        $users = $this->db->getAffectedRows();

        return ['users' => $users, 'keys' => $keys];
    }

    /**
     * Vytvoří (pokud chybí) výchozí připojení `default` (platforma
     * Anthropic). Model nenese — určuje ho AI funkce (D39). `api_key`
     * zůstává NULL, `is_active` = false — admin doplní klíč přes
     * `ai-backend-set-key`.
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
            'api_key' => null,
            'base_url' => null,
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
     * `modules/core/mail/profiles/czech_general.jsonc` — bez připojení
     * (NULL = podle funkce `mail.extraction`, D4). Když profil tohoto
     * kódu existuje, beze změny ho přeskočí (admin může mít upravený
     * prompt). `profile_id` ve výsledku je kód provisionovaného default
     * profilu (pro výpisy v ds-upgrade), ne kód případného cizího defaultu.
     *
     * @return array{id: int, profile_id: string, created: bool, skipped_reason?: string}
     */
    public function ensureDefaultProfile(): array
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
            'backend' => null,
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
