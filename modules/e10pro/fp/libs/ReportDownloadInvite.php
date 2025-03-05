<?php

namespace e10pro\fp\libs;


class ReportDownloadInvite extends \Shipard\Report\FormReport
{
	function init ()
	{
		parent::init();
		$reportId = 'reports.modern.e10pro.fp.downloadInvite';

		$this->reportId = $reportId;
		$this->reportTemplate = $reportId;

		$this->pdfAttSendDisabled = TRUE;
	}

	public function loadData ()
	{
		parent::loadData();

    //$this->sendReportNdx = 102;
		$this->loadReportsTexts();
	}

	public function reportWasSent(\Shipard\Report\MailMessage $msg)
	{
	}

	protected function loadReportsTexts()
	{
		/** @var \e10\reports\TableReportsTexts */
		$tableReportsTexts = $this->app()->table('e10.reports.reportsTexts');
		$this->data ['reportTexts'] ??= [];
		$tableReportsTexts->loadReportTexts($this, $this->data ['reportTexts']);
		if (count($this->data ['reportTexts']))
		{
			$this->data ['_subtemplatesItems'] ??= [];
			if (!count($this->data ['_subtemplatesItems']))
				$this->data ['_subtemplatesItems'][] = 'reportTexts';
			$this->data ['_textRenderItems'] ??= [];
			if (!count($this->data ['_textRenderItems']))
				$this->data ['_textRenderItems'][] = 'reportTexts';
		}
	}
}
