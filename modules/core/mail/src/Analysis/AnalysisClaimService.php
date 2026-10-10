<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;
use Shipard\Module\Core\Ai\AIBackendDocument;

/**
 * Rezervace zprávy k analýze (`core_mail_analysis_claims`) — tělo
 * dnešního `POST /claim` jako služba (tasks/mail-analysis-inprocess.md
 * D12/D13), sdílená pull endpointem i in-process runnerem.
 *
 * `claim()`: v transakci `SELECT … FOR UPDATE` na řádku zprávy (serializuje
 * souběžné claimy — tabulka claimů nemá partial unique), ověří stav 10
 * a gate předzpracování, odmítne aktivní claim, vybere profil a backend,
 * dešifruje klíč backendu, vloží claim a přepne `analysis_state` 10 → 20.
 * `docState` (workflow) se nemění. Spec tasks/mail-phase3a.md §3.2.
 */
class AnalysisClaimService
{
    public const DEFAULT_LEASE_SECONDS = 300;
    public const MIN_LEASE_SECONDS = 60;
    public const MAX_LEASE_SECONDS = 900;

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const BACKENDS_TABLE = 'core_ai_backends';
    private const PROFILES_TABLE = 'core_mail_ai_profiles';
    private const CLAIMS_TABLE = 'core_mail_analysis_claims';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DataSourceConfig $config,
    ) {}

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
            $backend = $this->resolveBackend((int) $profile['backend']);
            if ($backend === null) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::NO_BACKEND,
                    'Profile references a backend that is missing or inactive',
                    409,
                );
            }

            // Decrypt api_key přes AIBackendDocument — single source of truth
            try {
                $cipher = DsSecretCipher::forConfig($this->config);
            } catch (SecretsKeyMissingException | SecretsKeyInsecureException $e) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::SECRETS_UNAVAILABLE,
                    'Server cannot decrypt backend API key: ' . $e->getMessage(),
                    500,
                    $e,
                );
            }
            $backendDoc = new AIBackendDocument();
            $backendDoc->setSecretCipher($cipher);
            try {
                $apiKey = $backendDoc->decryptApiKey($backend);
            } catch (InvalidCiphertextException $e) {
                throw new AnalysisClaimException(
                    AnalysisClaimException::BACKEND_KEY_CORRUPTED,
                    'Stored API key cannot be decrypted (corrupted or wrong secrets.key)',
                    500,
                    $e,
                );
            }
            if ($apiKey === null || $apiKey === '') {
                throw new AnalysisClaimException(
                    AnalysisClaimException::BACKEND_KEY_MISSING,
                    "Backend '{$backend['backend_id']}' has no API key set. Run ai-analyzer-set-key.",
                    409,
                );
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

        return new AnalysisClaim($claimId, $claimToken, $expiresAt, $profile, $backend, $apiKey);
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

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBackend(int $backendNdx): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM %n WHERE id = %i AND is_active = %i LIMIT 1',
            self::BACKENDS_TABLE,
            $backendNdx,
            1,
        );
    }

    private static function generateClaimToken(): string
    {
        return 'ct_' . bin2hex(random_bytes(30));
    }
}
