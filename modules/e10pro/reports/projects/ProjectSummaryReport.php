<?php

namespace e10pro\reports\projects;

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\FormReport, \E10\DbTable;


/**
 * Class RegistrationListReport
 * @package e10pro\custreg
 */
class ProjectSummaryReport extends FormReport
{
	function init ()
	{
		$this->reportId = 'e10pro.reports.projects.summary';
		$this->reportTemplate = 'e10pro.reports.projects.summary';
	}

	public function loadAccounting ()
	{
		$as = new \e10doc\debs\AccountsSummary($this->app);
		$as->setProject($this->recData['ndx']);
		$as->run();

		$this->data['as'] = [
			'header' => ['accountId' => 'Účet', 'text' => 'text', 'money' => ' Částka'],
			'table' => $as->all
		];
	}

	public function loadData()
	{
		parent::loadData ();
		$this->loadAccounting();
	}

/*	public function checkDocumentInfo (&$documentInfo)
	{
		$documentInfo['messageDocKind'] = 'person.custreg';
	}*/
}
