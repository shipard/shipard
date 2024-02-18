<?php

namespace e10doc\ddm;
use \Shipard\Table\DbTable, \Shipard\Form\TableForm, \Shipard\Viewer\TableView;


/**
 * Class TableDDMItems
 */
class TableDDMItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.ddm.ddmItems', 'e10doc_ddm_ddmItems', 'Položky vytěžování dat dokladů');
	}
}


/**
 * Class ViewDDMItems
 */
class ViewDDMItems extends \e10\TableViewGrid
{
	var $dstDDMNdx = 0;
	var $formatItemsTypes;

	/** @var \e10doc\ddm\TableDDM */
	var $tableDDM;
	var $ddmRecData =NULL;

	/** @var \e10doc\ddm\libs\DDMEngine */
	var $ddmEngine;

	public function init ()
	{
		parent::init();

		$this->formatItemsTypes = $this->app()->cfgItem ('e10doc.ddm.ddmItemsTypes');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		//$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;
		$this->fullWidthToolbar = TRUE;

		$g = [
			'item' => 'Položka',
			'search' => 'Hledat',
			'found' => 'Nalezeno',
		];
		$this->setGrid ($g);

		$this->setMainQueries ();

		$this->dstDDMNdx = intval($this->queryParam('dstDDMNdx'));
		$this->addAddParam('ddm', $this->dstDDMNdx);

		$this->tableDDM = $this->app()->table('e10doc.ddm.ddm');
		$this->ddmRecData = $this->tableDDM->loadItem($this->dstDDMNdx);
		$this->ddmEngine = new \e10doc\ddm\libs\DDMEngine($this->app());
		$this->ddmEngine->setSrcText($this->ddmRecData['testText']);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [items].* ';
		array_push ($q, ' FROM [e10doc_ddm_ddmItems] AS [items]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [items].[ddm] = %i', $this->dstDDMNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [items].fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[items].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$fit = isset($this->formatItemsTypes[$item['itemType']]) ? $this->formatItemsTypes[$item['itemType']] : NULL;

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['item'] = [];
		if ($fit)
		{
			$l = ['text' => $fit['fn'], 'class' => 'e10-bold block'];
			$listItem ['item'][] = $l;
			if ($item['fullName'] !== '')
				$listItem ['item'][] = ['text' => $item['fullName'], 'class' => 'e10-off e10-small'];
		}
		else
			$listItem ['item'][] = ['text' => $item['fullName'], 'class' => 'e10-off'];

		$listItem ['search'] = [];
		if ($item['searchPrefix'] !== '')
			$listItem ['search'][] = ['text' => $item['searchPrefix'], 'class' => 'label label-default'];

		if ($item['searchSuffix'] !== '')
			$listItem ['search'][] = ['text' => $item['searchSuffix'], 'class' => 'label label-default'];

		$res = $this->ddmEngine->testOne($item);


		$listItem ['found'] = strval($res);//json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

		return $listItem;
	}
}


/**
 * class FormDDMItem
 */
class FormDDMItem extends TableForm
{
	public function renderForm ()
	{
		$itemTypeCfg = $this->app()->cfgItem('e10doc.ddm.ddmItemsTypes.'.$this->recData['itemType'], NULL);
		$itemTypeDataType = ($itemTypeCfg) ? ($itemTypeCfg['type'] ?? '') : '';

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('itemType');

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('searchPrefix');
			$this->addColumnInput ('searchSuffix');
			if ($itemTypeDataType === 'date')
				$this->addColumnInput ('dateFormat');
		$this->closeForm ();
	}
}
