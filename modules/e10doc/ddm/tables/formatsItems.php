<?php

namespace e10doc\ddm;
use e10\DbTable, e10\TableForm, \e10\TableView, e10\str;


/**
 * Class TableFormatsItems
 * @package e10doc\ddm
 */
class TableFormatsItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.ddm.formatsItems', 'e10doc_ddm_formatsItems', 'Položky formátů vytěžování dat dokladů');
	}
}


/**
 * Class ViewFormatsItems
 * @package e10doc\ddm
 */
class ViewFormatsItems extends \e10\TableViewGrid
{
	var $dstFormatNdx = 0;
	var $formatItemsTypes;

	/** @var \e10doc\ddm\TableFormats */
	var $tableFormats;
	var $formatRecData =NULL;

	/** @var \e10doc\ddm\libs\Engine */
	var $ddmEngine;

	public function init ()
	{
		parent::init();

		$this->formatItemsTypes = $this->app()->cfgItem ('e10doc.ddm.formatItemsTypes');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		//$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;
		$this->fullWidthToolbar = TRUE;

		$g = [
			'item' => 'Položka',
			'search' => 'Hledat',
			'found' => '_Nalezeno',
		];
		$this->setGrid ($g);

		$this->setMainQueries ();

		$this->dstFormatNdx = intval($this->queryParam('dstFormatNdx'));
		$this->addAddParam('format', $this->dstFormatNdx);

		$this->tableFormats = $this->app()->table('e10doc.ddm.formats');
		$this->formatRecData = $this->tableFormats->loadItem($this->dstFormatNdx);
		$this->ddmEngine = new \e10doc\ddm\libs\Engine($this->app());
		$this->ddmEngine->setSrcText($this->formatRecData['testText']);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [items].* ';
		array_push ($q, ' FROM [e10doc_ddm_formatsItems] AS [items]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [items].[format] = %s', $this->dstFormatNdx);

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

		if ($item['searchRegExp'] !== '')
			$listItem ['search'][] = ['text' => $item['searchRegExp'], 'class' => 'block e10-bg-t9 pl1 pr1'];
		elseif ($fit && isset($fit['re']))
			$listItem ['search'][] = ['text' => $fit['re'], 'class' => 'block e10-me e10-bg-t9 pl1 pr1'];

		if ($item['searchSuffix'] !== '')
			$listItem ['search'][] = ['text' => $item['searchSuffix'], 'class' => 'label label-default'];

		$res = $this->ddmEngine->testOne($item);

		if (isset($res[0]))
			$listItem ['found'] = json_encode($res[0], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

		return $listItem;
	}
}


/**
 * Class FormFormatItem
 * @package e10doc\ddm
 */
class FormFormatItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('itemType');
			$this->addColumnInput ('fullName');

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('searchPrefix');
			$this->addColumnInput('prefixIsRegExp', self::coRight);

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('searchSuffix');
			$this->addColumnInput('suffixIsRegExp', self::coRight);

			$this->addSeparator(self::coH3);
			$this->addColumnInput ('searchRegExp');
			$this->addColumnInput ('searchRegExpFlags');
		$this->closeForm ();
	}
}
