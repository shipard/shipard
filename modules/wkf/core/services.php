<?php

namespace wkf\core;
use e10\utils;


/**
 * Class ModuleServices
 * @package wkf\core
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function checkIssuesSystemKinds()
	{
		$allSystemKinds = $this->app->cfgItem ('wkf.issues.systemKinds', []);
		foreach ($allSystemKinds as $kindNdx => $kind)
		{
			if (!intval($kindNdx))
				continue;

			$exist = $this->app->db()->query('SELECT COUNT(*) AS [cnt] FROM [wkf_base_issuesKinds] WHERE [systemKind] = %i', $kindNdx)->fetch();
			if ($exist && $exist['cnt'] > 0)
				continue;

			$newItem = $kind;
			unset($newItem['icon']);
			$newItem['systemKind'] = $kindNdx;
			$newItem['docState'] = 4000;
			$newItem['docStateMain'] = 2;

			$this->app->db()->query('INSERT INTO [wkf_base_issuesKinds] ', $newItem);
		}
	}

	function checkSystemSections()
	{
		/** @var \wkf\base\TableSections $tableSections */
		$tableSections = $this->app->table('wkf.base.sections');

		$allSystemSectionsTypes = $this->app->cfgItem ('wkf.systemSections.types', []);

		// -- top sections
		foreach ($allSystemSectionsTypes as $stNdx => $st)
		{
			if (!$stNdx || $st['topSection'])
				continue;
			if (isset($st['create']) && !$st['create'])
				continue;

			$exist = $this->app->db()->query('SELECT COUNT(*) AS [cnt] FROM [wkf_base_sections] WHERE [systemSectionType] = %i', $stNdx)->fetch();
			if ($exist && $exist['cnt'] > 0)
				continue;

			$newItem = $st;
			unset($newItem['topSection']);
			$newItem['systemSectionType'] = $stNdx;
			$newItem['docState'] = 4000;
			$newItem['docStateMain'] = 2;

			if (isset($st['dik']))
				unset($newItem['dik']);
			if (isset($st['orderBy']))
				unset($newItem['dik']);

			$newSectionNdx = $tableSections->dbInsertRec($newItem);
			$tableSections->checkAfterSave2($newItem);
		}

		// -- inner sections
		foreach ($allSystemSectionsTypes as $stNdx => $st)
		{
			if (!$stNdx || !$st['topSection'])
				continue;
			if (isset($st['create']) && !$st['create'])
				continue;

			$exist = $this->app->db()->query('SELECT COUNT(*) AS [cnt] FROM [wkf_base_sections] WHERE [systemSectionType] = %i', $stNdx)->fetch();
			if ($exist && $exist['cnt'] > 0)
				continue;

			$topSection = $this->app->db()->query('SELECT * FROM [wkf_base_sections] WHERE [systemSectionType] = %i', $st['topSection'])->fetch();
			if (!$topSection)
				continue;

			$newItem = $st;
			unset($newItem['topSection']);
			unset($newItem['icon']);
			$newItem['parentSection'] = $topSection['ndx'];
			$newItem['systemSectionType'] = $stNdx;
			$newItem['docState'] = 4000;
			$newItem['docStateMain'] = 2;

			if (isset($st['dik']))
				unset($newItem['dik']);
			if (isset($st['orderBy']))
				unset($newItem['dik']);

			$newSectionNdx = $tableSections->dbInsertRec($newItem);
			$tableSections->checkAfterSave2($newItem);
		}
	}

	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2021-12-31', 'sql' => "UPDATE wkf_base_sections SET icon = '' WHERE icon LIKE 'icon-%'"];
		$s [] = ['end' => '2021-12-31', 'sql' => "UPDATE wkf_base_issuesKinds SET icon = '' WHERE icon LIKE 'icon-%'"];
		$s [] = ['end' => '2021-12-31', 'sql' => "UPDATE wkf_base_issuesKinds SET icon = '' WHERE icon LIKE 'e10-%'"];
		$this->doSqlScripts ($s);

		$this->checkIssuesSystemKinds();
		$this->checkSystemSections();
	}

	function issueFiltering()
	{
		$issueId = $this->app->arg('issueId');
		if (!$issueId)
		{
			echo "Param `issueId` not found...\n";
			return;
		}

		$issue = $this->app->db->query('SELECT * FROM [wkf_core_issues] WHERE [issueId] = %s', $issueId)->fetch();
		if (!$issue)
		{
			echo "Issue with id `issueId` not found...\n";
			return;
		}

		echo "===== filtering =====\n";


		$if = new \wkf\core\libs\IssueFiltering($this->app);
		$if->debug = 1;
		$if->setIssue($issue->toArray());
		$if->applyFilters();
	}

	function removeIssues()
	{
		$section = $this->app->arg('section');
		if (!$section)
		{
			echo "Param `section` not found...\n";
			return FALSE;
		}

		$debug = intval($this->app->arg('debug'));

		$macCount = intval($this->app->arg('max-count'));

		echo "===== removing =====\n";
		$ira = new \wkf\core\libs\IssuesRobotAction($this->app);
		$ira->fromCli = 1;
		$ira->debug = $debug;
		if ($macCount)
			$ira->maxCount = $macCount;
		$ira->setSections([$section]);
		$ira->setActionType(\wkf\core\libs\IssuesRobotAction::atFullRemove);
		$ira->run();

		return TRUE;
	}

	public function resetStatsIssuesCounts ()
	{
		// -- wkf-issues-all
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'wkf-issues-all');
		$this->app->db()->query (
			'INSERT INTO e10_base_statsCounters (id, s2, cnt, updated) ',
			'SELECT %s', 'wkf-issues-all', ', [issueKind], count(*), NOW() FROM [wkf_core_issues] WHERE [docStateMain] != 4 GROUP BY [issueKind]'
		);


		// -- wkf-issues-monthly
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'wkf-issues-monthly');
		$this->app->db()->query (
			'INSERT INTO e10_base_statsCounters (id, s1, s2, cnt, updated) ',
			'SELECT %s', 'wkf-issues-monthly', ', DATE_FORMAT(dateCreate, %s', '%Y-%m', ') AS dateKey, [issueKind], count(*), NOW() FROM [wkf_core_issues] WHERE [docStateMain] != 4 GROUP BY [issueKind], [dateKey]'
		);

		// -- wkf-issues-yearly
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'wkf-issues-yearly');
		$this->app->db()->query (
			'INSERT INTO e10_base_statsCounters (id, s1, s2, cnt, updated) ',
			'SELECT %s', 'wkf-issues-yearly', ', DATE_FORMAT(dateCreate, %s', '%Y', ') AS dateKey, [issueKind], count(*), NOW() FROM [wkf_core_issues] WHERE [docStateMain] != 4 GROUP BY [issueKind], [dateKey]'
		);
	}

	public function dataSourceStatsCreate()
	{
		$minDate = new \DateTime();
		$minDate = $minDate->sub(date_interval_create_from_date_string('12 months'));
		$minDate = $minDate->sub(date_interval_create_from_date_string('1 day'));

		$dsStats = new \lib\hosting\DataSourceStats($this);
		$dsStats->loadFromFile();

		$dsStats->data['docs']['created'] = new \DateTime();

		// -- count issues
		$cnt12m = $this->app->db()->query ('select count(*) as c from wkf_core_issues WHERE dateCreate > %d', $minDate)->fetch();
		$dsStats->data['issues']['last12m']['cnt'] = $cnt12m['c'];

		$cntAll = $this->app->db()->query ('select count(*) as c from wkf_core_issues')->fetch();
		$dsStats->data['issues']['all']['cnt'] = $cntAll['c'];

		$dsStats->saveToFile();
	}

	protected function uploadEmail()
	{
		$fileName = $this->app->arg('file');
		if (!$fileName)
		{
			echo "Param `file` not found...\n";
			return FALSE;
		}

		$im = new \wkf\core\services\IncomingEmail($this->app());
		$im->setFileName($fileName);
		$im->run();

		return TRUE;
	}

	public function checkIncomingIssue()
	{
		$issueNdx = intval($this->app->arg('issueNdx'));
		if (!$issueNdx)
		{
			echo "Param `issueNdx` not found...\n";
			return FALSE;
		}

		$im = new \wkf\core\libs\CheckIncomingIssue($this->app());
		$im->setIssue($issueNdx);
		$im->run();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'issue-filtering': return $this->issueFiltering();
			case 'remove-issues': return $this->removeIssues();
			case 'upload-email': return $this->uploadEmail();
			case 'check-incoming-issue': return $this->checkIncomingIssue();
		}

		parent::onCliAction($actionId);
	}

	public function onStats()
	{
		$this->resetStatsIssuesCounts();

		$this->dataSourceStatsCreate();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'stats': $this->onStats(); break;
		}
		return TRUE;
	}
}
