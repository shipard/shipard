<?php

namespace swdev\translation;


use \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TableDictsItemsTr
 * @package swdev\translation
 */
class TableDictsItemsTr extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.translation.dictsItemsTr', 'swdev_translation_dictsItemsTr', 'Přeložené položky slovníků');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		//$h ['info'][] = ['class' => 'title', 'value' => $recData ['identifier']];
		//$h ['info'][] = ['class' => 'info', 'value' => $recData ['description']];

		return $h;
	}
}


/**
 * Class FormDictItemTr
 * @package swdev\translation
 */
class FormDictItemTr extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$srcDictItemRecData = NULL;
		$srcText = '';
		if ($this->recData['dictItem'])
		{
			$srcDictItemRecData = $this->app()->db()->query('SELECT * FROM [swdev_translation_dictsItems] WHERE [ndx] = %i', $this->recData['dictItem'])->fetch();
			$srcDictRecData = $this->app()->db()->query('SELECT * FROM [swdev_translation_dicts] WHERE [ndx] = %i', $srcDictItemRecData['dict'])->fetch();
			if ($srcDictItemRecData)
			{
				$flagSrc = $this->app()->cfgItem ('swdev.tr.lang.langs.'.$srcDictRecData['srcLanguage'].'.flag', '');
				$flagDst = $this->app()->cfgItem ('swdev.tr.lang.langs.'.$this->recData['lang'].'.flag', '');
				$srcText = $srcDictItemRecData['text'];
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
					$this->addInputMemo('text', NULL);
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('lang');
					$this->addColumnInput ('dictItem');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
