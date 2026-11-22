<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Core\Exchange\Document\DocumentIssuePaths;

/** Nálezy `DocDocument` jako issues výměnného formátu (#166 D21, U6). */
final class DocumentIssuePathsTest extends TestCase
{
    public function testHeadColumnsMapToCanonicalFields(): void
    {
        $received = ['selfParty' => 'customer'];

        $this->assertSame('dates.issueDate', DocumentIssuePaths::path('issue_date', $received, []));
        $this->assertSame('dates.taxPointDate', DocumentIssuePaths::path('vat_duzp', $received, []));
        $this->assertSame('exchangeRate', DocumentIssuePaths::path('exchange_rate', $received, []));
        $this->assertSame('correction.reason', DocumentIssuePaths::path('correction_reason', $received, []));
        $this->assertSame('vatRecap', DocumentIssuePaths::path('recap', $received, []));
        $this->assertSame('vat.registrationCountry', DocumentIssuePaths::path('vat_registration', $received, []));
        $this->assertSame('totals.totalRoundingMode', DocumentIssuePaths::path('total_rounding_mode', $received, []));
        $this->assertSame('totals.totalRoundingAuto', DocumentIssuePaths::path('total_rounding_auto', $received, []));
        $this->assertSame('vat.roundingMode', DocumentIssuePaths::path('vat_rounding_mode', $received, []));
    }

    public function testPartnerIsTheOtherSideOfTheDocument(): void
    {
        $this->assertSame('supplier', DocumentIssuePaths::path('partner', ['selfParty' => 'customer'], []));
        $this->assertSame('customer', DocumentIssuePaths::path('partner', ['selfParty' => 'supplier'], []));
        $this->assertSame('partner', DocumentIssuePaths::path('partner', ['docType' => 'accountingDocument'], []));
    }

    /** Pozice řádku na dokladu → index canonicalu (vynechaný řádek návrhu posouvá pozice). */
    public function testRowPositionIsTranslatedToCanonicalIndex(): void
    {
        $rowIndices = [1, 3];

        $this->assertSame('rows.1.vat.code', DocumentIssuePaths::path('rows.0.vat_code', [], $rowIndices));
        $this->assertSame('rows.3.unitPrice', DocumentIssuePaths::path('rows.1.unit_price', [], $rowIndices));
        $this->assertSame('rows.3.vat.rateDate', DocumentIssuePaths::path('rows.1.vat_rate_date', [], $rowIndices));
        $this->assertSame('rows.5.item', DocumentIssuePaths::path('rows.5.item', [], $rowIndices), 'pozice mimo mapu zůstává');
    }

    public function testUnknownColumnStaysAsIs(): void
    {
        $this->assertSame('number_series', DocumentIssuePaths::path('number_series', [], []));
        $this->assertSame(ValidationError::FIELD_FORM, DocumentIssuePaths::path(ValidationError::FIELD_FORM, [], []));
        $this->assertSame('rows.0.asset', DocumentIssuePaths::path('rows.0.asset', [], [0]));
    }

    public function testIssuesCarrySeverityCodeAndMessage(): void
    {
        $validation = (new ValidationResult())
            ->addError('rows.0.vat_code', 'Řádek nemá kód DPH', 'vat_code_missing')
            ->addWarning('issue_date', 'Pozdní datum', 'late')
            ->addError('doc_type', 'Bez kódu');

        $this->assertSame([
            ['severity' => 'error', 'path' => 'rows.2.vat.code', 'code' => 'vat_code_missing', 'message' => 'Řádek nemá kód DPH'],
            ['severity' => 'error', 'path' => 'doc_type', 'code' => 'document_invalid', 'message' => 'Bez kódu'],
            ['severity' => 'warning', 'path' => 'dates.issueDate', 'code' => 'late', 'message' => 'Pozdní datum'],
        ], DocumentIssuePaths::issues($validation, [], [2]));

        $this->assertSame(
            [['severity' => 'warning', 'path' => 'dates.issueDate', 'code' => 'late', 'message' => 'Pozdní datum']],
            DocumentIssuePaths::warnings($validation, [], [2]),
        );
    }
}
