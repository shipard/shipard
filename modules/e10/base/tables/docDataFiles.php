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

	protected function checkSpecialDocState($phase, $specialDocState, &$saveData)
	{
		if (!isset($saveData['recData']['ndx']) || !$saveData['recData']['ndx'])
			return FALSE;

		if ($phase === 2)
		{
			$ddfObject = $this->ddfObject($saveData['recData']);
			if ($ddfObject)
			{
				$ddfObject->checkFileContent();
			}

			return TRUE;
		}

		return TRUE;
	}

	public function createHeader($recData, $options)
	{
		$hdr = parent::createHeader($recData, $options);

		$ddfCfg = $this->app()->cfgItem('e10.ddf.formats.'.$recData['ddfId'], NULL);
		$attRecData = $this->app()->loadItem($recData['srcAttachment'], 'e10.base.attachments');

		$hdr ['info'][] = [
			'class' => 'title', 'value' => [
				['text' => $attRecData['filename'], 'class' => 'block'],
				['text' => '#' . $recData ['ndx'], 'class' => 'id pull-right'],
				['text' => $ddfCfg['fn'] ?? 'Neznámý formát', 'class' => 'block'],
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

		$personNdx = 0;
		if ($ddfObject)
			$personNdx = intval($ddfObject->impData['head']['person'] ?? 0);

		$this->openForm ();
		$tabs ['tabs'] = [];
		foreach ($contents as $ci)
			$tabs ['tabs'][] = ['text' => $ci['name'], 'icon' => $ci['icon']];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/iconSettings'];

			$this->addColumnInput ('ndx', self::coHidden);
			$this->openTabs ($tabs, TRUE);
				foreach ($contents as $ci)
				{
					$this->openTab();
						$this->addContent([$ci['content']]);
					$this->closeTab();
				}
				$this->openTab();
					$this->addViewerWidget ('e10doc.helpers.impDocsSettings', 'default', ['personNdx' => $personNdx], TRUE);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}

	public function createToolbar ()
	{
		if (!$this->readOnly)
			return parent::createToolbar();

		$b = [
			'type' => 'action', 'action' => 'saveform', 'text' => 'Znovu načíst', 'docState' => '99001', 'noclose' => 1,
			'style' => 'stateSave', 'stateStyle' => 'done', 'icon' => 'system/actionRegenerate', 'buttonClass' => 'btn-primary'
		];

		$toolbar [] = $b;

		$toolbar = array_merge ($toolbar, parent::createToolbar());
		return $toolbar;
	}
}
