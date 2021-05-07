<?php

namespace lib;
use E10\utils;

/**
 * Class RightsManager
 * @package lib
 */
class RightsManager extends \E10\Utility
{
	var $rolesIds;
	var $roleName;
	var $roleId;

	var $allRoles;

	var $accessTo = [];


	function __construct($app)
	{
		parent::__construct($app);
		$this->allRoles = $this->app->cfgItem ('e10.persons.roles');
	}

	public function setAll ()
	{
		foreach ($this->allRoles as $roleId => $roleDef)
			$this->rolesIds[] = $roleId;
	}

	public function setPerson ($personRecData)
	{
		$this->rolesIds = explode ('.', $personRecData['roles']);
	}

	public function setRole ($roleId)
	{
		$this->roleId = $roleId;
		$this->rolesIds = [$roleId];
		$this->app->authenticator->checkRolesDependencies ($this->rolesIds, $this->allRoles);

		$this->roleName = $this->allRoles[$roleId]['name'];
	}

	public function create ()
	{
		$this->accessTo['tables'] = [];
		$this->accessTo['reports'] = [];
		$this->accessTo['widgets'] = [];

		foreach ($this->rolesIds as $roleId)
		{
			$r = $this->allRoles[$roleId];

			// -- viewers
			if (isset($r['viewers']))
			{
				foreach ($r['viewers'] as $tableId => $tableViewers)
				{
					$this->addTable ($tableId, 'viewers');
					foreach ($tableViewers as $viewerId => $accessLevel)
					{
						if (!isset($this->accessTo['tables'][$tableId]['viewers'][$viewerId]))
						{
							$viewerDef = $this->app->model()->viewDefinition ($tableId, $viewerId);
							if (!$viewerDef)
							{
								//$this->addMessage("Viewer not found; tableId: '$tableId' viewerId: '$viewerId'");
								continue;
							}
							$viewerName = isset ($viewerDef['title']) ? $viewerDef['title'] : $viewerDef['id'];
							if ($viewerName === 'default')
								$viewerName = $this->accessTo['tables'][$tableId]['tableDef']['name'];
							$this->accessTo['tables'][$tableId]['viewers'][$viewerId] = ['viewerDef' => $viewerDef, 'name' => $viewerName, 'accessLevel' => 0];
						}

						if (isset($this->accessTo['tables'][$tableId]['viewers'][$viewerId]) && $this->accessTo['tables'][$tableId]['viewers'][$viewerId]['accessLevel'] < $accessLevel)
							$this->accessTo['tables'][$tableId]['viewers'][$viewerId]['accessLevel'] = $accessLevel;
						else
							$this->accessTo['tables'][$tableId]['viewers'][$viewerId]['accessLevel'] = $accessLevel;

					}
				}
			} // viewers

			// -- documents by types
			if (isset($r['documents']))
			{
				foreach ($r['documents'] as $tableId => $tableDocuments)
				{
					if ($tableId === '*')
						continue;
					$this->addTable ($tableId, 'documents');

					foreach ($tableDocuments as $tableDoc)
					{
						$diName = isset ($tableDoc['_name']) ? $tableDoc['_name'] : json_encode($tableDoc);
						$di = ['name' => $diName, 'accessLevel' => $tableDoc['_access']];
						$this->accessTo['tables'][$tableId]['documents'][] = $di;
					}
				}
			}

			// -- global reports
			if (isset($r['reports']))
			{
				$allReports = $this->app->cfgItem ('reports', []);
				foreach ($r['reports'] as $reportId => $accessLevel)
				{
					$report = utils::searchArray($allReports, 'class', $reportId);
					$this->accessTo['reports'][$reportId] = ['name' => (isset ($report['name'])) ? $report['name'] : $reportId];
				}
			}

			// -- global widgets
			if (isset($r['widgets']))
			{
				$allWidgets = $this->app->cfgItem ('widgets', []);
				foreach ($r['widgets'] as $widgetId => $accessLevel)
				{
					$widget = utils::searchArray($allWidgets, 'class', $widgetId);
					$this->accessTo['widgets'][$widgetId] = ['name' => ($widget && isset ($widget['name'])) ? $widget['name'] : $widgetId];
				}
			}
		}
	}

