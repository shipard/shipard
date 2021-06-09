<?php

namespace swdev\dm;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableEnumsValuesTr
 * @package swdev\dm
 */
class TableEnumsValuesTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.enumsValuesTr', 'swdev_dm_enumsValuesTr', 'Přeložené texty hodnot Enumů');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['text']];
	//	$h ['info'][] = ['class' => 'info', 'value' => $recData ['value']];

		return $h;
	}
}


/**
 * Class FormEnumValueTr
 * @package swdev\dm
 */
class FormEnumValueTr extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$srcDictItemRecData = NULL;
		$srcText = '';
		if ($this->recData['enumValue'])
		{
			$srcEnumValueRecData = $this->app()->db()->query('SELECT * FROM [swdev_dm_enumsValues] WHERE [ndx] = %i', $this->recData['enumValue'])->fetch();
			$srcEnumRecData = $this->app()->db()->query('SELECT * FROM [swdev_dm_enums] WHERE [ndx] = %i', $srcEnumValueRecData['enum'])->fetch();
			if ($srcEnumValueRecData)
			{
				$flagSrc = $this->app()->cfgItem ('swdev.tr.lang.langs.'.$srcEnumRecData['srcLanguage'].'.flag', '');
				$flagDst = $this->app()->cfgItem ('swdev.tr.lang.langs.'.$this->recData['lang'].'.flag', '');
				$srcText = $srcEnumValueRecData['text'];
			}
		}

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addStatic([['text' => 'Přeložit z '.$flagSrc, 'class' => 'block padd5']]);
					$this->addStatic([['text' => $srcText, 'class' => 'block e10-bg-t9 padd5']]);
					$this->addStatic([['text' => 'do '.$flagDst, 'class' => 'block padd5']]);
					$this->addColumnInput('text');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('lang');
					$this->addColumnInput ('enum');
					$this->addColumnInput ('enumValue');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
