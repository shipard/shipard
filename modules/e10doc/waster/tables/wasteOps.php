<?php

namespace e10doc\waster;

use \Shipard\Viewer\TableViewGrid, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableWasteOps
 */
class TableWasteOps extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.waster.wasteOps', 'e10doc_waster_wasteOps', 'Pohyby odpadů');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
    parent::checkBeforeSave($recData, $ownerData);

    $recData['wasteCodeTextSrc'] = '';
    $ni = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [ndx] = %i', $recData['wasteCodeNomencSrc'])->fetch();
    if ($ni)
      $recData['wasteCodeTextSrc'] = $ni['itemId'];

    $recData['wasteCodeTextDst'] = '';
    $ni = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [ndx] = %i', $recData['wasteCodeNomencDst'])->fetch();
    if ($ni)
      $recData['wasteCodeTextDst'] = $ni['itemId'];
  }

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2($recData);

		$wre = new \e10pro\reports\waste_cz\libs\WasteReturnEngine($this->app);
		$wre->resetWasteOp($recData['ndx']);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		return $hdr;
	}

	public function getDocumentLockState ($recData, $form = NULL)
	{
		$lock = parent::getDocumentLockState ($recData, $form);
		if ($lock !== FALSE)
			return $lock;

		if (isset ($recData['generated']) && $recData['generated'])
			return ['mainTitle' => 'Uzamčeno', 'subTitle' => 'Automaticky generované pohyby nelze upravovat'];

		return FALSE;
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);
		$recData ['generated'] = 0;
		return $recData;
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		$opDate = Utils::createDateTime($form->recData['date']);
		if (!$opDate)
			return TRUE;

		if ($columnId === 'wasteHandlingCodeSrc')
		{
			if (isset($cfgItem['validFrom']) && $opDate < Utils::createDateTime($cfgItem['validFrom']))
				return FALSE;
			if (isset($cfgItem['validTo']) && $opDate > Utils::createDateTime($cfgItem['validTo']))
				return FALSE;

			if ($form->recData['opType'] == 0 && intval($cfgItem['dir'] ?? 0) != 2) // init states
				return FALSE;
			if ($form->recData['opType'] == 1 && intval($cfgItem['dir'] ?? 0) != 3) // end states
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}
}


/**
 * class ViewWasteOps
 */
class ViewWasteOps extends TableViewGrid
{
  var $wasteOpsTypes;

	public function init ()
	{
    $this->wasteOpsTypes = $this->app()->cfgItem('e10doc.waster.wasteOpsTypes');

		parent::init();

		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

		$g = [
      'id' => 'ID',
      'opType' => '_Pohyb',
      'ec' => '_EK',
      'date' => ' Datum',
      'codes' => '_Kód odpadu',
      'q' => ' Množství',
      'u' => 'Jedn.',
      'codesInfo' => 'Pozn.',
		];

		$this->setGrid ($g);

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['id'] = $item['ndx'];

		$opIcon = ($item['generated']) ? 'system/iconCogs' : 'system/iconUser';
    $listItem ['opType'] = ['text' => $this->wasteOpsTypes[$item['opType']]['sn'], 'icon' => $opIcon];

    if ($item['opType'] === 2)
    {
      $listItem ['codes'] = $item['wasteCodeTextSrc'].' ▶︎ '.$item['wasteCodeTextDst'];
      $listItem ['codesInfo'] = $item['wasteCodeNameSrc'].' ▶︎ '.$item['wasteCodeNameDst'];
      $listItem ['ec'] = $item['wasteHandlingCodeSrc'].' ▶︎ '.$item['wasteHandlingCodeDst'];
    }
    else
    {
      $listItem ['codes'] = $item['wasteCodeTextSrc'];
      $listItem ['codesInfo'] = $item['wasteCodeNameSrc'];
      $listItem ['ec'] = $item['wasteHandlingCodeSrc'];
    }

    $listItem ['date'] = $item['date'];
    $listItem ['q'] = $item['quantity'];
    $listItem ['u'] = $item['unit'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push($q, 'SELECT [wo].*,');
    array_push($q, ' [niSrc].[shortName] AS [wasteCodeNameSrc], [niDst].[shortName] AS [wasteCodeNameDst]');
    array_push($q, ' FROM [e10doc_waster_wasteOps] AS [wo]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS [niSrc] ON [wo].[wasteCodeNomencSrc] = [niSrc].[ndx]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS [niDst] ON [wo].[wasteCodeNomencDst] = [niDst].[ndx]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q,' [niSrc].[shortName] LIKE %s', '%'.$fts.'%');
      array_push($q,' OR [niDst].[shortName] LIKE %s', '%'.$fts.'%');
			array_push($q,' OR [wo].[wasteCodeTextSrc] LIKE %s', '%'.$fts.'%');
			array_push($q,' OR [wo].[wasteCodeTextDst] LIKE %s', '%'.$fts.'%');
			array_push($q,' OR [wo].[wasteHandlingCodeSrc] LIKE %s', '%'.$fts.'%');
			array_push($q,' OR [wo].[wasteHandlingCodeDst] LIKE %s', '%'.$fts.'%');
			array_push($q, ')');
		}

		$this->queryMain($q, 'wo.', ['[date] DESC', '[ndx] DESC']);
		$this->runQuery($q);
	}
}


/**
 * class FormWasteOp
 */
class FormWasteOp extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
      $this->addColumnInput ('opType');
			$this->addColumnInput ('date');
      $this->addSeparator(self::coH4);
      $this->addColumnInput ('wasteCodeNomencSrc');
      $this->openRow();
        $this->addColumnInput ('quantity');
        $this->addColumnInput ('unit');
      $this->closeRow();
      $this->addColumnInput ('wasteHandlingCodeSrc');
      if ($this->recData['opType'] == 2)
      {
        $this->addColumnInput ('wasteCodeNomencDst');
        $this->addColumnInput ('wasteHandlingCodeDst');
      }

			$this->addSeparator(self::coH4);
			$this->addColumnInput ('excludeFromStatesCheck');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailWasteOp
 */
class ViewDetailWasteOp extends TableViewDetail
{
}
