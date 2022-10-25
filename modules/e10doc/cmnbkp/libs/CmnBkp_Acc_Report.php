<?php

namespace e10doc\cmnbkp\libs;


class CmnBkp_Acc_Report extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('e10doc.cmnbkp.acc');
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
			'text' => 'Uložit jako kompletní PDF soubor včetně příloh', 'icon' => 'system/actionDownload',
			'type' => 'action', 'action' => 'print', 'data-saveas' => 'all', 'data-filename' => $this->saveAsFileName('all'),
			'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.cmnbkp.libs.CmnBkp_Acc_Report', 'data-pk' => $this->recData['ndx']
		];

		if ($this->recData['docType'] === 'invno')
		{
			$testISDoc = intval($this->app()->cfgItem('options.experimental.testISDoc', 0));
			if ($testISDoc)
			{
				$printButton['dropdownMenu'][] = [
					'text' => 'Elektronický doklad ISDOC', 'icon' => 'system/actionDownload',
					'type' => 'action', 'action' => 'print', 'data-saveas' => 'isdoc-xml', 'data-filename' => $this->saveAsFileName('isdoc-xml'),
					'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.core.libs.reports.DocReportISDoc', 'data-pk' => $this->recData['ndx']
				];
			}
		}
	}

	public function saveReportAs ()
	{
		$engine = new \lib\docs\SaveDocumentAsPdf ($this->app);
		$engine->setDocument($this->recData['ndx']);
		$engine->run();

		$this->fullFileName = $engine->fullFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'application/pdf';
	}

	public function saveAsFileName ($type)
	{
		$fn = $this->data ['documentName'].'-';
		$fn .= $this->recData['docNumber'].'.pdf';
		return $fn;
	}
}
