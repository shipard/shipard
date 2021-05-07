<?php


namespace wkf\core\libs;


use e10\Utility, e10\utils, wkf\core\TableIssues;


/**
 * Class InboxIssue
 * @package wkf\core\libs
 */
class InboxIssue extends Utility
{
	/** @var \wkf\core\TableIssues */
	var $tableIssues;

	var $recData = [];
	var $systemInfo = [];
	var $issueNdx = 0;

	protected function checkIssue()
	{
		if (!isset($this->recData['section']) || !$this->recData['section'])
			$this->recData['section'] = $this->tableIssues->defaultSection(1);


		if (!isset($this->recData['issueType']) || !$this->recData['issueType'])
			$this->recData['issueType'] = TableIssues::mtInbox;

		if (!isset($this->recData['issueKind']) || !$this->recData['issueKind'])
			$this->recData['issueKind'] = $this->tableIssues->issueKindDefault ($this->recData['issueType'], TRUE);


		if (!isset($this->recData['docState']) || !$this->recData['docState'])
			$this->recData['docState'] = 1200;
		if (!isset($this->recData['docStateMain']) || !$this->recData['docStateMain'])
			$this->recData['docStateMain'] = 1;

		if (count($this->systemInfo))
			$this->recData['systemInfo'] = json_encode($this->systemInfo);
	}

	public function init()
	{
		$this->tableIssues = $this->app()->table('wkf.core.issues');
	}

	public function setBody($text)
	{
		$this->recData['text'] = $text;
	}

	public function setSubject ($subject)
	{
		$this->recData['subject'] = $subject;
	}

	public function setSystemInfo ($group, $key, $value)
	{
		$this->systemInfo[$group][$key] = $value;
	}

	public function save()
	{
		$this->checkIssue();
		$this->issueNdx = $this->tableIssues->dbInsertRec($this->recData);
		$this->recData = $this->tableIssues->loadItem($this->issueNdx);
		$this->tableIssues->checkAfterSave($this->recData);



		$this->tableIssues->checkAfterSave2($this->recData);
		$this->tableIssues->docsLog($this->issueNdx);
	}
}