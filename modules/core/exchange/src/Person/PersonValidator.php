<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Person;

/**
 * Semantic validator for a canonical person — runs **after** schema
 * validation and **before** resolve. Five kinds of checks:
 *
 *   - Polymorphism per `personType` (the JSON schema intentionally
 *     accepts the union of company/person fields; this is where
 *     the per-type rules are enforced).
 *   - Address sub-record sanity — placeRegType/placeRegId is required
 *     for Provozovna/Zařízení (`addressType in [3,4]`) and forbidden
 *     for Sídlo/Doručovací (`addressType in [1,2]`).
 *   - Bank account sub-record sanity — at least one of `iban` /
 *     `accountNumber` must be present.
 *   - `documents.language` must be one of the document languages
 *     (cfgItem `world.base.documentLanguages`, #94).
 *   - `contacts[].sendPurposes` must be known send purposes
 *     (cfgItem `base.persons.sendPurposes`, #90 D34).
 *
 * Returns the same `{severity, path, code, message}` shape as
 * {@see \Shipard\Module\Core\Exchange\Schema\SchemaValidator}. Issues
 * with `severity = error` block /apply; warnings only surface in
 * `_resolve.issues` and never block.
 */
final class PersonValidator
{
    /**
     * @param list<string>|null $documentLanguages Jazyky dokumentů (klíče
     *        cfgItemu `world.base.documentLanguages`). Null = volající
     *        konfiguraci nemá (preflight datové sady) a jazyk se nekontroluje.
     * @param list<string>|null $sendPurposes Účely odesílání (klíče cfgItemu
     *        `base.persons.sendPurposes`). Null = účely se nekontrolují.
     */
    public function __construct(
        private readonly ?array $documentLanguages = null,
        private readonly ?array $sendPurposes = null,
    ) {}