	public function createDetailReview ()
	{
		$this->create();

		$c = [];
		$ti = ['info' => []];

		$ti['info'][] = ['value' => [['icon' => 'icon-table', 'text' => 'Tabulky', 'class' => 'h1']], 'class' => 'title'];

		foreach ($this->accessTo['tables'] as $tableId => $tableObjects)
		{
			$tableHead = ['value' => [['icon' => $tableObjects['icon'], 'text' => $tableObjects['tableDef']['name'], 'class' => 'h2']]];

			$cntViewers = (isset ($this->accessTo['tables'][$tableId]['viewers'])) ? count ($this->accessTo['tables'][$tableId]['viewers']) : 0;
			$cntDocumens = (isset ($this->accessTo['tables'][$tableId]['documents'])) ? count ($this->accessTo['tables'][$tableId]['documents']) : 0;

			$vi = [];
			if ($cntDocumens && $cntViewers)
				$vi[] = ['text' => 'Prohlížeče:', 'class' => 'prefix'];

			if ($cntViewers)
			{
				foreach ($this->accessTo['tables'][$tableId]['viewers'] as $viewer)
				{
					$icon = ($viewer['accessLevel'] === 2) ? 'icon-edit' : 'icon-eye';
					$class = ($viewer['accessLevel'] === 2) ? 'tag-success' : 'tag-info';
					$vwinfo = ['text' => $viewer['name'], 'icon' => $icon, 'class' => 'tag '.$class];

					if ($cntViewers < 3 && $cntDocumens === 0)
						$tableHead ['value'][] = $vwinfo;
					else
						$vi [] = $vwinfo;
				}
			}

			$ti['info'][] = $tableHead;
			if (count($vi))
			{
				$ti['info'][] = ['value' => $vi];
			}
			if ($cntDocumens)
			{
				$di = [];
				if ($cntDocumens)
					$di[] = ['text' => 'Druhy dokumentů:', 'class' => 'prefix'];

				foreach ($this->accessTo['tables'][$tableId]['documents'] as $document)
				{
					$icon = ($document['accessLevel'] === 2) ? 'icon-edit' : 'icon-eye';
					$class = ($document['accessLevel'] === 2) ? 'tag-success' : 'tag-info';
					$docinfo = ['text' => $document['name'], 'icon' => $icon, 'class' => 'tag '.$class];

					$di [] = $docinfo;
				}
				if (count($di))
					$ti['info'][] = ['value' => $di];
			}
		}
		$c[] = $ti;

		if (isset ($this->accessTo['reports']))
		{
			$rri = [];
			$ri = ['info' => []];
			$ri['info'][] = ['value' => [['icon' => 'icon-file-text-o', 'text' => 'Přehledy', 'class' => 'h1']], 'class' => 'title'];

			foreach ($this->accessTo['reports'] as $reportId => $report)
			{
				$icon = 'icon-eye';
				$class = 'tag-info';
				$reportinfo = ['text' => $report['name'], 'icon' => $icon, 'class' => 'tag '.$class];

				$rri [] = $reportinfo;
			}
			if (count($rri))
				$ri['info'][] = ['value' => $rri];
			$c[] = $ri;
		}
		return $c;
	}

	public function createDocumentation (&$documentation)
	{
		$this->create();

		$texyTables = '##'.$this->roleName."\n\n";

		$depsRoles = [];
		foreach ($this->rolesIds as $rid)
		{
			if ($rid === $this->roleId)
				continue;
			$depsRoles[] = $this->allRoles[$rid]['name'];
		}
		if (count($depsRoles))
			$texyTables .= 'Role: '.implode(', ', $depsRoles)."\n\n";

		foreach ($this->accessTo['tables'] as $tableId => $tableObjects)
		{
			$texyTables .= $tableObjects['tableDef']['name'].":\n";

			if (isset($this->accessTo['tables'][$tableId]['viewers']))
			{
				$texyTables .= '    - Prohlížeče:&nbsp; ';
				foreach ($this->accessTo['tables'][$tableId]['viewers'] as $viewer)
				{
					$icon = ($viewer['accessLevel'] === 2) ? 'icon-edit' : 'icon-eye';
					$i = $this->app()->ui()->icons()->cssClass($icon);
					$class = ($viewer['accessLevel'] === 2) ? 'label-success' : 'label-default';
					$texyTables .= " <span class='label $class'><i class='$i'></i> &nbsp;".$viewer['name'].'</span> ';
				}
				$texyTables .= "\n";
			}

			if (isset($this->accessTo['tables'][$tableId]['documents']))
			{
				$texyTables .= '    - Druhy dokumentů:&nbsp; ';
				foreach ($this->accessTo['tables'][$tableId]['documents'] as $document)
				{
					$icon = ($viewer['accessLevel'] === 2) ? 'icon-edit' : 'icon-eye';
					$i = $this->app()->ui()->icons()->cssClass($icon);
					$class = ($viewer['accessLevel'] === 2) ? 'label-success' : 'label-default';

					$texyTables .= " <span class='label $class'><i class='$i'></i> &nbsp;".$document['name'].'</span> ';
					$texyTables .= " ";
				}
				$texyTables .= "\n";
			}
		}

		$texyTables .= "\n\n";

		$documentation['rights']['all-tables']['texy'] .= $texyTables;
	}

	protected function addTable ($tableId, $objectType)
	{
		if (!isset ($this->accessTo['tables'][$tableId]))
		{
			$tableDef = $this->app->model()->table($tableId);
			if (!$tableDef)
				return FALSE;
			$tableIcon = (isset ($tableDef['icon'])) ? $tableDef['icon'] : 'icon-table';
			$this->accessTo['tables'][$tableId] = ['tableDef' => $tableDef, 'icon' => $tableIcon];
		}

		if (!isset ($this->accessTo['tables'][$tableId][$objectType]))
			$this->accessTo['tables'][$tableId][$objectType] = [];

		return TRUE;
	}
}


