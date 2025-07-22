<?php

namespace e10pro\reports\waste_cz;

use \Shipard\Table\DbTable, \Shipard\Viewer\TableViewGrid;


/**
 * class TableReturnRows
 */
class TableReturnRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.reports.waste_cz.returnRows', 'e10pro_reports_waste_cz_returnRows', 'Řádky hlášení o odpadech');
	}
}


/**
 * class ViewWasteReturnRows
 */
class ViewWasteReturnRows extends TableViewGrid
{
  var $wasteOpsTypes;

	public function init ()
	{
    $this->wasteOpsTypes = $this->app()->cfgItem('e10doc.waster.wasteOpsTypes');

		parent::init();

		//$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

		$g = [
      'opType' => '_Pohyb',
			'whc' => 'KN',
      'date' => ' Datum',
      'code' => '_Kód odpadu',
			'item' => 'Položka',
			'document' => 'Doklad',
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
		//$listItem ['id'] = $item['ndx'];

		$listItem ['opType'] = $item['wasteOpType'];
		$listItem ['whc'] = $item['wasteHandlingCode'];
		//$listItem ['date'] = $item['dateAccounting'];
		$listItem ['code'] = $item['wasteCodeText'];
		//$listItem ['item'] = $item['wasteCodeNameSrc'];
		//$listItem ['document'] = $item['document'];

		//if ($item['wasteCodeNomencDst'])
		//	$listItem ['codeDst'] = $item['wasteCodeTextDst'];

		if ($item['docNumber'])
			$listItem ['document'] = $item['docNumber'];

		/*
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
		*/
    $listItem ['date'] = $item['dateAccounting'];
    $listItem ['q'] = $item['quantity'];
    $listItem ['u'] = $item['unit'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push($q, 'SELECT [wrr].*,');
    array_push($q, ' [ni].[shortName] AS [wasteCodeName], [heads].[docNumber]');
    array_push($q, ' FROM [e10pro_reports_waste_cz_returnRows] AS [wrr]');
    array_push($q, ' LEFT JOIN [e10_base_nomencItems] AS [ni] ON [wrr].[wasteCodeNomenc] = [ni].[ndx]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] AS [heads] ON [wrr].[document] = [heads].[ndx]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{

			array_push($q, ' AND (');
			array_push($q,' [ni].[shortName] LIKE %s', '%'.$fts.'%');
			array_push($q,' OR [wrr].[wasteCodeText] LIKE %s', '%'.$fts.'%');
			array_push($q,' OR [wrr].[wasteHandlingCode] LIKE %s', '%'.$fts.'%');
			array_push($q, ')');

		}

		//$this->queryMain($q, 'wrr.', ['[date] DESC', '[ndx] DESC']);
		array_push($q, ' ORDER BY [dateAccounting] DESC, [ndx] DESC');
		array_push($q, $this->sqlLimit());
		$this->runQuery($q);
	}
}
