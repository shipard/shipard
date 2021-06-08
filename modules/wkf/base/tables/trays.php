<?php

namespace wkf\base;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableTrays
 * @package e10pro\wkf
 */
class TableTrays extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.trays', 'wkf_base_trays', 'Přihrádky na přílohy', 1248);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
	}

	public function saveConfig ()
	{
		$list = [];
		$shipardEmails = [];

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_base_trays] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $rec)
		{
			$r = $rec->toArray();
			$tray = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'icon' => $r['icon']];

			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($tray, 'members', 'e10.persons.persons', 'wkf-trays-members', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($tray, 'membersGroups', 'e10.persons.groups', 'wkf-trays-members', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($tray, 'admins', 'e10.persons.persons', 'wkf-trays-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($tray, 'adminsGroups', 'e10.persons.groups', 'wkf-trays-admins', $r ['ndx']);

			$tray['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$list [$r['ndx']] = $tray;

			$sei = $r['shipardEmailId'];
			if ($sei !== '')
			{
				$shipardEmails[$sei] = ['type' => 'tray', 'dstNdx' => $r['ndx'], 'id' => $sei, 'title' => $sei.': '.$r['fullName'].' (přihrádka)'];
			}
		}

		// -- save to file
		$cfg ['wkf']['trays'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_wkf.base.trays.json', utils::json_lint (json_encode ($cfg)));

		// -- shipard emails
		if (count($shipardEmails))
		{
			$cfgShipardEmails ['wkf']['shipardEmails'] = $shipardEmails;
			file_put_contents(__APP_DIR__ . '/config/_wkf.trays.shipardEmails.json', utils::json_lint (json_encode ($cfgShipardEmails)));
		}
	}

	function saveConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
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

	public function XXXXgetDocumentLockState ($recData, $form = NULL)
	{
		$lock = parent::getDocumentLockState ($recData, $form);
		if ($lock !== FALSE)
			return $lock;

		if (isset ($recData['shipardEmailId']) && $recData['shipardEmailId'] != '')
		{
			$exist = $this->db()->query('SELECT [ndx] FROM [wkf_base_trays] WHERE [shipardEmailId] = %s', $recData['shipardEmailId'],
				' AND [ndx] != %i', $recData['ndx'], ' AND [docState] != %i', 9800);
			if ($exist)
				return ['mainTitle' => 'Doklad je uzamčen', 'subTitle' => 'Fiskální období '.$fiscalYear['fullName'].' je uzavřeno'];
		}

		return FALSE;
	}

	function usersTrays()
	{
		$trays = [];

		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$allTrays = $this->app()->cfgItem ('wkf.trays', NULL);
		if ($allTrays === NULL)
			return [];

		foreach ($allTrays as $item)
		{
			$enabled = 0;
			if ($item['allowAllUsers']) $enabled = 1;
			elseif (isset($item['members']) && in_array($userNdx, $item['members'])) $enabled = 1;
			elseif (isset($item['membersGroups']) && count($userGroups) && count(array_intersect($userGroups, $item['membersGroups'])) !== 0) $enabled = 1;
			elseif (isset($item['admins']) && in_array($userNdx, $item['admins'])) $enabled = 1;
			elseif (isset($item['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $item['adminsGroups'])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$trays[$item['ndx']] = $item;
		}

		return $trays;
	}
}


/**
 * Class ViewTrays
 * @package e10pro\wkf
 */
class ViewTrays extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		//$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['shipardEmailId'] !== '')
			$props [] = ['icon' => 'icon-at', 'text' => $item ['shipardEmailId'], 'class' => 'label label-default'];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [wkf_base_trays]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%',
				' OR [shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTray
 * @package e10pro\wkf
 */
class FormTray extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('shipardEmailId');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}

	public function validNewDocumentState ($newDocState, $saveData)
	{
		if ($newDocState === 9800 || $newDocState === 8000)
			return parent::validNewDocumentState($newDocState, $saveData);

		if (isset ($saveData['recData']['shipardEmailId']) && $saveData['recData']['shipardEmailId'] !== '')
		{
			// -- system ids
			if (in_array($saveData['recData']['shipardEmailId'], ['scan', 'outbox', 'note', 'documents']))
			{
				$this->setColumnState('shipardEmailId', utils::es ('Hodnota není povolena - je vyhražena pro systémové účely'));
				return FALSE;
			}

			// -- trays
			$exist = $this->app()->db()->query('SELECT [ndx], [fullName] FROM [wkf_base_trays] WHERE [shipardEmailId] = %s', $saveData['recData']['shipardEmailId'],
				' AND [ndx] != %i', isset($saveData['recData']['ndx']) ? $saveData['recData']['ndx'] : 0, ' AND [docState] != %i', 9800)->fetch();
			if ($exist)
			{
				$this->setColumnState('shipardEmailId', utils::es ('Hodnota'." '".$this->columnLabel($this->table->column ('shipardEmailId'), 0)."' ".' už existuje v přihrádce '.$exist['fullName']));
				return FALSE;
			}

			// -- sections
			$exist = $this->app()->db()->query('SELECT [ndx], [fullName] FROM [wkf_base_sections] WHERE [shipardEmailId] = %s', $saveData['recData']['shipardEmailId'],
				' AND [docState] != %i', 9800)->fetch();
			if ($exist)
			{
				$this->setColumnState('shipardEmailId', utils::es ('Hodnota'." '".$this->columnLabel($this->table->column ('shipardEmailId'), 0)."' ".' už existuje v sekci '.$exist['fullName']));
				return FALSE;
			}

		}

		return parent::validNewDocumentState($newDocState, $saveData);
	}
}


/**
 * Class ViewDetailTray
 * @package e10pro\wkf
 */
class ViewDetailTray extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentAttachments($this->item['ndx']);
	}
}
