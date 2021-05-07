<?php
namespace wkf\core\libs;

use e10\Utility, e10\utils, wkf\core\TableIssues, \lib\persons\LinkedPersons, \e10\str, \e10\json;


/**
 * Class IssuesRobotAction
 * @package wkf\core\libs
 */
class IssuesRobotAction extends Utility
{
	CONST atNone = 0, atFullRemove = 8;

	var $sections = [];
	var $actionType = self::atNone;
	var $docStates = [];

	var $maxCount = 100;
	var $batchSize = 10;
	var $activeCount = 0;

	var $debug = 0;
	var $fromCli = 0;

	public function setSections(array $sections)
	{
		$this->sections = $sections;
	}

	public function setActionType($actionType)
	{
		$this->actionType = $actionType;
	}

	public function setDocStates(array $docStates)
	{
		$this->docStates = $docStates;
	}

	protected function doAll()
	{
		if (!count($this->sections))
			return 0;

		$q = [];
		array_push ($q, 'SELECT issues.*');
		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' WHERE 1');

		if (count($this->sections) === 1)
			array_push ($q, ' AND [section] = %i', $this->sections[0]);
		else
			array_push ($q, ' AND [section] IN %in', $this->sections);

		if (count($this->docStates))
			array_push ($q, ' AND [docState] IN %in', $this->docStates);

		array_push($q, ' ORDER BY [displayOrder] DESC');

		array_push($q, ' LIMIT %i', $this->batchSize);


		$rows = $this->db()->query($q);

		if ($this->debug)
		{
			echo \dibi::$sql."\n--------------------------------------------------------------\n";
		}

		$cnt = 0;
		foreach ($rows as $r)
		{
			switch ($this->actionType)
			{
				case self::atFullRemove: $this->doOne_FullRemove($r->toArray()); break;
			}

			$cnt++;
			$this->activeCount++;
			if ($this->activeCount >= $this->maxCount)
				return $cnt;
		}

		return $cnt;
	}

	protected function doOne_FullRemove($recData)
	{
		if ($this->debug)
		{
			echo "#".$recData['issueId'].": ".$recData['subject']."\n";
		}

		$this->deleteComments($recData['ndx']);
		$this->deleteConnections($recData['ndx']);
		$this->deleteAttachments('wkf.core.issues', $recData['ndx']);
		$this->deleteNotifications('wkf.core.issues', $recData['ndx']);
		$this->deleteClassification('wkf.core.issues', $recData['ndx']);
		$this->deleteDocLinks('wkf.core.issues', $recData['ndx']);
		$this->deleteDocMarks($recData['ndx']);
		$this->deleteHistory('wkf.core.issues', $recData['ndx']);

		$this->db()->query('DELETE FROM [wkf_core_issues] WHERE [ndx] = %i', $recData['ndx']);
		if ($this->debug)
		{
			echo \dibi::$sql."\n\n";
		}
	}

	protected function deleteAttachments($tableId, $ndx)
	{
		$q = [];
		array_push ($q, 'SELECT * FROM [e10_attachments_files] ');
		array_push ($q, ' WHERE [tableid] = %s', $tableId, ' AND [recid] = %i', $ndx);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$fileName = __APP_DIR__.'/att/'.$r['path'].'/'.$r['filename'];

			if (is_readable($fileName))
				unlink($fileName);
			if ($this->debug)
			{
				echo ' --> rm '.$fileName."\n";
			}


			$this->db()->query('DELETE FROM [e10_attachments_files] WHERE ndx = %i', $r['ndx']);
			if ($this->debug)
			{
				echo \dibi::$sql."\n";
			}
		}
	}

	protected function deleteHistory($tableId, $ndx)
	{
		$this->db()->query ('DELETE FROM [e10_base_docslog] WHERE [tableid] = %s', $tableId,' AND [recid] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function deleteComments($issueNdx)
	{
		$q [] = 'SELECT * FROM [wkf_core_comments]';
		array_push ($q, ' WHERE issue = %i', $issueNdx);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->deleteAttachments('wkf.core.comments', $r['ndx']);
			$this->deleteNotifications('wkf.core.comments', $r['ndx']);
			$this->deleteHistory('wkf.core.comments', $r['ndx']);
			$this->db()->query('DELETE FROM [wkf_core_comments] WHERE ndx = %i', $r['ndx']);
			if ($this->debug)
			{
				echo \dibi::$sql."\n";
			}
		}
	}

	protected function deleteConnections($issueNdx)
	{
		$this->db()->query('DELETE FROM [wkf_core_issuesConnections] WHERE [issue] = %i', $issueNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
		$this->db()->query('DELETE FROM [wkf_core_issuesConnections] WHERE [connectedIssue] = %i', $issueNdx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function deleteNotifications($tableId, $ndx)
	{
		$this->db()->query ('DELETE FROM [e10_base_notifications] WHERE [tableId] = %s', $tableId, ' AND [recId] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function deleteDocMarks($ndx)
	{
		$this->db()->query ('DELETE FROM [wkf_base_docMarks] WHERE [table] = %i', 1241);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function deleteClassification($tableId, $ndx)
	{
		$this->db()->query ('DELETE FROM [e10_base_clsf] WHERE [tableid] = %s', $tableId, ' AND [recid] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function deleteDocLinks($tableId, $ndx)
	{
		$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [srcTableId] = %s', $tableId, ' AND [srcRecId] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
		$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [dstTableId] = %s', $tableId, ' AND [dstRecId] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	public function run()
	{
		while(1)
		{
			$cnt = $this->doAll();
			if ($this->fromCli)
			{
				echo $this->activeCount." ";
			}
			if (!$cnt || $this->activeCount >= $this->maxCount)
				break;
		}
		if ($this->fromCli)
		{
			echo "\n";
		}
	}
}
