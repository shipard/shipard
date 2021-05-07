<?php

namespace e10\base;

use \e10\TableForm, \e10\utils, \e10\DbTable;


/**
 * Class TableDocDataFiles
 * @package e10\base
 */
class TableDocDataFiles extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10.base.docDataFiles', 'e10_base_docDataFiles', 'Datové soubory dokumentů');
	}

	public function createHeader($recData, $options)
	{
		$hdr = parent::createHeader($recData, $options);

		$hdr ['info'][] = [
			'class' => 'title', 'value' => [
				['text' => 'TEST'],
				['text' => '#' . $recData ['ndx'], 'class' => 'id pull-right'],
			]
		];

		return $hdr;
	}

	public function ddfObject($ddfRecData, $ddfNdx = 0)
	{
		if ($ddfNdx)
			$ddfRecData = $this->loadItem($ddfNdx);

		$ddfCfg = $this->app()->cfgItem('e10.ddf.formats.'.$ddfRecData['ddfId'], NULL);
		if (!$ddfCfg)
			return NULL;

		/** @var \lib\docDataFiles\DocDataFile $o */
		$o = $this->app()->createObject($ddfCfg['classId']);
		if (!$o)
		{
			return NULL;
		}

		$o->init();
		$o->setRecData($ddfRecData);

		return $o;
	}
}


/**
 * Class FormDocDataFile
 * @package e10\base
 */
class FormDocDataFile extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		/** @var \lib\docDataFiles\DocDataFile $ddfObject */
		$ddfObject = $this->table->ddfObject($this->recData);
		$contents = ($ddfObject) ? $ddfObject->createContents() : [];

		$this->openForm ();
		$tabs ['tabs'] = [];
			foreach ($contents as $ci)
				$tabs ['tabs'][] = ['text' => $ci['name'], 'icon' => $ci['icon']];
		//$tabs ['tabs'][] = ['text' => 'TEST', 'icon' => 'icon-paperclip'];

			$this->addColumnInput ('ndx', self::coHidden);
			$this->openTabs ($tabs, TRUE);
				foreach ($contents as $ci)
				{
					$this->openTab();
						$this->addContent([$ci['content']]);
					$this->closeTab();
				}
			$this->closeTabs();
		$this->closeForm ();
	}
}
