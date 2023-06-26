<?php

namespace wkf\events;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableCals
 */
class TableCals extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.events.cals', 'wkf_events_cals', 'Kalendáře');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_events_cals] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'],
				'icon' => ($r['icon'] === '') ? 'system/iconCalendar': $r['icon'],
				'colorbg' => ($r['colorbg'] === '') ? '0000FA' : $r['colorbg'],
			];

			$cntPeoples = 0;

			$cntPeoples += $this->docLinksConfigList ($item, 'admins', 'e10.persons.persons', 'wkf-events-cals-admins', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($item, 'adminsGroups', 'e10.persons.groups', 'wkf-events-cals-admins', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($item, 'members', 'e10.persons.persons', 'wkf-events-cals-members', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($item, 'membersGroups', 'e10.persons.groups', 'wkf-events-cals-members', $r ['ndx']);

			$item['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg['wkf']['events']['cals'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_wkf.events.cals.json', Utils::json_lint (json_encode ($cfg)));
	}

	function docLinksConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}

	public function usersCals($enabledCfgItem = '')
	{
		$allCals = $this->app()->cfgItem('wkf.events.cals', NULL);

		$cals = [];
		if ($allCals === NULL)
			return $cals;

		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		foreach ($allCals as $itemNdx => $i)
		{
			if ($enabledCfgItem !== '' && !($i[$enabledCfgItem] ?? 0))
				continue;

			$enabled = 0;
			if (!isset($i['allowAllUsers'])) $enabled = 1;
			elseif ($i['allowAllUsers']) $enabled = 1;
			elseif (isset($i['admins']) && in_array($userNdx, $i['admins'])) $enabled = 2;
			elseif (isset($i['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $i['adminsGroups'])) !== 0) $enabled = 2;
			elseif (in_array($userNdx, $i['members'] ?? [])) $enabled = 1;
			elseif (count($userGroups) && count(array_intersect($userGroups, $i['membersGroups'] ?? [])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$cals[$itemNdx] = $i;
			$cals[$itemNdx]['accessLevel'] = $enabled;
		}

    return $cals;
	}
}


/**
 * class ViewCals
 */
class ViewCals extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		if ($item['fullName'] === $item['shortName'])
		{
			$listItem ['t1'] = $item['fullName'];
		}
		else
		{
			$listItem ['t1'] = ['text' => $item['fullName'], 'suffix' => $item['shortName']];
		}

		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['order'])
			$props[] = ['text' => Utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT cals.* ';
		array_push ($q, ' FROM [wkf_events_cals] AS [cals]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' cals.[fullName] LIKE %s', '%'.$fts.'%',
				' OR cals.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[cals].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormCal
 */
class FormCal extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput('fullName');
					$this->addColumnInput('shortName');
					$this->addSeparator(self::coH4);
					$this->addList ('doclinks', '', self::loAddToFormLayout);
				$this->closeTab();
				$this->openTab ();
					/*$this->addColumnInput('usePerex');
					$this->addColumnInput('useImage');
					$this->addColumnInput('useLinkToUrl');
					$this->addColumnInput('usePersonsNotify');
					*/
					$this->addColumnInput('colorbg');
					$this->addColumnInput('icon');
					$this->addColumnInput('order');
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
