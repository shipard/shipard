<?php

namespace e10mnf\core;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableWorkRecs
 * @package e10mnf\core
 */
class TableWorkRecs extends DbTable
{
	const dthNone = 0, dthDateFromToAndTimeFromTo = 1, dthDateAndTimeFromTo = 2, dthDateAndTimeLenHHMM = 3, dthDateAndTimeLenInHours = 4;
	const dtrNone = 0, dtrDateFromToAndTimeFromTo = 1, dtrDateAndTimeFromTo = 2, dtrDateAndTimeLenHHMM = 3, dtrDateAndTimeLenInHours = 4,
		dtrTimeFromTo = 5, dtrTimeLenHHM = 6, dtrTimeLenHours = 7;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.core.workRecs', 'e10mnf_core_workRecs', 'Pracovní záznamy');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		$this->resetDocType($recData);

		$dk = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$recData['docKind'], []);
		$useRows = (isset($dk['useRows']) && $dk['useRows']) ? 1 : 0;

		$askPerson = isset($dk['askPerson']) ? $dk['askPerson'] : 0;
		$askItem = isset($dk['askItem']) ? $dk['askItem'] : 0;
		$askPrice = isset($dk['askPrice']) ? $dk['askPrice'] : 0;

		if ($askPerson !== 0)
			$recData['person'] = 0;


		$dth = $dk['askDateTimeOnHead'];

		if ($dth === TableWorkRecs::dthNone)
		{

		}
		elseif ($dth === TableWorkRecs::dthDateFromToAndTimeFromTo)
		{

		}
		elseif ($dth === TableWorkRecs::dthDateAndTimeFromTo)
		{
			$recData['endDate'] = utils::createDateTime($recData['beginDate']);
		}
		elseif ($dth === TableWorkRecs::dthDateAndTimeLenInHours)
		{
			$recData['endDate'] = utils::createDateTime($recData['beginDate']);
		}

		if ($dth !== TableWorkRecs::dthNone)
		{
			$beginStr = '';
			if (!utils::dateIsBlank($recData['beginDate']))
				$beginStr .= utils::createDateTime($recData['beginDate'])->format('Y-m-d');
			if ($recData['beginTime'] !== '')
				$beginStr .= ' ' . $recData['beginTime'] . ':00';
			if ($beginStr === '')
				$recData['beginDateTime'] = NULL;
			else
				$recData['beginDateTime'] = new \DateTime($beginStr);

			$endStr = '';
			if (!utils::dateIsBlank($recData['endDate']))
				$endStr .= utils::createDateTime($recData['endDate'])->format('Y-m-d');
			if ($recData['endTime'] !== '')
				$endStr .= ' ' . $recData['endTime'] . ':00';
			if ($endStr === '')
				$recData['endDateTime'] = NULL;
			else
				$recData['endDateTime'] = new \DateTime($endStr);
		}

		$useRows = (isset($dk['useRows']) && $dk['useRows']) ? 1 : 0;
		if (!$useRows)
		{
			$recData['timeLen'] = 0;

			if ($dth === TableWorkRecs::dthDateAndTimeLenInHours)
			{
				$recData['timeLen'] = intval($recData['timeLenHours'] * 60 * 60);
			} elseif ($dth !== TableWorkRecs::dthNone)
			{
				if (!utils::dateIsBlank($recData['beginDateTime']) && !utils::dateIsBlank($recData['endDateTime']))
					$recData['timeLen'] = utils::dateDiffSeconds(utils::createDateTime($recData['beginDateTime'], TRUE), utils::createDateTime($recData['endDateTime'], TRUE));
			}
		}
		/*
		if ($useRows)
		{
			$recData['dateBegin'] = NULL;
			$recData['dateEnd'] = NULL;
		}
		else
		{
			if ($askTime === 0)
			{
				if (isset($recData['dateBegin']) && !utils::dateIsBlank($recData['dateBegin'])
					&& isset($recData['dateEnd']) && !utils::dateIsBlank($recData['dateEnd']))
					$recData['timeLen'] = utils::dateDiffSeconds(utils::createDateTime($recData['dateBegin'], TRUE), utils::createDateTime($recData['dateEnd'], TRUE));
				else
					$recData['timeLen'] = 0;
			}
			else
			{
				$recData['dateBegin'] = NULL;
				$recData['dateEnd'] = NULL;
			}
		}
		*/
	}

	public function checkDocumentState_OLD (&$recData)
	{
		parent::checkDocumentState ($recData);

		switch ($recData['docState'])
		{
			case	1200:
				if (utils::dateIsBlank ($recData['dateBegin']))
					$recData['dateBegin'] = new \DateTime ();
				break;
			case	4000:
				if (utils::dateIsBlank ($recData['dateEnd']))
					$recData['dateEnd'] = new \DateTime ();
				break;
		}
	}

	public function checkDocumentState (&$recData)
	{
		parent::checkDocumentState ($recData);

		// -- check document number
		if ($recData['docStateMain'] == 1 || $recData['docStateMain'] == 2)
		{
			if (!isset ($recData['docNumber']) || $recData['docNumber'] === '' || $recData['docNumber'][0] === '!')
				$this->makeDocNumber ($recData);
		}
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		$recData ['author'] = $this->app()->user()->data ('id');

		if (!isset($recData['docKind']))
			$recData['docKind'] = 0;
		if (isset ($recData['dbCounter']) && $recData['dbCounter'] !== 0 && $recData['docKind'] == 0)
		{
			$dbCounter = $this->app()->cfgItem ('e10mnf.workRecs.wrNumbers.'.$recData['dbCounter'], []);
			//$useDocKinds = utils::param ($dbCounter, 'useDocKinds', 0);
			$recData['docKind'] = $dbCounter['docKind'];
		}

		if ($recData['docKind'] == 0)
		{
			$docKinds = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds', NULL);
			if ($docKinds)
				$recData['docKind'] = key($docKinds);
		}

		if ($recData['docKind'])
		{
			$docKind = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$recData['docKind'], FALSE);
			if ($docKind)
			{
				if ($docKind['enableStartStop'] && (!isset ($recData['docState']) || $recData['docState'] == 1000))
					$recData['docState'] = 1001;
			}
		}

		//if (!isset($recData['dateBegin']) || utils::dateIsBlank ($recData['dateBegin']))
		//	$recData['dateBegin'] = new \DateTime ();

		if (isset($recData['docState']) && $recData['docState'] != 1001)
		{
			//$recData['dateEnd'] = new \DateTime ();
			//$recData['dateEnd']->modify('+15 minutes');
		}

		$this->resetDocType($recData);
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['docNumber'] = '';
		$recData ['dbCounterNdx'] = 0;
		$recData ['dbCounterYear'] = 0;

		$recData ['author'] = $this->app()->user()->data ('id');
		$recData ['dateBegin'] = new \DateTime();
		unset($recData ['dateEnd']);
		unset($recData ['timeLen']);
		unset($recData ['money']);

		if (isset($recData['docKind']))
		{
			$docKind = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$recData['docKind'], FALSE);
			if ($docKind)
			{

				if ($docKind['enableStartStop'])
				{
					$recData['docState'] = 1001;
					$recData['docStateMain'] = 0;
					$recData['_fixedDocState'] = 1;
				}
			}
		}

		return $recData;
	}

	function resetDocType (&$recData)
	{
		$dk = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$recData['docKind'], FALSE);
		if ($dk)
			$recData['docType'] = $dk['docType'];
		else
			$recData['docType'] = 0;
	}

	public function makeDocNumber (&$recData)
	{
		$formula = '';

		if ($formula == '')
			$formula = '%C%y%6';

		$dd = NULL;
		if (isset($recData['dateBegin']) && !utils::dateIsBlank($recData['dateBegin']))
			$dd = utils::createDateTime($recData['dateBegin']);
		elseif (isset($recData['dateCreate']) && !utils::dateIsBlank($recData['dateCreate']))
			$dd = utils::createDateTime($recData['dateCreate']);

		if (utils::dateIsBlank($dd))
			$dd = utils::now();

		$year2 = $dd->format ('y');
		$year4 = $dd->format ('Y');

		$recData['dbCounterYear'] = $year4;

		// make select code
		$q[] = 'SELECT MAX([dbCounterNdx]) AS maxDbCounterNdx FROM [e10mnf_core_workRecs]';
		array_push ($q, ' WHERE [dbCounter] = %i', $recData['dbCounter']);
		if (strpos ($formula, '%y') !== FALSE || strpos ($formula, '%Y') !== FALSE)
			array_push ($q, ' AND [dbCounterYear] = %i', $recData['dbCounterYear']);

		$res = $this->db()->query ($q);
		$r = $res->fetch ();

		$dbCounter = $this->app()->cfgItem ('e10mnf.workRecs.wrNumbers.'.$recData['dbCounter'], FALSE);
		$dbCounterId = ($dbCounter === FALSE) ? '1' : $dbCounter ['docKeyId'];


		$firstNumber = 1;
		$dbCounterNdx = intval($r['maxDbCounterNdx']) + $firstNumber;

		$rep = [
			'%Y' => $year4, '%y' => $year2,
			'%C' => $dbCounterId,
			'%2' => sprintf ('%02d', $dbCounterNdx), '%3' => sprintf ('%03d', $dbCounterNdx),
			'%4' => sprintf ('%04d', $dbCounterNdx), '%5' => sprintf ('%05d', $dbCounterNdx), '%6' => sprintf ('%06d', $dbCounterNdx)
		];
		$docNumber = strtr ($formula, $rep);

		$recData['docNumber'] = $docNumber;
		$recData['dbCounterNdx'] = $dbCounterNdx;

		return $docNumber;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		$dk = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$recData['docKind'], FALSE);
		if ($dk)
		{
			if ($dk['icon'] !== '')
				return $dk['icon'];
			$dt = $this->app()->cfgItem ('e10mnf.core.wrTypes.'.$dk['docType'], FALSE);
			if ($dt)
				return $dt['icon'];
		}

		return parent::tableIcon ($recData, $options);
	}

	public function createHeader ($recData, $options)
	{
		$sourcesIcons = [0 => 'icon-keyboard-o', 1 => 'icon-envelope-o', 2 => 'icon-plug', 3 => 'icon-android'];
		$item = $recData;

		$linkedPersons = \E10\Base\linkedPersons ($this->app(), $this, $recData['ndx']);

		$hdr ['icon'] = $this->tableIcon ($recData);

		$props = [];

		if ($recData['author'])
		{
			$author = $this->loadItem($recData['author'], 'e10_persons_persons');
			$props[] = ['class' => 'e10-off', 'icon' => 'icon-user', 'text' => $author ['fullName']];
		}

		if (isset ($linkedPersons [$item ['ndx']]['e10pro-wkf-message-from']))
		{
			$linkedPersons [$item ['ndx']]['e10pro-wkf-message-from'][0]['class'] = 'e10-off';
			$props[] = $linkedPersons [$item ['ndx']]['e10pro-wkf-message-from'];
		}

		if (!utils::dateIsBlank($item ['dateCreate']))
			$props[] = ['class' => 'e10-small', 'icon' => $sourcesIcons[$item['source']], 'text' => utils::datef ($item ['dateCreate'], '%D, %T')];


		if ($recData['projectPart'])
		{
			$projectPart = $this->loadItem($recData['projectPart'], 'e10pro_wkf_projectsParts');
			$props[] = ['icon' => 'icon-flag-checkered', 'class' => 'tag label-danger pull-right', 'text' => $projectPart['id']];
		}
		if ($recData['project'])
		{
			$project = $this->loadItem($recData['project'], 'e10pro_wkf_projects');
			$props[] = ['icon' => 'icon-lightbulb-o', 'class' => 'tag tag-info pull-right', 'text' => $project['fullName']];
		}

		if (count($props))
			$hdr ['info'][] = ['class' => 'info', 'value' => $props];

		$hdr ['info'][] = ['class' => 'title', 'value' => $item['subject']];

		return $hdr;
	}

	public function addWorksRecsButtons (&$buttons, $params)
	{
		$wrKinds = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds', []);
		$wrTypes = $this->app()->cfgItem ('e10mnf.core.wrTypes', []);

		$oneButton = [
			'action' => '', 'icon' => 'icon-paw',
			'text' => '', 'title' => 'Pracovní záznamy', 'type' => 'button', 'actionClass' => 'btn btn-sm',
			'class' => '', 'btnClass' => 'btn-info',
			'dropdownMenu' => [],
		];

		$allButtons = [];

		foreach ($wrKinds as $wrKindNdx => $wrKind)
		{
			$add = FALSE;

			if ($wrKind['startGlobal'])
				$add = TRUE;
			elseif ($wrKind['startOnIssue'] && isset($params['ownerMsg']))
				$add = TRUE;
			elseif (isset($params['project']) && $params['project'] && $wrKind['startOnProject'])
				$add = TRUE;
			elseif (isset($params['uiPlace']) && $params['uiPlace'] === 'bboard' && $wrKind['startOnBBoard'])
				$add = TRUE;
			elseif (isset($params['forceTypes']) && in_array($wrKind['msgType'], $params['forceTypes']))
				$add = TRUE;

			$enabled = TRUE;
			$enabledFromProjectGroup = FALSE;
			if (isset($wrKind['projectsGroups']))
			{
				if (!isset($params['projectsGroup']) || !$params['projectsGroup'] || !in_array($params['projectsGroup'], $wrKind['projectsGroups']))
					$enabled = FALSE;
				else
					$enabledFromProjectGroup = TRUE;
			}
			if (!$enabledFromProjectGroup && isset($wrKind['projects']))
			{
				if (!isset($params['project']) || !$params['project'] || !in_array($this->activeProjectNdx, $wrKind['projects']))
					$enabled = FALSE;
			}

			$ug = $this->app()->userGroups();
			$enabledFromUserGroups = FALSE;
			if (isset($wrKind['usersGroups']))
			{
				if (!count($ug) || count(array_intersect($ug, $wrKind['usersGroups'])) == 0)
					$enabled = FALSE;
				else
					$enabledFromUserGroups = TRUE;
			}
			if (!$enabledFromUserGroups && isset($wrKind['users']))
			{
				if (!in_array($this->app()->userNdx(), $wrKind['users']))
					$enabled = FALSE;
			}

			if (!$enabled)
				$add = FALSE;

			if (!$add)
				continue;

			$wrType = $wrTypes[$wrKind['docType']];

			$addParams = '__docKind=' . $wrKindNdx . '&__docType=' . $wrKind['docType'];
			if (isset($params['project']) && $params['project'])
				$addParams .= '&__project=' . $params['project'];
			if (isset($params['projectPart']) && $params['projectPart'])
				$addParams .= '&__projectPart=' . $params['projectPart'];
			if (isset($params['projectFolder']) && $params['projectFolder'])
				$addParams .= '&__projectFolder=' . $params['projectFolder'];
			if (isset($params['ownerMsg']))
				$addParams .= '&__ownerMsg=' . $params['ownerMsg'];

			$icon = ($wrKind['icon'] !== '') ? $wrKind['icon'] : $wrType['icon'];
			$txtTitle = $wrKind['fn'];
			$txtText = '';
			$addButtonPullDown = [
				'action' => 'new', 'data-table' => 'e10mnf.core.workRecs', 'icon' => $icon,
				'text' => $txtTitle, 'data-addParams' => $addParams,
			];

			$addButton = [
				'action' => 'new', 'data-table' => 'e10mnf.core.workRecs', 'icon' => $icon,
				'text' => $txtText, 'title' => $txtTitle, 'type' => 'button', 'actionClass' => 'btn btn-sm',
				'class' => 'e10-param-addButton', 'btnClass' => 'btn-info',
				'data-addParams' => $addParams,
			];

			if (isset($params['thisViewerId']))
			{
				$addButton['data-srcobjecttype'] = 'viewer';
				$addButton['data-srcobjectid'] = $params['thisViewerId'];
			}

			$allButtons[] = $addButton;
			$oneButton['dropdownMenu'][] = $addButtonPullDown;
		}

		if (!count($allButtons))
			return;

		if (count($allButtons) > 4)
			$buttons[] = $oneButton;
		else
			foreach ($allButtons as $b)
				$buttons[] = $b;
	}
}


