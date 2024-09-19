<?php

namespace e10pro\vendms;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \e10\base\libs\UtilsBase;

/**
 * Class TableVendMs
 */
class TableVendMs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.vendms.vendms', 'e10pro_vendms_vendms', 'Prodejní automaty');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [e10pro_vendms_vendms] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$vendms = [];
		foreach ($rows as $r)
		{
			$vendm = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'],
				'title' => $r['title'],
				'allowAllUsers' => 1,
				'mqttBaseTopic' => $r['mqttBaseTopic'],
				'urlMachine' => $r['urlMachine'],
				'urlSetup' => $r['urlSetup'],
				'mqttTempTop' => $r['mqttTempTop'],
				'mqttTempBottom' => $r['mqttTempBottom'],
				'mqttRfid' => $r['mqttRfid'],
				'mqttBusy' => $r['mqttBusy'],
				'setupModeChipIds' => $r['setupModeChipIds'],
			];

			$vendms [$r['ndx']] = $vendm;
		}

		$cfg ['e10pro']['vendms']['vendms'] = $vendms;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.vendms.vendms.json', utils::json_lint (json_encode ($cfg)));
	}

	function usersVendMs ()
	{
		$allVendMS = $this->app()->cfgItem ('e10pro.vendms.vendms', NULL);
		return $allVendMS;
	}
}


/**
 * Class ViewVendMs
 */
class ViewVendMs extends TableView
{
	var $linkedPersons;

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
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		/*
		if (isset ($this->linkedPersons [$item ['pk']]))
		{
			$item ['t2'] = $this->linkedPersons [$item ['pk']];
		}
			*/
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_vendms_vendms]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}


	public function selectRows2 ()
	{
		/*
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks);
		*/
	}
}


/**
 * class FormVendMs
 */
class FormVendMs extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('title');
					$this->addColumnInput ('order');
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('mqttBaseTopic');
					$this->addColumnInput ('urlMachine');
					$this->addColumnInput ('urlSetup');
					$this->addColumnInput ('mqttTempTop');
					$this->addColumnInput ('mqttTempBottom');
					$this->addColumnInput ('mqttRfid');
					$this->addColumnInput ('mqttBusy');
					$this->addColumnInput ('setupModeChipIds');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
