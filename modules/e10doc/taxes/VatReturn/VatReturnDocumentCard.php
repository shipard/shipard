<?php

namespace e10doc\taxes\VatReturn;


/**
 * Class VatReturnDocumentCard
 */
class VatReturnDocumentCard extends \e10doc\taxes\TaxReportDocumentCard
{
	protected function createAccInfo($filingRecData) : ?array
	{
		if (!$filingRecData)
			return NULL;

		$accInfo = [];

		if ($this->recData['accDocument'])
		{
			$accInfo[] = ['text' => 'Daňové přiznání je zaúčtováno.', 'class' => 'padd5'];

			$accInfo[] = [
				'text' => 'Přeúčtovat', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/actionRegenerate', 'btnClass' => 'btn-success', 'class' => 'pull-right',
				'data-table' => 'e10doc.core.heads', 'data-class' => 'e10doc.taxes.VatReturn.VatReturnAccWizard', 
				'data-addparams' => 'taxReportNdx=' . $this->recData['ndx'] . '&filingNdx=' . $filingRecData['ndx']
			];

			$accInfo[] = [
				'type' => 'action', 'action' => 'editform', 'text' => 'Otevřít doklad', 
				'data-table' => 'e10doc.core.heads', 'data-pk' => $this->recData['accDocument'],
				'class' => 'pull-right'
			];
		}
		else
		{
			$accInfo[] = ['text' => 'Daňové přiznání zatím není zaúčtováno.', 'class' => 'padd5'];

			$accInfo[] = [
				'text' => 'Zaúčtovat', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/actionRegenerate', 'btnClass' => 'btn-success', 'class' => 'pull-right',
				'data-table' => 'e10doc.core.heads', 'data-class' => 'e10doc.taxes.VatReturn.VatReturnAccWizard', 
				'data-addparams' => 'taxReportNdx=' . $this->recData['ndx'] . '&filingNdx=' . $filingRecData['ndx']
			];
		}

		$content = ['type' => 'line', 'pane' => 'e10-pane e10-pane-table', 'paneTitle' => ['text' => 'Zaúčtování', 'class' => 'h1 padd5 block subtitle'], 'line' => $accInfo];
		return $content;
	}

	public function createContentErrors ()
	{
			$reportContent = new \e10doc\taxes\VatCS\VatCSReportAll ($this->app());
			$reportContent->taxReportNdx = $this->recData['ndx'];
			$reportContent->filingNdx = 0;
			$reportContent->subReportId = 'preview';

			$reportContent->init();
			$reportContent->createContent();

			if ($reportContent->cntErrors)
			{
					$msg = [['text' => 'Přiznání DPH patrně obsahuje chyby', 'class' => 'h1', 'icon' => 'system/iconWarning']];

					if (count($reportContent->invalidDocs))
							$msg[] = ['text' => 'Nesrovnalosti v evidenci Dokladů ('.count($reportContent->invalidDocs).')', 'class' => 'block', 'icon' => 'icon-chevron-right'];
					//if (count($reportContent->badVatIds))
					//    $msg[] = ['text' => 'U některých dokladů nesouhlasí DIČ dokladu s evidencí Osob ('.count($reportContent->badVatIds).')', 'class' => 'block', 'icon' => 'icon-chevron-right'];
					//if (count($reportContent->invalidPersons))
					//    $msg[] = ['text' => 'Nesrovnalosti v evidenci Osob ('.count($reportContent->invalidPersons).')', 'class' => 'block', 'icon' => 'icon-chevron-right'];

					$this->addContent('body', ['pane' => 'e10-pane e10-pane-table e10-warning1', 'type' => 'line', 'line' => $msg]);
			}
	}

	public function createContentBody ()
	{
		$this->createContentErrors();
		$this->createContentFilings();
	}

	public function createContent ()
	{
		$this->init();
		$this->createContentBody ();
	}
}
