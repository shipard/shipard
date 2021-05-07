<?php

namespace e10doc\taxes\VatReturn;


/**
 * Class VatReturnFilingEngine
 * @package e10doc\taxes\VatReturn
 */
class VatReturnFilingEngine extends \e10doc\taxes\TaxReportFilingEngine
{
	public function createFilingContent ()
	{
		// -- copy rows
		$q[] = 'INSERT INTO [e10doc_taxes_reportsRowsVatReturn]';
		array_push($q, ' ([report], [filing], ',
				'[dateTax], [dateTaxDuty], [vatId], ',
				'[base], [tax], [total], [quantity], [weight], ',
				'[taxCode], [taxRate], [taxDir], [taxPercents], ',
				'[document], [docNumber], [docId])');
		array_push($q, ' SELECT src.[report], %i, ', $this->filingNdx,
				'src.[dateTax], src.[dateTaxDuty], src.[vatId], ',
				'src.[base], src.[tax], src.[total], src.[quantity], src.[weight], ',
				'src.[taxCode], src.[taxRate], src.[taxDir], src.[taxPercents], ',
				'src.[document], src.[docNumber], src.[docId]');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatReturn] AS src WHERE');
		array_push($q, ' src.[report] = %i', $this->taxReportRecData['ndx'], ' AND src.[filing] = %i', 0);

		$this->db()->query($q);
	}

	public function createFilingFiles ()
	{
		// -- preview
		$reportContent = new \e10doc\taxes\VatReturn\VatReturnReport ($this->app());
		$reportContent->taxReportNdx = $this->taxReportRecData['ndx'];
		$reportContent->filingNdx = $this->filingNdx;
		$reportContent->subReportId = 'preview';
		$reportContent->createPdf();
		$this->addFile($reportContent->fullFileName, 'eu-vat-tr-preview', 'Opis přiznání DPH');
		unset ($reportContent);

		// -- all content
		$reportContent = new \e10doc\taxes\VatReturn\VatReturnReport ($this->app());
		$reportContent->taxReportNdx = $this->taxReportRecData['ndx'];
		$reportContent->filingNdx = $this->filingNdx;
		$reportContent->subReportId = 'ALL';
		$reportContent->createPdf();
		$this->addFile($reportContent->fullFileName, 'eu-vat-tr-all', 'Obsah přiznání DPH');
		$xmlFileName = $reportContent->createContentXml();

		// -- XML
		$this->addFile($xmlFileName, 'eu-vat-tr-xml', 'XML soubor pro elektronické podání');
		unset ($reportContent);
	}

	public function removeFilingContent ()
	{
		$q[] = 'DELETE FROM [e10doc_taxes_reportsRowsVatReturn]';
		array_push($q, ' WHERE [report] = %i', $this->taxReportRecData['ndx'], ' AND [filing] = %i', $this->filingNdx);
		$this->db()->query($q);
	}
}
