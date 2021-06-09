<?php

namespace mac\lan;

use \e10\FormReport, \e10\utils;


/**
 * Class ReportCardSWLicense
 * @package mac\lan
 */
class ReportCardSWLicense extends FormReport
{
	function init ()
	{
		$this->reportId = 'mac.lan.cardSWLicense';
		$this->reportTemplate = 'mac.lan.cardSWLicense';
	}

	public function loadData ()
	{
		$this->setInfo('icon', 'icon-certificate');
		$this->setInfo('title', $this->recData['id']);
		$this->setInfo('param', 'SW', $this->recData ['fullName']);

		$this->app()->printMode = TRUE;
		$card = new \mac\lan\DocumentCardSwLicense($this->app);
		//$card->disableAttachments = TRUE;
		$card->showDeprecations = TRUE;
		$card->setDocument($this->table, $this->recData);
		$card->createContent();
		foreach ($card->content['body'] as $cp)
			$this->data['properties'][] = $cp;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Uložit jako kompletní PDF soubor včetně příloh', 'icon' => 'system/actionDownload',
				'type' => 'action', 'action' => 'print', 'data-saveas' => 'all', 'data-filename' => $this->saveAsFileName('all'),
				'data-table' => $this->table->tableId(), 'data-report' => 'mac.lan.ReportCardSWLicense', 'data-pk' => $this->recData['ndx']
		];
	}

	public function saveAsFileName ($type)
	{
		$fn = 'SWLicence'.'-';
		$fn .= $this->recData['id'].'.pdf';
		return $fn;
	}

	public function saveReportAs ()
	{
		$engine = new \lib\core\SaveDocumentAsPdf ($this->app);
		$engine->attachmentsPdfOnly = TRUE;
		$engine->setDocument($this->table, $this->recData['ndx'], 'mac.lan.ReportCardSWLicense');
		$engine->run();

		$this->fullFileName = $engine->fullFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'application/pdf';
	}
}


