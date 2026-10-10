<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\AiCallOverride;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Rezervace zprávy k analýze (`core_mail_analysis_claims`) — tělo
 * dnešního `POST /claim` jako služba (tasks/mail-analysis-inprocess.md
 * D12/D13), sdílená pull endpointem i in-process runnerem.
 *
 * `claim()`: v transakci `SELECT … FOR UPDATE` na řádku zprávy (serializuje
 * souběžné claimy — tabulka claimů nemá partial unique), ověří stav 10
 * a gate předzpracování, odmítne aktivní claim, vybere profil, nechá
 * {@see AiFunctionResolver} složit volání funkce `mail.extraction`
 * s přepsáním z profilu (připojení, model, ladění, strop tokenů —
 * tasks/ai-functions.md D4, D40; klíč dešifrovaný), vloží claim a přepne
 * `analysis_state` 10 → 20. `docState` (workflow) se nemění.
 * Spec tasks/mail-phase3a.md §3.2.
 */
class AnalysisClaimService
{
    public const DEFAULT_LEASE_SECONDS = 300;
    public const MIN_LEASE_SECONDS = 60;
    public const MAX_LEASE_SECONDS = 900;

    public const string AI_FUNCTION = 'mail.extraction';

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';

    private ?AiFunctionResolver $functions;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DataSourceConfig $config,
        private readonly ?ConfigRuntime $configRuntime = null,
        ?AiFunctionResolver $functions = null,
    ) {
        $this->functions = $functions;
    }

    private function functions(): AiFunctionResolver
    {
        return $this->functions ??= new AiFunctionResolver($this->db, $this->configRuntime, $this->config);
    }

    public static function clampLeaseSeconds(?int $requested): int
    {
        $seconds = $requested ?? self::DEFAULT_LEASE_SECONDS;
        return max(self::MIN_LEASE_SECONDS, min(self::MAX_LEASE_SECONDS, $seconds));
    }

    /**
     * @param int|null $profileId Vyžádaný profil; null = `profile_override`
     *        zprávy, jinak výchozí aktivní profil DS.
     * @throws AnalysisClaimException
     */
    public function claim(int $messageId, string $analyzerId, int $leaseSeconds, ?int $profileId = null): AnalysisClaim
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        try {
            // FOR UPDATE serializuje souběžné claim() přes řádek zprávy —
            // bez tohoto by dva analyzéry mohli oba projít SELECT a oba
            // INSERT do claims (tabulka nemá partial unique). Spec §3.2
            // "Atomicky" + §2.4 popis invariantu max-jedna-aktivní-claim.
            $msgRow = $dibi->fetch(
                'SELECT id, analysis_state, preprocess_state, profile_override FROM %n WHERE id = %i FOR UPDATE',
                self::MESSAGES_TABLE,
                $messageId,
            );
            if ($msgRow === null) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::NOT_FOUND,
                    "Message {$messageId} not found",
                    404,
                );
            }
            if ((int) $msgRow['analysis_state'] !== AnalysisStates::QUEUED) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::INVALID_STATE,
                    'Message is not queued for analysis (analysis_state != 10)',
                    409,
                );
            }
            // Gate předzpracování i na claimu — fronta zprávu nevydá, ale
            // runner může claimovat ze staršího snapshotu fronty.
            if (in_array((int) ($msgRow['preprocess_state'] ?? 0), AnalysisStates::PREPROCESS_BLOCKING_STATES, true)) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::INVALID_STATE,
                    'Message is being preprocessed (preprocess_state in 10/20)',
                    409,
                );
            }

            $now = date('Y-m-d H:i:s');
            $activeClaim = $dibi->fetch(
                'SELECT id FROM %n WHERE message = %i AND released = %i AND expires_at > %s LIMIT 1',
                self::CLAIMS_TABLE,
                $messageId,
                0,
                $now,
            );
            if ($activeClaim !== null) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::ALREADY_CLAIMED,
                    'Message already has an active claim',
                    409,
                );
            }

            // Vyber profil + backend
            $profileNdx = $profileId
                ?? (isset($msgRow['profile_override']) ? (int) $msgRow['profile_override'] : null);
            $profile = $this->resolveProfile($profileNdx);
            if ($profile === null) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::NO_PROFILE,
                    'No active profile available (default missing or requested profile invalid)',
                    409,
                );
            }
            // Připojení, model, ladění a klíč: funkce mail.extraction
            // s přepsáním z profilu (profil → řádek funkce → deklarace).
            try {
                $call = $this->functions()->resolve(self::AI_FUNCTION, AiCallOverride::fromRow($profile));
            } catch (AiFunctionException $e) {
                throw self::claimExceptionFrom($e);
            }

            // Vytvoř claim
            $claimToken = self::generateClaimToken();
            $expiresAt = date('Y-m-d H:i:s', time() + $leaseSeconds);
            $dibi->insert(self::CLAIMS_TABLE, [
                'message' => $messageId,
                'analyzer_id' => $analyzerId,
                'claim_token' => $claimToken,
                'claimed_at' => $now,
                'expires_at' => $expiresAt,
                'released' => 0,
            ])->execute();
            $claimId = (int) $dibi->getInsertId();

            // Přepni analýzu na "Analyzuje se" — docState zůstává
            $dibi->update(self::MESSAGES_TABLE, [
                'analysis_state' => AnalysisStates::ANALYZING,
                'modified' => $now,
            ])->where('id = %i', $messageId)->execute();

            $dibi->commit();
        } catch (AnalysisClaimException $e) {
            $dibi->rollback();
            throw $e;
        } catch (\Throwable $e) {
            $dibi->rollback();
            // Plaintext API key žije v této metodě v paměti — zpráva výjimky
            // nesmí prosáknout ven (spec §10 dec.2). Detail loguj server-side.
            ErrorLogger::warn('AnalysisClaimService::claim failed', [
                'error' => $e->getMessage(),
            ]);
            throw new AnalysisClaimException(
                AnalysisClaimException::INTERNAL_ERROR,
                'Internal server error during claim',
                500,
                $e,
            );
        }

        return new AnalysisClaim($claimId, $claimToken, $expiresAt, $profile, $call);
    }

    /**
     * Kódy resolveru → dnešní kódy claimu: bez připojení `NO_BACKEND`,
     * klíč `BACKEND_KEY_MISSING` / `SECRETS_UNAVAILABLE` /
     * `BACKEND_KEY_CORRUPTED`, špatný model `BACKEND_CONFIG_INVALID` (D48).
     * Všechno jsou chyby konfigurace — zpráva zůstává ve frontě.
     */
    public static function claimExceptionFrom(AiFunctionException $e): AnalysisClaimException
    {
        [$code, $status] = match ($e->errorCode) {
            AiFunctionException::NO_CONNECTION => [AnalysisClaimException::NO_BACKEND, 409],
            AiFunctionException::KEY_MISSING => [AnalysisClaimException::BACKEND_KEY_MISSING, 409],
            AiFunctionException::SECRETS_UNAVAILABLE => [AnalysisClaimException::SECRETS_UNAVAILABLE, 500],
            AiFunctionException::KEY_CORRUPTED => [AnalysisClaimException::BACKEND_KEY_CORRUPTED, 500],
            default => [AnalysisClaimException::BACKEND_CONFIG_INVALID, 409],
        };
        return new AnalysisClaimException($code, $e->getMessage(), $status, $e);
    }

    /**
     * Prodlouží lease neuvolněného claimu; `false` = claim už neplatí
     * (uvolněný výsledkem, selháním nebo reaperem) — volající nesmí zapsat
     * výsledek. Platnost se čte dotazem, ne z počtu ovlivněných řádků:
     * MariaDB hlásí 0 i u živého claimu, když nová `expires_at` vyjde
     * stejně jako stará (prodloužení ve stejné sekundě po claimu).
     */
    public function extend(int $claimId, int $leaseSeconds): bool
    {
        $this->db->execute(
            'UPDATE %n SET expires_at = %s WHERE id = %i AND released = %i',
            self::CLAIMS_TABLE,
            date('Y-m-d H:i:s', time() + $leaseSeconds),
            $claimId,
            0,
        );
        return $this->isActive($claimId);
    }

    /** Claim je stále živý: neuvolněný a lease nevypršel. */
    public function isActive(int $claimId, ?string $now = null): bool
    {
        $now ??= date('Y-m-d H:i:s');
        $row = $this->db->fetchRow(
            'SELECT id FROM %n WHERE id = %i AND released = %i AND expires_at > %s LIMIT 1',
            self::CLAIMS_TABLE,
            $claimId,
            0,
            $now,
        );
        return $row !== null;
    }

    /**
     * Aktivní claim zprávy (neuvolněný, lease v budoucnu), nebo null.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(int $messageId, ?string $now = null): ?array
    {
        $now ??= date('Y-m-d H:i:s');
        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE message = %i AND released = %i AND expires_at > %s LIMIT 1',
            self::CLAIMS_TABLE,
            $messageId,
            0,
            $now,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProfile(?int $requestedNdx): ?array
    {
        if ($requestedNdx !== null && $requestedNdx > 0) {
            return $this->db->fetchRow(
                'SELECT * FROM %n WHERE id = %i AND is_active = %i LIMIT 1',
                self::PROFILES_TABLE,
                $requestedNdx,
                1,
            );
        }

        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE is_default = %i AND is_active = %i LIMIT 1',
            self::PROFILES_TABLE,
            1,
            1,
        );
    }

    private static function generateClaimToken(): string
    {
        return 'ct_' . bin2hex(random_bytes(30));
    }
}
