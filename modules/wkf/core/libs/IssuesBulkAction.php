<?php


namespace wkf\core\libs;
use e10\E10ApiObject;


/**
 * Class IssuesBulkAction
 * @package wkf\core\libs
 */
class IssuesBulkAction extends E10ApiObject
{
	var $userNdx = 0;
	var $actionType = '';
	var $pks = [];

	var $paramsError = 1;

	public function init ()
	{
		$this->userNdx = $this->app()->userNdx();

		$this->actionType = $this->requestParam ('action-type', '');
		if ($this->actionType === '')
		{
			return;
		}

		$pks = $this->requestParam ('pk', '');
		if ($pks !== '')
		{
			$pksNumbers = explode(',', $pks);
			foreach ($pksNumbers as $pk)
				$this->pks[] = intval($pk);
		}

		$this->paramsError = 0;
	}

	function runAction()
	{
		if ($this->paramsError)
			return FALSE;

		switch ($this->actionType)
		{
			case 'markAsUnread': return $this->runAction_MarkAsUnread('all');
			case 'markAsUnread_done': return $this->runAction_MarkAsUnread('done');
			case 'markAsUnread_archive': return $this->runAction_MarkAsUnread('archive');
		}
	}

	function runAction_MarkAsUnread($type)
	{
		if (!count($this->pks))
			return FALSE;

		$allSections = $this->app()->cfgItem ('wkf.sections.all', []);
		$sectionNdx = intval($this->requestParam ('section', 0));
		if (!isset($allSections[$sectionNdx]))
			return FALSE;

		$section = $allSections[$sectionNdx];
		$sectionsPks = [$sectionNdx];
		if (isset($section['subSections']) && count($section['subSections']))
			$sectionsPks = array_merge($sectionsPks, $section['subSections']);

		$q = [];
		array_push ($q, 'DELETE [ntf] FROM [e10_base_notifications] AS [ntf]');
		array_push ($q, ' LEFT JOIN [wkf_core_issues] AS [issues] ON ntf.[recIdMain] = [issues].ndx');
		array_push ($q, ' WHERE [ntf].[tableId] = %s', 'wkf.core.issues');
		array_push ($q, ' AND [ntf].[personDest] = %i', $this->userNdx);
		array_push ($q, ' AND [ntf].[recIdMain] IN %in', $this->pks);
		array_push ($q, ' AND [issues].[section] IN %in', $sectionsPks);

		if ($type === 'done')
			array_push ($q, ' AND [issues].[docStateMain] = %i', 2);
		elseif ($type === 'archive')
			array_push ($q, ' AND [issues].[docStateMain] = %i', 5);

		$this->db()->query($q);

		return TRUE;
	}

	public function createResponseContent($response)
	{
		$this->init();
		$this->runAction();

		$response->add ('reloadNotifications', 1);
		$response->add ('success', 1);
	}
}
