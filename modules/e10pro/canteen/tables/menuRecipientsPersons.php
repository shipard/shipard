<?php

namespace e10pro\canteen;
use \e10\TableView, \e10\DbTable, \e10\utils;


/**
 * Class TableMenuRecipientsPersons
 * @package e10pro\canteen
 */
class TableMenuRecipientsPersons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.menuRecipientsPersons', 'e10pro_canteen_menuRecipientsPersons', 'Menu k rozeslání');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'fullName', 'value' => $recData ['email']];

		return $hdr;
	}
}


/**
 * Class ViewMenuRecipientsPersons
 * @package e10pro\canteen
 */
class ViewMenuRecipientsPersons extends TableView
{
	var $usersCanteens;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$tableCanteens = $this->app()->table('e10pro.canteen.canteens');
		$this->usersCanteens = $tableCanteens->usersCanteens();

		$active = 1;
		forEach ($this->usersCanteens as $canteen)
		{
			$bt [] = [
				'id' => $canteen['ndx'], 'title' => $canteen['sn'], 'active' => $active,
				'addParams' => ['canteen' => $canteen['ndx']]
			];

			$active = 0;
		}
		if (count($this->usersCanteens) > 1)
			$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 0];

		$this->setBottomTabs ($bt);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['personName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];

		$props = [];
		$props[] = ['text' => $item['email'], 'icon' => 'icon-at', 'class' => 'label label-default'];
		if ($item['canteenName'])
			$props[] = ['text' => $item['canteenName'], 'icon' => 'system/iconCutlery', 'class' => 'label label-default'];
		if ($item['menuName'])
			$props[] = ['text' => $item['menuName'], 'icon' => 'icon-file-text-o', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['t2'] = $props;

		if ($item['disableSend'])
			$listItem ['i2'] = ['text' => 'Neodesílá se', 'class' => 'label label-default'];
		elseif (!$item['sent'])
			$listItem ['i2'] = ['text' => 'Čeká na odeslání', 'class' => 'label label-warning'];
		else
			$listItem ['i2'] = ['text' => 'Odesláno', 'suffix' => utils::datef($item['sentDate'], '%d, %T'), 'class' => 'label label-success'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$bt = intval($this->bottomTabId ());

		$q [] = 'SELECT recipients.*, persons.fullName AS personName, persons.id AS personId, canteens.shortName AS canteenName, menus.fullName AS menuName';
		array_push ($q, ' FROM [e10pro_canteen_menuRecipientsPersons] AS recipients');
		array_push ($q, ' LEFT JOIN e10pro_canteen_canteens AS canteens ON recipients.canteen = canteens.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON recipients.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10pro_canteen_menus AS menus ON recipients.menu = menus.ndx');
		array_push ($q, ' WHERE 1');

		if ($bt)
			array_push ($q, ' AND recipients.canteen = %i', $bt);
		else
		{
			if (count($this->usersCanteens))
				array_push($q, ' AND recipients.canteen IN %in', array_keys($this->usersCanteens));
			else
				array_push ($q, ' AND recipients.canteen = %i', -1);
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' recipients.[email] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR persons.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY recipients.[dateId] DESC, persons.[fullName], recipients.[ndx]', $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}

