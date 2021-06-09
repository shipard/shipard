<?php

namespace services\sw;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableSWQueue
 * @package services\sw
 */
class TableSWQueue extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.sw.swQueue', 'services_sw_swQueue', 'SW informace ke zpracování');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['title']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//if ($recData['osInfo'])
		//	return 'icon-windows';

		return parent::tableIcon ($recData, $options);
	}

}


/**
 * Class ViewSWQueue
 * @package services\sw
 */
class ViewSWQueue extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		//$listItem ['i1'] = ['text' => '#'.$item['ndx'].';'.substr($item['checksum'], 0, 3).'...'.substr($item['checksum'],-3), 'class' => 'id'];
		$listItem ['t1'] = $item['title'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		$props[] = [
			'text' => utils::datef($item['dateCreate'], '%S %T'), 'class' => 'label label-default', 'icon' => 'system/actionPlay'
		];

		if ($item['cntSameAsOriginal'])
			$props[] = [
				'text' => utils::datef($item['dateSameAsOriginal'], '%S %T'), 'class' => 'label label-default', 'icon' => 'icon-repeat',
				'prefix' => $item['cntSameAsOriginal'].'x',
			];

		$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [swQueue].* ';
		array_push ($q, ' FROM [services_sw_swQueue] AS [swQueue]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [swQueue].[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [swQueue].[data] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'swQueue.', ['[title]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSWQueue
 * @package services\sw
 */
class FormSWQueue  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Data', 'icon' => 'system/iconCogs'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('title');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSWQueue
 * @package services\sw
 */
class ViewDetailSWQueue extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.sw.dc.SWQueue');
	}
}

