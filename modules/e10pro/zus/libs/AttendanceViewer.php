<?php

namespace e10pro\zus\libs;
use \Shipard\Viewer\TableViewGrid;



/**
 * class AttendanceViewer
 */
class AttendanceViewer extends TableViewGrid
{
	public $mainGroup = 0;
	public $properties = array();
	var $classification = [];
	public $addresses = array();
	protected $loadAddresses = FALSE;
	protected $searchInProperties = TRUE;
	protected $searchInBA = 0;
	protected $showValidity = TRUE;
	protected $ftsInArchive = TRUE;

  var $periodBegin = NULL;
  var $periodEnd = NULL;

	var $workingHoursKinds;



	public function init ()
	{
		$this->workingHoursKinds = $this->app()->cfgItem('e10pro.emps.workingHoursKinds', NULL);

		$this->periodBegin = $this->queryParam ('periodBegin');
    $this->periodEnd = $this->queryParam ('periodEnd');

		parent::init();

		$this->enableDetailSearch = TRUE;
    $this->type = 'form';

    $this->fullWidthToolbar = TRUE;
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->objectSubType = self::vsMain;
		$this->linesWidth = 33;
		//$this->setPanels (self::sptQuery);

		$g = [
      'person' => 'Osoba',
      'whk' => 'Druh',
		];

		$this->setGrid ($g);
	}

	public function selectRows ()
	{
		$this->checkFastSearch ();
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT wh.*,');
		array_push ($q, ' persons.fullName AS personName');
		array_push ($q, ' FROM [e10pro_emps_workingHours] AS wh');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON wh.person = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND (wh.validFrom IS NULL OR wh.validFrom <= %d', $this->periodBegin, ')');
		array_push ($q, ' AND (wh.validTo IS NULL OR wh.validTo >= %d', $this->periodEnd, ')');

		/*
    array_push ($q, ' AND EXISTS (',
      'SELECT ndx FROM e10pro_emps_workingHours AS ewh WHERE persons.ndx = ewh.person',
      ' AND (ewh.validFrom IS NULL OR ewh.validFrom <= %d', $this->periodBegin, ')',
      ' AND (ewh.validTo IS NULL OR ewh.validTo >= %d', $this->periodEnd, ')',
      ')');
		*/

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, '[persons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

/*
		array_push($q,' AND (itemset.validFrom IS NULL OR itemset.validFrom <= %d', $this->docRecData['dateAccounting'], ')');
		array_push($q,' AND (itemset.validTo IS NULL OR itemset.validTo >= %d', $this->docRecData['dateAccounting'], ')');
*/

    array_push ($q, ' ORDER BY [persons].[lastName], [persons].[firstName] ');
    array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['person'] = $item['personName'];

		$whk = $this->workingHoursKinds[$item['workingHoursKind']] ?? NULL;
		if ($whk)
			$listItem ['whk'] = $whk['sn'];


		//$listItem ['i1'] = array ('text' => '#'.$item['id'], 'class' => 'id');

	//	$listItem ['t2'] = [];

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}
}

