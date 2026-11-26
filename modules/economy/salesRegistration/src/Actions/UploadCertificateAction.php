<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Actions;

use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\I18n\DisplayDate;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionDialog;
use Shipard\Core\RecordAction\RecordActionField;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Economy\SalesRegistration\Certificate\CertificateImportException;
use Shipard\Module\Economy\SalesRegistration\Certificate\ImportedCertificate;
use Shipard\Module\Economy\SalesRegistration\Certificate\Pkcs12Importer;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Akce „Nahrát certifikát“ nad registrací (#171 D11): `.p12` + heslo →
 * certifikát a šifrovaný klíč v registraci, identifikátor poplatníka
 * a platnost z certifikátu. Nahrání nového certifikátu přepíše starý
 * (obnova po roce).
 *
 * Heslo se neukládá ani neloguje; nešifrovaný klíč žije jen v paměti mezi
 * převodem a zašifrováním klíčem zdroje dat. Zápis jde přes `TableGateway`,
 * takže platí pravidla {@see RegistrationDocument} (maska, unikátnost).
 */
class UploadCertificateAction implements RecordAction
{
    public const string FIELD_FILE = 'certificate';
    public const string FIELD_PASSWORD = 'password';

    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return in_array((int) ($record['docState'] ?? 0), RegistrationDocument::LIVE_STATES, true)
            && $this->typeOf($record, $context) !== null;
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        $t = Texts::of($context->config);
        $hasCertificate = trim((string) ($record['cert_pem'] ?? '')) !== '';

        return new RecordActionDescriptor(
            $t->t('upload.label', 'Upload certificate'),
            $hasCertificate ? RecordActionDescriptor::SECONDARY : RecordActionDescriptor::PRIMARY,
            dialog: new RecordActionDialog(
                $t->t('upload.title', 'Upload certificate'),
                [
                    RecordActionField::notice('info', $hasCertificate
                        ? $t->t('upload.noticeReplace', 'The new certificate replaces the current one. The private key is stored encrypted, the password is not stored.')
                        : $t->t('upload.notice', 'The private key is stored encrypted, the password is not stored. The taxpayer identifier and validity are read from the certificate.')),
                    RecordActionField::file(
                        self::FIELD_FILE,
                        $t->t('upload.file', 'Certificate file (.p12)'),
                        Pkcs12Importer::MAX_BYTES,
                        accept: '.p12,.pfx',
                        required: true,
                    ),
                    RecordActionField::password(self::FIELD_PASSWORD, $t->t('upload.password', 'Certificate password'), required: true),
                ],
                submitLabel: $t->t('upload.submit', 'Upload'),
            ),
        );
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        $t = Texts::of($context->config);
        $type = $this->typeOf($record, $context);
        $file = $input->file(self::FIELD_FILE);
        $password = (string) $input->string(self::FIELD_PASSWORD);
        if ($type === null) {
            throw RecordActionValidationException::form($t->t('upload.errorType', 'The registration type is not available in this data source.'));
        }
        if ($file === null) {
            throw RecordActionValidationException::field(self::FIELD_FILE, $t->t('upload.errorNoFile', 'Choose the certificate file.'));
        }
        if ($context->dsConfig === null) {
            throw RecordActionValidationException::form($t->t('upload.errorStore', 'The certificate cannot be stored.'));
        }

        try {
            $certificate = $this->importer()->import($file->path, $password, $type);
        } catch (CertificateImportException $e) {
            throw $this->importError($e, $t, $type, $context->language);
        }

        $current = trim((string) ($record['taxpayer_id'] ?? ''));
        if ($current !== '' && $current !== $certificate->taxpayerId) {
            throw RecordActionValidationException::field(self::FIELD_FILE, $t->t(
                'upload.errorOtherTaxpayer',
                'The certificate belongs to another taxpayer ({certificate}) than this registration ({registration}).',
                ['certificate' => $certificate->taxpayerId, 'registration' => $current],
            ));
        }

        $this->store((int) $record['id'], $certificate, $context, $t);

        ErrorLogger::info('sales registration: certificate uploaded', [
            'registration' => (int) $record['id'],
            'type'         => $type->id,
            'taxpayerId'   => $certificate->taxpayerId,
            'validTo'      => $certificate->validTo->format(DATE_ATOM),
            'user'         => $context->userId,
        ]);

        return RecordActionResult::success($t->t(
            'upload.done',
            'Certificate uploaded — {label} {taxpayerId}, valid until {validTo}.',
            [
                'label'      => $type->taxpayerIdLabel,
                'taxpayerId' => $certificate->taxpayerId,
                'validTo'    => self::displayDate($certificate->validTo->format(DATE_ATOM), $context->language),
            ],
        ));
    }

    /** Zapíše certifikát do registrace přes dokument; klíč šifrovaně. */
    private function store(int $registrationId, ImportedCertificate $certificate, RecordActionContext $context, Texts $t): void
    {
        try {
            $encryptedKey = DsSecretCipher::forConfig($context->dsConfig)->encrypt($certificate->keyPem);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'sales registration: private key cannot be encrypted');
            throw RecordActionValidationException::form($t->t('upload.errorStore', 'The certificate cannot be stored.'));
        }

        $result = $this->save($context, [
            'id'               => $registrationId,
            'taxpayer_id'      => $certificate->taxpayerId,
            'cert_pem'         => $certificate->certificatePem,
            'cert_key'         => $encryptedKey,
            'cert_valid_from'  => self::local($certificate->validFrom)->format('Y-m-d H:i:s'),
            'cert_valid_to'    => self::local($certificate->validTo)->format('Y-m-d H:i:s'),
            'cert_serial'      => $certificate->serial !== '' ? $certificate->serial : null,
            'cert_uploaded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'cert_uploaded_by' => $context->userId,
        ]);
        if ($result === null) {
            return;
        }
        throw RecordActionValidationException::form($result);
    }

    /**
     * Uloží řádek registrace přes `TableGateway`; vrací null, nebo hlášku
     * chyby (přepsatelné v testech).
     *
     * @param array<string, mixed> $data
     */
    protected function save(RecordActionContext $context, array $data): ?string
    {
        $gateway = new TableGateway(
            RegistrationDocument::TABLE,
            $context->db->getDibiConnection(),
            new DocumentRegistry([['table' => RegistrationDocument::TABLE, 'class' => RegistrationDocument::class]]),
            null,
            $context->config,
            $context->dsConfig,
            null,
            $context->tableDef?->docStates,
            $context->tableDef,
        );
        $result = $gateway->saveDocument($data);
        if ($result->isSuccess()) {
            return null;
        }
        $messages = array_column($result->getValidation()?->toArray() ?? [], 'message');
        return $messages !== [] ? implode(' ', $messages) : ($result->getErrorMessage() ?? 'The certificate cannot be stored.');
    }

    protected function importer(): Pkcs12Importer
    {
        return new Pkcs12Importer();
    }

    /** @param array<string, mixed> $record */
    private function typeOf(array $record, RecordActionContext $context): ?SalesRegistrationType
    {
        return SalesRegistrationTypes::fromConfig($context->config)->get((string) ($record['type'] ?? ''));
    }

    private function importError(CertificateImportException $e, Texts $t, SalesRegistrationType $type, string $language): RecordActionValidationException
    {
        $date = static fn(string $param): string => self::displayDate((string) ($e->params[$param] ?? ''), $language);

        return match ($e->kind) {
            CertificateImportException::WRONG_PASSWORD => RecordActionValidationException::field(
                self::FIELD_PASSWORD,
                $t->t('upload.errorPassword', 'The password does not open the certificate file.'),
            ),
            CertificateImportException::EXPIRED => RecordActionValidationException::field(self::FIELD_FILE, $t->t(
                'upload.errorExpired',
                'The certificate has expired ({validTo}). Issue a new one and upload it.',
                ['validTo' => $date('validTo')],
            )),
            CertificateImportException::NOT_YET_VALID => RecordActionValidationException::field(self::FIELD_FILE, $t->t(
                'upload.errorNotYetValid',
                'The certificate is not valid yet (from {validFrom}).',
                ['validFrom' => $date('validFrom')],
            )),
            CertificateImportException::NOT_FOR_TYPE => RecordActionValidationException::field(self::FIELD_FILE, $t->t(
                'upload.errorNotForType',
                'This is not a certificate for {type}: its name “{commonName}” is not a valid {label}.',
                ['type' => $type->name, 'commonName' => (string) ($e->params['commonName'] ?? ''), 'label' => $type->taxpayerIdLabel],
            )),
            CertificateImportException::KEY_MISMATCH => RecordActionValidationException::field(
                self::FIELD_FILE,
                $t->t('upload.errorKeyMismatch', 'The private key in the file does not belong to the certificate.'),
            ),
            CertificateImportException::LEGACY_UNAVAILABLE => RecordActionValidationException::form(
                $t->t('upload.errorLegacy', 'The file uses an outdated cipher that this server cannot convert — the openssl program is missing. Contact the administrator.'),
            ),
            default => RecordActionValidationException::field(
                self::FIELD_FILE,
                $t->t('upload.errorNotCertificate', 'The file is not a certificate with a private key (.p12).'),
            ),
        };
    }

    /** Datum certifikátu pro hlášku — v místní zóně a v jazyce požadavku. */
    private static function displayDate(string $atom, string $language): string
    {
        try {
            return (string) DisplayDate::date(self::local(new \DateTimeImmutable($atom)), $language);
        } catch (\Exception) {
            return $atom;
        }
    }

    /** Čas certifikátu (UTC) v místní zóně serveru — tak se ukládá i ukazuje. */
    private static function local(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
