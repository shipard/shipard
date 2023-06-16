<?php

namespace Shipard\Report;
use \Shipard\Utils\Utils;


class FormReport extends Report
{
	protected $table;
	public $recData;

	public function __construct ($table, $recData)
	{
		parent::__construct ($table->app ());
		$this->table = $table;
		$this->recData = $recData;
		$this->recData ['print'] = $this->getPrintValues ($this->table, $recData);
		$this->loadData ();

		if ($this->app->testGetParam ('saveas') !== '')
			$this->saveAs = $this->app->testGetParam ('saveas');
	}

	public function init ()
	{
		parent::init();
	}

	public function checkDocumentInfo (&$documentInfo) {}

	public function getPrintValues ($table, $item)
	{
		return $table->getPrintValues ($item);
	}

	public function renderReport ()
	{
		$this->loadData2();

		if ($this->saveAs === FALSE)
		{
			$fileName = Utils::safeChars($this->createReportPart('fileName'), TRUE);
			if ($fileName != '')
				$this->setSaveFileName($fileName.'.pdf');
		}
		elseif ($this->saveAs === 'json')
		{
			$fileName = Utils::safeChars($this->createReportPart('fileName'), TRUE);

			$this->setSaveFileName($fileName.'.txt');
			$this->mimeType = 'text/plain';
		}
		elseif ($this->saveAs === 'html')
		{
			$fileName = Utils::safeChars($this->createReportPart('fileName'), TRUE);

			$this->setSaveFileName($fileName.'.html');
			$this->mimeType = 'text/html';
		}

		if ($this->reportMode == FormReport::rmPOS || $this->reportMode == FormReport::rmLabels)
		{
			if ($this->rasterPrint)
			{
				$this->srcFileExtension = 'html';
				$this->mimeType = 'image/png';
			}
			else
			{
				$this->srcFileExtension = 'rawprint';
				$this->mimeType = 'application/x-octet-stream';
			}
			$this->objectData ['mainCode'] = $this->renderTemplate ($this->reportTemplate);
		}
		else
		{
			parent::renderReport();
		}
	}

	public function saveReportAs ()
	{
		if ($this->saveAs === 'json')
		{
			$this->fullFileName = __APP_DIR__ . "/tmp/r-" . time() . '-' . mt_rand () . '.txt';
			$this->mimeType = 'text/plain';
			if ($this->app()->hasRole('admin'))
			{
				$t = new TemplateMustache ($this->app);
				$t->loadTemplate($this->reportId);
				$t->setData ($this);
				file_put_contents($this->fullFileName, json::lint(['data' => $t->data]));
			}
			else
				file_put_contents($this->fullFileName, 'forbidden');
		}
		elseif ($this->saveAs === 'html')
		{
			$this->fullFileName = __APP_DIR__ . "/tmp/r-" . time() . '-' . mt_rand () . '.html';
			$this->mimeType = 'text/html';
			if ($this->app()->hasRole('admin'))
				file_put_contents($this->fullFileName, $this->objectData ['mainCode']);
			else
				file_put_contents($this->fullFileName, 'forbidden');
		}
	}

	protected function createReportHeaderFooter()
	{
		$this->pageHeader = $this->renderTemplate ($this->reportTemplate, 'pageHeader');
		$this->pageFooter = $this->renderTemplate ($this->reportTemplate, 'pageFooter');

		if ($this->pageHeader !== '' && $this->pageFooter === '')
			$this->pageFooter = ' ';
		if ($this->pageFooter !== '' && $this->pageHeader === '')
			$this->pageHeader = ' ';
	}

	public function addMessageAttachments(\Shipard\Report\MailMessage $msg)
	{
	}

	public function loadData ()
	{
		parent::loadData();
		$this->data['mainBCId'] = $this->table->itemMainBCId($this->recData);
	}
}
