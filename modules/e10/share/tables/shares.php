<?php

namespace e10\share;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableShares
 */
class TableShares extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.share.shares', 'e10_share_shares', 'Sdílení');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['id']) || $recData ['id'] === '')
			$recData ['id'] = base_convert (mt_rand(1000000, 999999999), 10, 36).base_convert (time() / mt_rand(9, 999), 10, 36).base_convert (mt_rand(1000000, 999999999), 10, 36);

		if (!isset ($recData ['dateCreate']) || self::dateIsBlank ($recData ['dateCreate']))
			$recData ['dateCreate'] = new \DateTime();

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}
}


/**
 * class ViewShares
 */
class ViewShares extends TableView
{
	public function init ()
	{
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];

		$mq [] = ['id' => 'archive', 'title' => 'Archív'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $item['id'];
		$listItem ['i2'] = Utils::datef ($item['dateCreate'], '%d, %T');

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q[] = 'SELECT * FROM [e10_share_shares] as shares WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([name] LIKE %s OR [id] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND shares.[docStateMain] < 4");

		// -- archive
		if ($mainQuery === 'archive')
			array_push ($q, ' AND shares.[docStateMain] = 5');

		// -- trash
		if ($mainQuery === 'trash')
			array_push ($q, ' AND shares.[docStateMain] = 4');

		array_push($q, ' ORDER BY [dateCreate] DESC, [ndx] DESC');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailShare
 */
class ViewDetailShare extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$item = $this->item;
		//$this->addContentAttachments($this->item['ndx']);
	}
}


/**
 * class FormShare
 */
class FormShare extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addColumnInput('name');
			$this->addColumnInput('id');
		$this->closeForm ();
	}
}

