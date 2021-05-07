<?php

namespace e10doc\taxes\VatCS;


/**
 * Class VatCSFilingEngine
 * @package e10doc\taxes\VatCS
 */
class VatCSFilingEngine extends \e10doc\taxes\TaxReportFilingEngine
{
	public function createFilingContent ()
	{
		// -- copy rows
		$q[] = 'INSERT INTO [e10doc_taxes_reportsRowsVatCS]';
		array_push($q, ' ([report], [filing], [rowKind], ',
				'[reverseChargeCode], [vatModeCode], [dateTax], [dateTaxDuty], [vatId], ',
				'[base1], [tax1], [total1], [base2], [tax2], [total2], [base3], [tax3], [total3], ',
				'[document], [docNumber], [docId])');
		array_push($q, ' SELECT src.[report], %i, ', $this->filingNdx, 'src.[rowKind], ',
				'src.[reverseChargeCode], src.[vatModeCode], src.[dateTax], src.[dateTaxDuty], src.[vatId], ',
				'src.[base1], src.[tax1], src.[total1], src.[base2], src.[tax2], src.[total2], src.[base3], src.[tax3], src.[total3], ',
				'src.[document], src.[docNumber], src.[docId]');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatCS] AS src WHERE');
		array_push($q, ' src.[report] = %i', $this->taxReportRecData['ndx'], ' AND src.[filing] = %i', 0);

		$this->db()->query($q);
	}

	public function createFilingFiles ()
	{
		// -- preview
		$reportContent = new \e10doc\taxes\VatCS\VatCSReportAll ($this->app());
		$reportContent->taxReportNdx = $this->taxReportRecData['ndx'];
		$reportContent->filingNdx = $this->filingNdx;
		$reportContent->subReportId = 'preview';
		$reportContent->createPdf();
		$this->addFile($reportContent->fullFileName, 'cz-vat-cs-preview', 'Opis kontrolního hlášení');
		unset ($reportContent);

		// -- all content
		$reportContent = new \e10doc\taxes\VatCS\VatCSReportAll ($this->app());
		$reportContent->taxReportNdx = $this->taxReportRecData['ndx'];
		$reportContent->filingNdx = $this->filingNdx;
		$reportContent->subReportId = 'ALL';
		$reportContent->createPdf();
		$this->addFile($reportContent->fullFileName, 'cz-vat-cs-all', 'Obsah kontrolního hlášení');
		$xmlFileName = $reportContent->createContentXml();

		// -- XML
		$this->addFile($xmlFileName, 'cz-vat-cs-xml', 'XML soubor pro elektronické podání');
		unset ($reportContent);
	}

	public function removeFilingContent ()
	{
		$q[] = 'DELETE FROM [e10doc_taxes_reportsRowsVatCS]';
		array_push($q, ' WHERE [report] = %i', $this->taxReportRecData['ndx'], ' AND [filing] = %i', $this->filingNdx);
		$this->db()->query($q);
	}
}
