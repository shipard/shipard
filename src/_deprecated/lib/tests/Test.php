<?php

namespace lib\tests;

use e10pro\wkf\TableMessages, \e10\json, \e10\str, \wkf\core\TableIssues;


/**
 * Class Test
 * @package lib\tests
 */
class Test extends \e10\Utility
{
	var $testDefinition;

	var $content = [];
	var $cycleContent = [];

	var $targetUsersGroups = [];

	function addContent($content)
	{
		$this->content[] = $content;
	}

	function addCycleContent($content)
	{
		$this->cycleContent[] = $content;
	}

	function appendCycleContent ($title)
	{
		if (!count($this->cycleContent))
			return;
		$this->addContent(['type' => 'line', 'line' => $title]);
		foreach ($this->cycleContent as $cp)
			$this->content[] = $cp;
		$this->cycleContent = [];
	}

	function addMessages ($title, $messages)
	{
		if (!$messages || !count($messages))
			return;
		$h = ['#' => '#', 'text' => 'Zpráva'];
		$this->addContent(['type' => 'table', 'table' => $messages, 'header' => $h, 'title' => $title]);
	}

	public function init()
	{
	}

	public function setDefinition($def)
	{
		$this->testDefinition = $def;
	}

	protected function checkMessage (&$recData)
	{
	}

	public function test()
	{
	}

	function subject ()
	{
		return $this->testDefinition['name'];
	}

	function linkId()
	{
		return $this->testDefinition['id'];
	}

	function save()
	{
		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app()->table('wkf.core.issues');

		$q[] = 'SELECT * FROM [wkf_core_issues]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [issueType] = %i', TableIssues::mtTest);
		array_push($q, ' AND [linkId] = %s', $this->linkId());
		array_push($q, ' AND [docState] = %i', 1200);

		$existedIssue = $this->db()->query($q)->fetch();

		if ($existedIssue)
		{
			$issueRecData = $tableIssues->loadItem($existedIssue['ndx']);
			if (!count($this->content))
			{
				$issueRecData['body'] = '';
				$issueRecData['text'] = '';
				$issueRecData['docState'] = 4000;
				$issueRecData['docStateMain'] = 2;
			}
			else
			{
				$issueRecData['text'] = '';
				$issueRecData['body'] = json::lint($this->content);
				$issueRecData['docState'] = 1200;
				$issueRecData['docStateMain'] = 1;
			}
			$issueRecData['dateTouch'] = new \DateTime();
			$issueRecData['dateCreate'] = $issueRecData['dateTouch'];

			$issueNdx = $tableIssues->dbUpdateRec($issueRecData);
			$issueRecData = $tableIssues->loadItem($issueNdx);
			$tableIssues->checkAfterSave2($issueRecData);
			$tableIssues->docsLog ($issueNdx);

			return;
		}

		if (!count($this->content))
			return;

		$sectionNdx = 0;
		$issueKindNdx = 0;

		if (isset($this->testDefinition['systemSection']) && $this->testDefinition['systemSection'])
			$sectionNdx = $tableIssues->defaultSection($this->testDefinition['systemSection']);

		if (!$issueKindNdx)
			$issueKindNdx = $tableIssues->defaultSystemKind(5); // systemTest record
		if (!$sectionNdx)
			$sectionNdx = $tableIssues->defaultSection(20); // secretariat

		$issueKindCfg = $this->app()->cfgItem ('wkf.issues.kinds.'.$issueKindNdx, NULL);
		$issueType = $issueKindCfg['issueType'];

		$issueRecData = [
			'section' => $sectionNdx, 'issueKind' => $issueKindNdx, 'issueType' => $issueType,
			'source' => TableIssues::msTest,
			'structVersion' => $tableIssues->currentStructVersion,
			'subject' => str::upToLen($this->subject(), 100),
			'linkId' => $this->linkId(),
			'docState' => 1200, 'docStateMain' => 1, 'dateTouch' => new \DateTime()
		];

		$tableIssues->checkNewRec($issueRecData);
		$issueRecData['body'] = json::lint($this->content);

		$issueNdx = $tableIssues->dbInsertRec($issueRecData);
		$issueRecData = $tableIssues->loadItem($issueNdx);
		$tableIssues->checkAfterSave2($issueRecData);
		$tableIssues->docsLog ($issueNdx);
	}

	protected function checkNotify ()
	{
		$groupsMap = $this->app->cfgItem('e10.persons.groupsToSG', FALSE);

		if (is_string($this->testDefinition['notify']))
		{
			if ($groupsMap && isset ($groupsMap [$this->testDefinition['notify']]))
				$this->targetUsersGroups[] = $groupsMap [$this->testDefinition['notify']];
		}
	}

	public function run()
	{
		$this->init();
		$this->checkNotify ();
		$this->test();
		$this->save();
	}
}


