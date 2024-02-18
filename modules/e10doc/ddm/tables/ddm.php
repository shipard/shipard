<?php

namespace e10doc\ddm;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm;
use \Shipard\Table\DbTable, \Shipard\Application\DataModel;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;



/**
 * Class TableDDM
 */
class TableDDM extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.ddm.ddm', 'e10doc_ddm_ddm', 'Vytěžování dat dokladů');
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
			$recData['formatId'] = Utils::createRecId($recData, $idFormula);
		}

		$cfg = $this->createConfiguration($recData);
		$recData['configuration'] = Json::lint($cfg);
	}

	public function createConfiguration($recData)
	{
		$cfg = [
      'name' => $recData['fullName'],
			'id' => $recData['formatId'],
      'signatureString' => $recData['signatureString'],
			'items' => [],
		];

		$rows = $this->db()->query('SELECT * FROM [e10doc_ddm_ddmItems] ',
			' WHERE [ddm] = %i', $recData['ndx'],
			' AND [docState] < %i', 9000,
			' ORDER BY [itemType], [ndx]');

		foreach ($rows as $r)
		{
      $itemTypeCfg = $this->app()->cfgItem('e10doc.ddm.ddmItemsTypes.'.$r['itemType'], NULL);
      $itemTypeDataType = ($itemTypeCfg) ? ($itemTypeCfg['type'] ?? '') : '';

			$item = ['itemType' => $r['itemType']];
			if ($r['searchPrefix'] !== '')
				$item['searchPrefix'] = $r['searchPrefix'];
			if ($r['searchSuffix'] !== '')
				$item['searchSuffix'] = $r['searchSuffix'];

      if ($itemTypeDataType === 'date')
        $item['dateFormat'] = $r['dateFormat'];

			$cfg['items'][] = $item;
		}

		return $cfg;
	}
}


/**
 * class ViewDDMs
 */
class ViewDDMs extends TableView
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

		$q = [];
    array_push ($q, 'SELECT [ddms].*');
    array_push ($q, ' FROM [e10doc_ddm_ddm] AS [ddms]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [ddms].[fullName] LIKE %s', '%'.$fts.'%');
      array_push ($q,' OR [ddms].[testText] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[ddms].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormDDM
 */
class FormDDM extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Položky', 'icon' => 'system/iconList'];
		$tabs ['tabs'][] = ['text' => 'Zkušební text', 'icon' => 'user/fileText'];
		$tabs ['tabs'][] = ['text' => 'Konfigrace', 'icon' => 'user/code'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('signatureString');
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addViewerWidget ('e10doc.ddm.ddmItems', 'default', ['dstDDMNdx' => $this->recData['ndx']]);
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addInputMemo ('testText', NULL, self::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
				$this->openTab (self::ltNone);
          $this->addContent([['pane' => 'padd5', 'type' => 'text', 'subtype' => 'code', 'paneTitle' => '', 'text' => $this->recData['configuration']]], self::coFullSizeY);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
