<?php

namespace e10\witems;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;
use \Shipard\Form\TableForm;
use \shipard\Table\DbTable;


/**
 * class TableBrands
 */
class TableBrands extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.brands', 'e10_witems_brands', 'Značky výrobků');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['shortName']];
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['fullName']];

		return $hdr;
	}
}


/**
 * class ViewBrands
 */
class ViewBrands extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'new', 'title' => 'Nové');
		$mq [] = array ('id' => 'archive', 'title' => 'Archív');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);
	} // init

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10_witems_brands] WHERE 1';

		// -- fulltext
		if ($dotaz != '')
			array_push ($q, " AND [fullName] LIKE %s", '%'.$dotaz.'%');

		// -- new
		if ($mainQuery == 'new')
			array_push ($q, " AND [docStateMain] = 0");

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		// -- archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND [docStateMain] = 5");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [shortName] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [shortName] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows


	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['i2'] = $item['homePage'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * class ViewDetailBrand
 */
class ViewDetailBrand extends TableViewDetail
{
	public function createDetailContent ()
	{
		$items = [];

		$q[] = 'SELECT * from [e10_witems_items] WHERE 1 ';
		array_push ($q, ' AND docState IN (1000, 4000, 8000) ');
		array_push ($q, ' AND brand = %i', $this->item['ndx']);
		array_push ($q, ' LIMIT 0, 304');

		$rows = $this->db()->query ($q);

		$cnt = 0;
		$pks = [];
		foreach ($rows as $r)
		{
			$item = ['t1' => $r['fullName'],
				'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $r['ndx']
			];

			$items[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
			$cnt++;
		}

		$atts = \E10\Base\getAttachments2 ($this->app(), 'e10.witems.items', $pks);
		foreach ($atts as $pk => $imgs)
		{
			$i = $imgs[0];
			$items[$pk]['coverImage'] = 'imgs/-w700/att/'.$i['path'].$i['filename'];
		}

		$title = ['icon' => 'e10-witems-items', 'text'=>'Položky'.' ('.$cnt.')'];
		$this->addContent (['pane' => 'e10-pane', 'title' => $title,
												'type' => 'tiles', 'tiles' => $items, 'class' => 'coverImages']);
	}
}


/**
 * class FormBrand
 */
class FormBrand extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

		$this->addColumnInput ("fullName");
		$this->addColumnInput ("shortName");
		$this->addColumnInput ("homePage");
		$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
		$this->openTabs ($tabs);

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}
}