/**
 * Class ViewWorkRecs
 * @package e10mnf\core
 */
class ViewWorkRecs extends TableView
{
	var $now;
	var $useWorkOrders = FALSE;

	public function init ()
	{
		$this->now = new \DateTime();
		if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
			$this->useWorkOrders = TRUE;

		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];

		$this->setMainQueries ($mq);

		$this->createBottomTabs();
	}

	public function createBottomTabs ()
	{
		$dbCounters = $this->table->app()->cfgItem ('e10mnf.workRecs.wrNumbers', FALSE);
		if ($dbCounters !== FALSE)
		{
			$activeDbCounter = key($dbCounters);
			if (count ($dbCounters) > 1)
			{
				forEach ($dbCounters as $cid => $c)
				{
					$addParams = ['dbCounter' => intval($cid)];
					$nbt = [
						'id' => $cid, 'title' => ($c['tabName'] !== '') ? $c['tabName'] : $c['shortName'],
						'active' => ($activeDbCounter === $cid),
						'addParams' => $addParams
					];
					$bt [] = $nbt;
				}
				$this->setBottomTabs ($bt);
			}
			else
				$this->addAddParam ('dbCounter', $activeDbCounter);
		}
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$bottomTabId = intval($this->bottomTabId());
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT workrecs.*, persons.fullName AS personName';

		//array_push ($q, ', projects.fullName AS projectName');

		if ($this->useWorkOrders)
			array_push ($q, ', workOrders.docNumber AS woDocNumber');

		array_push ($q, ' FROM [e10mnf_core_workRecs] AS workrecs');

		//array_push ($q, ' LEFT JOIN e10pro_wkf_projects AS projects ON workrecs.project = projects.ndx');

		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON workrecs.person = persons.ndx');

		if ($this->useWorkOrders)
			array_push ($q, ' LEFT JOIN e10mnf_core_workOrders AS workOrders ON workrecs.workOrder = workOrders.ndx');

		array_push ($q, ' WHERE 1');

		// -- bottom tabs
		if ($bottomTabId != 0)
			array_push ($q, ' AND workrecs.dbCounter = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [workrecs].[subject] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [projects].fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [persons].fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND workrecs.[docStateMain] < 4");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND workrecs.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [dateBegin] DESC ' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY workrecs.[docStateMain], [docNumber] DESC ' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['personName'])
		{
			$listItem ['t1'] = $item['personName'];
			if ($item['subject'] !== '')
				$listItem ['t3'] = $item['subject'];
		}
		else
		{
			$listItem ['t1'] = $item['subject'];
		}

		$listItem ['i1'] = ['text' => $item['docNumber'], 'class' => 'id'];

		$propsBeginEnd = [];
		if ($item['beginDateTime'])
			$propsBeginEnd [] = ['icon' => 'icon-sign-in', 'text' => utils::datef($item['beginDateTime'], '%d, %T'), 'class' => ''];
		if ($item['endDateTime'])
		{
			$format = '%T';
			if ($item['beginDateTime'] && $item['beginDateTime']->format('Ymd') !== $item['endDateTime']->format('Ymd'))
				$format = '%d, %T';

			$propsBeginEnd [] = ['icon' => 'icon-sign-out', 'text' => utils::datef($item['endDateTime'], $format), 'class' => ''];
		}
		if (count($propsBeginEnd))
			$listItem ['t2'] = $propsBeginEnd;
		//else
		//	$listItem ['t2'] = '---';

		$propsTime = [];

		$allMinutes = utils::minutesToTime($item['timeLen'] / 60);
		$propsTime[] = [
			'text' => $allMinutes,
			'suffix' => utils::nf($item['timeLen'] / 60 / 60, 2) . ' hod',
			'icon' => 'icon-clock-o', 'class' => 'label label-success'
		];

		if (count($propsTime))
			$listItem ['i2'] = $propsTime;

		$info = [];
		if ($item['projectName'])
			$info [] = ['icon' => 'icon-lightbulb-o', 'text' => $item['projectName'], 'class' => 'label label-default'];
		if ($this->useWorkOrders && $item['woDocNumber'])
			$info [] = ['icon' => 'icon-industry', 'text' => $item['woDocNumber'], 'class' => 'label label-info'];
		$listItem ['t3'] = $info;

		return $listItem;
	}
}


