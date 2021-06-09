<?php

namespace e10pro\property;

use \e10\utils, e10\FormReport;


/**
 * Class ReportPropertyCard
 * @package e10pro\property
 */
class ReportPropertyCard extends FormReport
{
	function init ()
	{
		$this->reportId = 'e10pro.property.card';
		$this->reportTemplate = 'e10pro.property.card';
		$this->paperOrientation = 'landscape';
	}

	public function loadData ()
	{
		$this->setInfo('icon', 'icon-university');
		$this->setInfo('title', $this->recData['propertyId']);
		$this->setInfo('param', 'Karta majetku', $this->recData ['fullName']);

		$card = new \e10pro\property\DocumentCardProperty($this->app);
		$card->disableAttachments = TRUE;
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
				'data-table' => $this->table->tableId(), 'data-report' => 'e10pro.property.ReportPropertyCard', 'data-pk' => $this->recData['ndx']
		];
	}

	public function saveAsFileName ($type)
	{
		$fn = 'Majetek'.'-';
		$fn .= $this->recData['propertyId'].'.pdf';
		return $fn;
	}

	public function saveReportAs ()
	{
		$engine = new \lib\core\SaveDocumentAsPdf ($this->app);
		$engine->attachmentsPdfOnly = TRUE;
		$engine->setDocument($this->table, $this->recData['ndx'], 'e10pro.property.ReportPropertyCard');
		$engine->run();

		$this->fullFileName = $engine->fullFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'application/pdf';
	}
}
