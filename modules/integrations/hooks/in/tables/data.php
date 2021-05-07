<?php

namespace integrations\hooks\in;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableData
 * @package integrations\hooks\in
 */
class TableData extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.hooks.in.data', 'integrations_hooks_in_data', 'Příchozí Webhooks Data', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		/*
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		$url = '/hooks/'.$recData['urlPart1'].'/'.$recData['urlPart2'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $url];
		*/

		return $hdr;
	}
}


/**
 * Class ViewData
 * @package integrations\hooks\in
 */
class ViewData extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'done', 'title' => 'Hotovo'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'error', 'title' => 'Chybné'];

		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['hookName'];
		$listItem ['i1'] = ['text' => '#'.utils::nf($item['ndx']), 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];

		if ($item['dateCreate'])
			$props[] = ['text' => $item['dateCreate']->format('Y-m-d H:i:s'), 'class' => 'label label-default', 'icon' => 'icon-calendar'];

		$props[] = ['text' => $item['ipAddress'], 'class' => 'label label-default', 'icon' => 'icon-road'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [data].*, [hooks].fullName AS hookName';
		array_push ($q, ' FROM [integrations_hooks_in_data] AS [data]');
		array_push ($q, ' LEFT JOIN [integrations_hooks_in_hooks] AS [hooks] ON [data].[hook] = [hooks].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [data].[payload] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [data].[params] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		if ($mainQuery === 'active' || $mainQuery === '')
			array_push ($q, ' AND [data].[hookState] = %i', 0);
		elseif ($mainQuery === 'done')
			array_push ($q, ' AND [data].[hookState] = %i', 2);
		elseif ($mainQuery === 'error')
			array_push ($q, ' AND [data].[hookState] = %i', 9);

		array_push ($q, ' ORDER BY [ndx] DESC');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormData
 * @package integrations\hooks\in
 */
class FormData extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'webhook', 'icon' => 'icon-cloud-download'];
			$tabs ['tabs'][] = ['text' => 'Parametry', 'icon' => 'icon-th'];
			$tabs ['tabs'][] = ['text' => 'Data', 'icon' => 'icon-truck'];
			$tabs ['tabs'][] = ['text' => 'Protokol', 'icon' => 'icon-road'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput('hook');
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addInputMemo('params', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
				$this->openTab (self::ltNone);
					$this->addInputMemo('payload', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
				$this->openTab (self::ltNone);
					$this->addInputMemo('protocol', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailData
 * @package integrations\hooks\in
 */
class ViewDetailData extends TableViewDetail
{
}