/**
 * Class ViewDetailWorkRec
 * @package e10mnf\core
 */
class ViewDetailWorkRec extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10mnf.core.WorkRecCard');
	}
}


/**
 * Class FormWorkRec
 * @package e10mnf\core
 */
class FormWorkRec extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$useProjects = $this->app()->cfgItem ('options.core.useProjects', 0);

		$dk = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$this->recData['docKind'], []);
		$useRows = (isset($dk['useRows']) && $dk['useRows']) ? 1 : 0;
		$askDate = isset($dk['askDate']) ? $dk['askDate'] : 0;
		$askTime = isset($dk['askTime']) ? $dk['askTime'] : 0;
		$askPerson = isset($dk['askPerson']) ? $dk['askPerson'] : 0;
		$askProject = isset($dk['askProject']) ? $dk['askProject'] : 0;
		$askSubject = isset($dk['askSubject']) ? $dk['askSubject'] : 0;
		$askNote = isset($dk['askNote']) ? $dk['askNote'] : 0;
		$askWorkOrder = isset($dk['askWorkOrder']) ? $dk['askWorkOrder'] : 0;
		$askItem = isset($dk['askItem']) ? $dk['askItem'] : 0;
		$askPrice = isset($dk['askPrice']) ? $dk['askPrice'] : 0;
		$askPersons = (isset($dk['askPersons']) && $dk['askPersons']);

		$dth = $dk['askDateTimeOnHead'];

		$tabs ['tabs'][] = ['text' => 'Popis', 'icon' => 'x-content'];
		if ($useRows)
			$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'icon-list'];
		$tabs ['tabs'][] = ['text' => 'Fakturace', 'icon' => 'e10-docs-invoices-out'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'x-wrench'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-attachments'];


		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					if ($askSubject)
						$this->addColumnInput ('subject');
					if ($askPerson === 0)
						$this->addColumnInput ('person');
					if ($askProject === 1 && $useProjects)
					{
						$this->addColumnInput('project');
						$this->addColumnInput('projectPart');
					}

					if ($askWorkOrder == 1)
						$this->addColumnInput('workOrder');

					if ($askPrice == 1)
					{
						$this->openRow();
							$this->addColumnInput('money');
							$this->addColumnInput('currency');
						$this->closeRow();
					}

					if ($dth === TableWorkRecs::dthDateFromToAndTimeFromTo)
					{
						$this->openRow();
							$this->addColumnInput('beginDate');
							$this->addColumnInput('beginTime');
						$this->closeRow();
						$this->openRow();
							$this->addColumnInput('endDate');
							$this->addColumnInput('endTime');
						$this->closeRow();
					}
					elseif ($dth === TableWorkRecs::dthDateAndTimeFromTo)
					{
						$this->openRow();
							$this->addColumnInput('beginDate');
							$this->addColumnInput('beginTime');
							$this->addColumnInput('endTime');
						$this->closeRow();
					}
					elseif ($dth === TableWorkRecs::dthDateAndTimeLenInHours)
					{
						$this->addColumnInput('beginDate');
						$this->addInput('timeLenHours', 'Čas celkem');
					}

					if ($askPersons)
						$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

					if ($askNote)
						$this->addColumnInput ('text');
				$this->closeTab ();

				if ($useRows)
				{
					$this->openTab();
						$this->addList('rows');
					$this->closeTab();
				}

				$this->openTab ();
					$this->addColumnInput ('customer');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('docKind');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function checkAfterSave ()
	{
		parent::checkAfterSave();

		if ($this->recData['docNumber'] == '')
			$this->recData['docNumber'] = '!'.sprintf ('%09d', $this->recData['ndx']);

		$dk = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$this->recData['docKind'], []);
		$useRows = (isset($dk['useRows']) && $dk['useRows']) ? 1 : 0;

		if ($useRows)
			$this->calcTimeLen();
		else
			$this->createSystemRow();

		return TRUE;
	}

	function calcTimeLen ()
	{
		$totalTimeLen = 0;
		$sum = $this->table->db()->query ('SELECT SUM(timeLen) AS totalTimeLen FROM [e10mnf_core_workRecsRows] WHERE workRec = %i', $this->recData['ndx'])->fetch ();
		if ($sum)
		{
			$totalTimeLen = $sum['totalTimeLen'];
		}
		$this->recData['timeLen'] = $totalTimeLen;
	}

	function createSystemRow ()
	{
		$rows = $this->table->db()->query ('SELECT ndx FROM [e10mnf_core_workRecsRows] WHERE workRec = %i', $this->recData['ndx'], ' ORDER BY ndx');

		$cnt = 0;
		foreach ($rows as $r)
		{
			if ($cnt === 0)
			{ // update first row
				$update = [
					'subject' => $this->recData['subject'],
					'person' => $this->recData['person'],
					'workOrder' => $this->recData['workOrder'],

					'beginDate' => $this->recData['beginDate'],
					'beginTime' => $this->recData['beginTime'],
					'beginDateTime' => $this->recData['beginDateTime'],
					'endDate' => $this->recData['endDate'],
					'endTime' => $this->recData['endTime'],
					'endDateTime' => $this->recData['endDateTime'],
					'timeLen' => $this->recData['timeLen'],
					'timeLenHours' => $this->recData['timeLenHours'],
				];
				$this->app()->db()->query ('UPDATE [e10mnf_core_workRecsRows] SET ', $update, ' WHERE ndx = %i', $r['ndx']);
			}
			else
			{ // delete next rows
				$this->app()->db()->query ('DELETE FROM [e10mnf_core_workRecsRows] WHERE ndx = %i', $r['ndx']);
			}
			$cnt++;
		}

		if ($cnt === 0)
		{ // insert first row
			$item = [
				'workRec' => $this->recData['ndx'],
				'subject' => $this->recData['subject'],
				'person' => $this->recData['person'],
				'workOrder' => $this->recData['workOrder'],

				'beginDate' => $this->recData['beginDate'],
				'beginTime' => $this->recData['beginTime'],
				'beginDateTime' => $this->recData['beginDateTime'],
				'endDate' => $this->recData['endDate'],
				'endTime' => $this->recData['endTime'],
				'endDateTime' => $this->recData['endDateTime'],
				'timeLen' => $this->recData['timeLen'],
				'timeLenHours' => $this->recData['timeLenHours'],
			];
			$this->app()->db()->query ('INSERT INTO [e10mnf_core_workRecsRows] ', $item);
		}
	}
}
