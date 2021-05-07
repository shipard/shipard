<?php
namespace demo\documents\libs;

use \e10\utils, \e10\Utility, wkf\core\TableIssues;


class DemoDocIssue extends Utility
{
	var $srcDocNdx = 0;
	var $srcDocRec;
	var $tableDocHeads;

	/** @var \wkf\core\TableIssues */
	var $tableIssues;

	var $newIssueNdx = 0;

	public function init ($params)
	{
		$this->tableDocHeads = $this->app->table ('e10doc.core.heads');
		$this->tableIssues = $this->app->table ('wkf.core.issues');

		if (isset($params['docId']))
			$this->srcDocNdx = $params['dataPackageInstaller']->primaryKeys[$params['docId']];
		else
		if (isset($params['docNdx']))
			$this->srcDocNdx = intval($params['docNdx']);

		$this->loadDocument();
	}

	public function loadDocument ()
	{
		$this->srcDocRec = $this->tableDocHeads->loadItem ($this->srcDocNdx);
	}
}
