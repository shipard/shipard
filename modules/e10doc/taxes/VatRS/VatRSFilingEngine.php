<?php

namespace e10doc\taxes\VatRS;


/**
 * Class VatRSFilingEngine
 * @package e10doc\taxes\VatRS
 */
class VatRSFilingEngine extends \e10doc\taxes\TaxReportFilingEngine
{
	public function createFilingContent ()
	{
		// -- copy rows
		$q[] = 'INSERT INTO [e10doc_taxes_reportsRowsVatRS]';
		array_push($q, ' ([report], [filing], [dateTax], [vatId], [taxCode], [base], [document], [docNumber])');
		array_push($q, ' SELECT src.[report], %i, ', $this->filingNdx, 'src.[dateTax], src.[vatId], [taxCode], src.[base], src.[document], src.[docNumber]');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatRS] AS src WHERE');
		array_push($q, ' src.[report] = %i', $this->taxReportRecData['ndx'], ' AND src.[filing] = %i', 0);

		$this->db()->query($q);
	}

	public function createFilingFiles ()
	{
		// -- preview
		$reportContent = new \e10doc\taxes\VatRS\VatRSReport ($this->app());
		$reportContent->taxReportNdx = $this->taxReportRecData['ndx'];
		$reportContent->filingNdx = $this->filingNdx;
		$reportContent->subReportId = 'preview';
		$reportContent->createPdf();
		$this->addFile($reportContent->fullFileName, 'eu-vat-rs-preview', 'Opis souhrnného hlášení');
		unset ($reportContent);

		// -- all content
		$reportContent = new \e10doc\taxes\VatRS\VatRSReport ($this->app());
		$reportContent->taxReportNdx = $this->taxReportRecData['ndx'];
		$reportContent->filingNdx = $this->filingNdx;
		$reportContent->subReportId = 'ALL';
		$reportContent->createPdf();
		$this->addFile($reportContent->fullFileName, 'eu-vat-rs-all', 'Obsah souhrnného hlášení');
		$xmlFileName = $reportContent->createContentXml();

		// -- XML
		$this->addFile($xmlFileName, 'cz-vat-cs-xml', 'XML soubor pro elektronické podání');
		unset ($reportContent);
	}

	public function removeFilingContent ()
	{
		$q[] = 'DELETE FROM [e10doc_taxes_reportsRowsVatRS]';
		array_push($q, ' WHERE [report] = %i', $this->taxReportRecData['ndx'], ' AND [filing] = %i', $this->filingNdx);
		$this->db()->query($q);
	}
}
