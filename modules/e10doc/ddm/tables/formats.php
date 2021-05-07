<?php

namespace e10doc\ddm;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable, \e10\DataModel, \e10\json;


/**
 * Class TableFormats
 * @package e10doc\ddm
 */
class TableFormats extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.ddm.formats', 'e10doc_ddm_formats', 'Formáty vytěžování dat dokladů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['formatId']) && $recData['formatId'] === '')
		{
			$idFormula = '!12z';
			$recData['formatId'] = utils::createRecId($recData, $idFormula);
		}

		$cfg = $this->createConfiguration($recData);
		$recData['configuration'] = json::lint($cfg);
	}

	public function createConfiguration($recData)
	{
		$cfg = [
			'id' => $recData['formatId'], 'signatureRegExp' => $recData['signatureRegExp'],
			'items' => [],
		];

		$rows = $this->db()->query('SELECT * FROM [e10doc_ddm_formatsItems] ',
			' WHERE [format] = %i', $recData['ndx'],
			' AND [docState] < %i', 9000,
			' ORDER BY [itemType], [ndx]');

		foreach ($rows as $r)
		{
			$item = ['itemType' => $r['itemType']];
			if ($r['searchPrefix'] !== '')
				$item['searchPrefix'] = $r['searchPrefix'];
			if ($r['prefixIsRegExp'] !== 0)
				$item['prefixIsRegExp'] = $r['prefixIsRegExp'];
			if ($r['searchSuffix'] !== '')
				$item['searchSuffix'] = $r['searchSuffix'];
			if ($r['suffixIsRegExp'] !== 0)
				$item['suffixIsRegExp'] = $r['suffixIsRegExp'];
			if ($r['searchRegExp'] !== '')
				$item['searchRegExp'] = $r['searchRegExp'];

			$cfg['items'][] = $item;
		}

		return $cfg;
	}
}


/**
 * Class ViewFormats
 * @package e10doc\ddm
 */
class ViewFormats extends TableView
{
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
		$listItem ['i1'] = ['text' => $item['formatId'], 'class' => 'id'];

		$props = [];

		$listItem ['t2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [formats].* FROM [e10doc_ddm_formats] AS [formats]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [formats].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[formats].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormFormat
 * @package e10doc\ddm
 */
class FormFormat extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Formát', 'icon' => 'icon-filter'];
		$tabs ['tabs'][] = ['text' => 'Položky', 'icon' => 'icon-list'];
		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'icon-file-text'];
		$tabs ['tabs'][] = ['text' => 'CFG', 'icon' => 'icon-code'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('signatureRegExp');
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addViewerWidget ('e10doc.ddm.formatsItems', 'default', ['dstFormatNdx' => $this->recData['ndx']]);
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addInputMemo ('testText', NULL, self::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addInputMemo ('configuration', NULL, self::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