    /**
     * @param array<string, mixed> $canonical
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    public function validate(array $canonical): array
    {
        $issues = [];

        $this->checkPerPersonType($canonical, $issues);
        $this->checkAddresses($canonical, $issues);
        $this->checkBankAccounts($canonical, $issues);
        $this->checkOwnCompanyTransition($canonical, $issues);
        $this->checkDocuments($canonical, $issues);
        $this->checkContacts($canonical, $issues);

        return $issues;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkPerPersonType(array $canonical, array &$issues): void
    {
        $personType = (string) ($canonical['personType'] ?? '');
        $name = is_array($canonical['name'] ?? null) ? $canonical['name'] : [];

        if ($personType === 'company') {
            if (!$this->isNonEmptyString($name['fullName'] ?? null)) {
                $issues[] = $this->error(
                    'name.fullName',
                    'required',
                    'U firmy je povinné obchodní jméno.',
                );
            }
            if (!$this->isNonEmptyString($canonical['companyId'] ?? null)) {
                // Foreign companies without IČO are legal in DB, so this is
                // a warning, not an error — applier saves them anyway.
                $issues[] = $this->warning(
                    'companyId',
                    'company_id_missing',
                    'IČO není vyplněno (povoleno pro zahraniční firmy).',
                );
            }
            if (is_array($canonical['personal'] ?? null) && $canonical['personal'] !== []) {
                $issues[] = $this->warning(
                    'personal',
                    'wrong_for_type',
                    'Sekce personal je relevantní jen pro fyzické osoby; bude ignorována.',
                );
            }
            return;
        }

        if ($personType === 'person') {
            if (!$this->isNonEmptyString($name['firstName'] ?? null)) {
                $issues[] = $this->error(
                    'name.firstName',
                    'required',
                    'U fyzické osoby je povinné křestní jméno.',
                );
            }
            if (!$this->isNonEmptyString($name['lastName'] ?? null)) {
                $issues[] = $this->error(
                    'name.lastName',
                    'required',
                    'U fyzické osoby je povinné příjmení.',
                );
            }
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkAddresses(array $canonical, array &$issues): void
    {
        $addresses = $canonical['addresses'] ?? null;
        if (!is_array($addresses)) {
            return;
        }

        foreach ($addresses as $idx => $addr) {
            if (!is_array($addr)) {
                continue;
            }
            $type = $addr['addressType'] ?? null;
            $placeRegType = $addr['placeRegType'] ?? null;
            $placeRegId = $addr['placeRegId'] ?? null;
            $hasRegType = $this->isNonEmptyString($placeRegType);
            $hasRegId = $this->isNonEmptyString($placeRegId);

            if ($type === 3 || $type === 4) {
                $expected = $type === 3 ? 'ICP' : 'ICZ';
                if (!$hasRegType) {
                    $issues[] = $this->error(
                        "addresses.{$idx}.placeRegType",
                        'place_reg_required',
                        "U adresy typu " . ($type === 3 ? 'Provozovna' : 'Zařízení')
                            . " je povinný placeRegType ('{$expected}').",
                    );
                } elseif ($placeRegType !== $expected) {
                    $issues[] = $this->error(
                        "addresses.{$idx}.placeRegType",
                        'place_reg_mismatch',
                        "Pro addressType={$type} je očekáváný placeRegType '{$expected}', přišlo '{$placeRegType}'.",
                    );
                }
                if (!$hasRegId) {
                    $issues[] = $this->error(
                        "addresses.{$idx}.placeRegId",
                        'place_reg_required',
                        'U adresy provozovny/zařízení je povinný placeRegId (IČP/IČZ).',
                    );
                }
            } else {
                // addressType in [1,2] (Sídlo / Doručovací) — placeReg* must be null.
                if ($hasRegType || $hasRegId) {
                    $issues[] = $this->warning(
                        "addresses.{$idx}.placeRegType",
                        'place_reg_unexpected',
                        'placeRegType/Id jsou relevantní jen pro Provozovnu (3) a Zařízení (4); budou ignorovány.',
                    );
                }
            }
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkBankAccounts(array $canonical, array &$issues): void
    {
        $accounts = $canonical['bankAccounts'] ?? null;
        if (!is_array($accounts)) {
            return;
        }

        foreach ($accounts as $idx => $account) {
            if (!is_array($account)) {
                continue;
            }
            $iban = $this->isNonEmptyString($account['iban'] ?? null);
            $accountNumber = $this->isNonEmptyString($account['accountNumber'] ?? null);
            if (!$iban && !$accountNumber) {
                $issues[] = $this->error(
                    "bankAccounts.{$idx}",
                    'bank_account_id_missing',
                    'U bankovního účtu musí být vyplněn alespoň jeden z `iban` / `accountNumber`.',
                );
            }
        }
    }

    /**
     * When apply targets docState=40 (V pořádku) and the canonical declares
     * the person is our own company, IČO is hard-required — the doc
     * subsystem refuses to operate without it.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkOwnCompanyTransition(array $canonical, array &$issues): void
    {
        $targetState = $canonical['applyOptions']['targetDocState'] ?? null;
        if ((int) $targetState !== 40) {
            return;
        }
        $isOwn = $canonical['status']['isOwn'] ?? false;
        if ($isOwn !== true) {
            return;
        }
        if (!$this->isNonEmptyString($canonical['companyId'] ?? null)) {
            $issues[] = $this->error(
                'companyId',
                'own_company_id_required',
                'Vlastní firma musí mít IČO; uložení do stavu „V pořádku" by jinak selhalo.',
            );
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkDocuments(array $canonical, array &$issues): void
    {
        $language = $canonical['documents']['language'] ?? null;
        if ($this->documentLanguages === null || !is_string($language) || $language === '') {
            return;
        }
        if (!in_array($language, $this->documentLanguages, true)) {
            $issues[] = $this->error(
                'documents.language',
                'invalid_document_language',
                "Jazyk dokumentů '{$language}' není mezi podporovanými ("
                    . implode(', ', $this->documentLanguages) . ').',
            );
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkContacts(array $canonical, array &$issues): void
    {
        if ($this->sendPurposes === null || !is_array($canonical['contacts'] ?? null)) {
            return;
        }
        foreach ($canonical['contacts'] as $i => $contact) {
            $purposes = is_array($contact) ? ($contact['sendPurposes'] ?? null) : null;
            if (!is_array($purposes)) {
                continue;
            }
            foreach ($purposes as $purpose) {
                if (!is_string($purpose) || !in_array($purpose, $this->sendPurposes, true)) {
                    $issues[] = $this->error(
                        "contacts.{$i}.sendPurposes",
                        'invalid_send_purpose',
                        'Účel odesílání ' . json_encode($purpose, JSON_UNESCAPED_UNICODE)
                            . ' není mezi podporovanými (' . implode(', ', $this->sendPurposes) . ').',
                    );
                }
            }
        }
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private function error(string $path, string $code, string $message): array
    {
        return ['severity' => 'error', 'path' => $path, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private function warning(string $path, string $code, string $message): array
    {
        return ['severity' => 'warning', 'path' => $path, 'code' => $code, 'message' => $message];
    }
}
