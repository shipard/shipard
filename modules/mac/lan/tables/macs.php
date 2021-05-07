<?php

namespace mac\lan;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableMacs
 * @package mac\lan
 */
class TableMacs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.macs', 'mac_lan_macs', 'MAC adresy');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['mac']];

		return $h;
	}
}


/**
 * Class ViewMacs
 * @package mac\lan
 */
class ViewMacs extends TableView
{
	var $macs = [];
	var $macDevicesLabels = [];

	public function init ()
	{
		parent::init();
		//$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$this->macs[] = $item['mac'];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['mac'] = $item ['mac'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['mac'];
		//$listItem ['i1'] = ['text' => $item['id'], 'class' => 'id'];


		$listItem['i2'] = [];
		if (!utils::dateIsBlank($item['updated']))
			$listItem['i2'][] = ['text' => utils::datef($item['updated'], '%S %T'), 'icon' => 'icon-clock-o', 'class' => ''];
//		if (!utils::dateIsBlank($item['created']))
//			$listItem['i2'][] = ['text' => utils::datef($item['created'], '%S %T'), 'icon' => 'icon-play-circle-o', 'class' => 'e10-small'];


		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->macDevicesLabels [$item ['mac']]))
		{
			if (count($this->macDevicesLabels [$item ['mac']]) === 1)
			{
				$item['icon'] = $this->macDevicesLabels [$item ['mac']][0]['icon'];
				unset($this->macDevicesLabels [$item ['mac']][0]['icon']);
			}
			$item ['t2'] = $this->macDevicesLabels [$item ['mac']];
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [macs].* ';
		array_push($q, ' FROM [mac_lan_macs] AS [macs]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, 'macs.[mac] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

		array_push($q, ' ORDER BY [mac], [ndx]');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$mu = new \mac\lan\libs\MacsUtils($this->app());
		$info = $mu->loadMacs($this->macs, '');
		$this->macDevicesLabels = $info['labels'];
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Class FormMac
 * @package mac\lan
 */
class FormMac extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->readOnly = TRUE;

		$this->openForm ();
			$this->addColumnInput ('mac');
			$this->addColumnInput ('ports');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailMac
 * @package mac\lan
 */
class ViewDetailMac extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.DocumentCardMac');
	}

	public function createToolbar ()
	{
		return [];
	}
}

