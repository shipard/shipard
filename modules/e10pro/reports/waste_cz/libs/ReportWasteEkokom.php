<?php

namespace e10pro\reports\waste_cz\libs;


use \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;


/**
 * class ReportWasteEkokom
 */
class ReportWasteEkokom extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $calendarYear = 0;

  var $itemsGroups = [];
  var $data = [];

  public function init ()
	{
    $today = Utils::today();
    $defaultYear = 'Y'.(intval($today->format('Y')) - 1);
    $this->addParam ('calendarMonth', 'calendarPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'defaultValue' => $defaultYear]);

		parent::init();

    if (!$this->periodBegin)
    {
      $cpBegin = $this->reportParams ['calendarPeriod']['values'][$this->reportParams ['calendarPeriod']['value']];
      if (isset($cpBegin['dateBegin']))
        $this->periodBegin = Utils::createDateTime($cpBegin['dateBegin']);
      elseif ($cpBegin['calendarYear'] !== 0)
        $this->periodBegin = Utils::createDateTime(substr($cpBegin['calendarYear'], 1).'-01-01');

      if (isset($cpBegin['dateEnd']))
        $this->periodEnd = Utils::createDateTime($cpBegin['dateEnd']);
      elseif ($cpBegin['calendarYear'] !== 0)
        $this->periodEnd = Utils::createDateTime(substr($cpBegin['calendarYear'], 1).'-12-31');

      if (is_string($cpBegin['calendarYear']) && $cpBegin['calendarYear'][0] === 'Y')
        $this->calendarYear = intval(substr($cpBegin['calendarYear'], 1));

      $this->setInfo('icon', 'reportMonthlyReport');
      $this->setInfo('param', 'Období', $this->reportParams ['calendarPeriod']['activeTitle']);
    }
    else
    {
      $this->periodBegin = Utils::createDateTime($this->periodBegin);
      $this->periodEnd = Utils::createDateTime($this->periodEnd);
    }
  }

  function createContent ()
	{
    $this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'overview': $this->createContent_Overview (); break;
		}
	}

  public function loadData()
  {
    parent::loadData();

    $this->loadData_ItemsGroups();
    $this->loadData_In();
    $this->loadData_Out();
    $this->loadData_Out_ByPersons();
    $this->loadData_InitStates();
  }

  public function createContent_Overview()
  {
    $this->createContent_InitStates();
    $this->createContent_In();
    $this->createContent_Out();

		$this->setInfo('title', 'Ekokom');
    $this->setInfo('note', '1', 'Všechna množství jsou v tunách');
  }

  public function createContent_In()
  {
		$data = [];
		forEach ($this->data['IN'] as $groupNdx => $groupData)
    {
      $item = [
        'groupId' => $this->itemsGroups[$groupNdx]['fullName'],
        'sumQuantity' => round($groupData['sumQuantity'] / 1000, 3),
      ];
      $data[] = $item;
    }

		$h = [
      'groupId' => 'Komodita',
      'sumQuantity' => '+Množství',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Vstup příjem', 'params' => ['precision' => 3]]);

		$this->setInfo('title', 'Příjem od občanů');
    $this->setInfo('note', '1', 'Všechna množství jsou v tunách');
  }

  public function createContent_Out()
  {
		$data = [];
		forEach ($this->data['OUT_BP'] as $groupData)
    {
      $personOid = $this->loadPersonOid ($groupData['personNdx']);

      $item = [
        'groupId' =>  $groupData['groupId'],
        'personOid' => $personOid,
        'personName' => $groupData['personName'],
        'sumQuantity' => round($groupData['sumQuantity'] / 1000, 3),
      ];
      $data[] = $item;
    }

		$h = [
      'personName' => 'Název odběratele',
      'personOid' => 'IČO odběratele',
      'personType' => 'Typ odběratele',
      'groupId' => 'Komodita',
      'sumQuantity' => '+Množství',
      'useType' => 'Způsob využití',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Výstup odběratelé', 'params' => ['precision' => 3]]);


    // -- summary
		$data = [];
		forEach ($this->data['OUT'] as $groupNdx => $groupData)
    {
      $item = [
        'groupId' => $this->itemsGroups[$groupNdx]['fullName'],
        'sumQuantity' => round($groupData['sumQuantity'] / 1000, 3),
      ];
      $data[] = $item;
    }

		$h = [
      'groupId' => 'Komodita',
      'sumQuantity' => '+Množství',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Výstup odběratelé CELKEM', 'params' => ['precision' => 3]]);
  }

  public function createContent_InitStates()
  {
		$data = [];
		forEach ($this->data['IS'] as $groupNdx => $groupData)
    {
      $item = [
        'groupId' => $this->itemsGroups[$groupNdx]['fullName'],
        'sumQuantity' => round(($groupData['sumQuantityIS'] + $groupData['sumQuantityIn'] - $groupData['sumQuantityOut']) / 1000, 3),
        'sumQuantityIn' => round($groupData['sumQuantityIn'] / 1000, 3),
        'sumQuantityOut' => round($groupData['sumQuantityOut'] / 1000, 3),
        'sumQuantityIS' => round($groupData['sumQuantityIS'] / 1000, 3),
      ];
      $data[] = $item;
    }

		$h = [
      'groupId' => 'Komodita',
      'sumQuantityIS' => '+PS 1.1.',
      'sumQuantityIn' => '+Příjem',
      'sumQuantityOut' => '+Výdej',
      'sumQuantity' => '+Množství',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Vstup sklad', 'params' => ['precision' => 3]]);
  }

  protected function loadData_In()
  {
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $this->data['IN'][$groupNdx] = [
        'fullName' => $groupCfg['fullName'],
        'sumQuantity' => 0.0,
      ];

      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit]');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'purchase');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      if ($this->periodBegin)
        array_push ($q, ' AND [heads].[dateAccounting] >= %d', $this->periodBegin);
      if ($this->periodEnd)
        array_push ($q, ' AND [heads].[dateAccounting] <= %d', $this->periodEnd);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');
        $this->data['IN'][$groupNdx]['sumQuantity'] += $r['sumQuantity'] * $ucc;
      }
    }
  }

  protected function loadData_Out()
  {
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $this->data['OUT'][$groupNdx] = [
        'fullName' => $groupCfg['fullName'],
        'sumQuantity' => 0.0,
      ];

      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit]');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'invno');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      if ($this->periodBegin)
        array_push ($q, ' AND [heads].[dateAccounting] >= %d', $this->periodBegin);
      if ($this->periodEnd)
        array_push ($q, ' AND [heads].[dateAccounting] <= %d', $this->periodEnd);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');
        $this->data['OUT'][$groupNdx]['sumQuantity'] += $r['sumQuantity'] * $ucc;
      }
    }
  }

  protected function loadData_Out_ByPersons()
  {
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit], [heads].[person] AS headPerson,');
      array_push($q, ' [persons].[fullName] AS personName');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'invno');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      if ($this->periodBegin)
        array_push ($q, ' AND [heads].[dateAccounting] >= %d', $this->periodBegin);
      if ($this->periodEnd)
        array_push ($q, ' AND [heads].[dateAccounting] <= %d', $this->periodEnd);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2, 3');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $iid = $groupNdx.'_'.$r['headPerson'];

        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');

        if (!isset($this->data['OUT_BP'][$iid]))
        {
          $this->data['OUT_BP'][$iid] = [
            'groupId' => $groupCfg['fullName'],
            'sumQuantity' => 0.0,
            'personNdx' => $r['headPerson'],
            'personName' => $r['personName'],
          ];
        }

        $this->data['OUT_BP'][$iid]['sumQuantity'] += $r['sumQuantity'] * $ucc;
      }
    }
  }

  protected function loadData_InitStates()
  {
    $limitBegin = $this->periodBegin->format('Y').'-01-01';

    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $this->data['IS'][$groupNdx] = [
        'fullName' => $groupCfg['fullName'],
        'sumQuantityIn' => 0.0, 'sumQuantityOut' => 0.0, 'sumQuantityIS' => 0.0,
      ];
    }

    // -- STOCK INIT STATES
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit]');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'stockinst');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push($q, ' AND [heads].[dateAccounting] <= %d', $this->periodBegin);
      array_push($q, ' AND [heads].[dateAccounting] >= %d', $limitBegin);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');
        $this->data['IS'][$groupNdx]['sumQuantityIS'] += $r['sumQuantity'] * $ucc;
      }
    }

    // -- OUT
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit]');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'invno');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push($q, ' AND [heads].[dateAccounting] < %d', $this->periodBegin);
      array_push($q, ' AND [heads].[dateAccounting] >= %d', $limitBegin);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');
        $this->data['IS'][$groupNdx]['sumQuantityOut'] += $r['sumQuantity'] * $ucc;
      }
    }

    // -- IN
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit]');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'purchase');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push($q, ' AND [heads].[dateAccounting] < %d', $this->periodBegin);
      array_push($q, ' AND [heads].[dateAccounting] >= %d', $limitBegin);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');
        $this->data['IS'][$groupNdx]['sumQuantityIn'] += $r['sumQuantity'] * $ucc;
      }
    }
  }

  protected function loadData_ItemsGroups()
  {
    $q = [];
    array_push($q, 'SELECT * FROM e10_witems_itemsGroups');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND docState != %i', 9000);
    array_push($q, ' AND (validFrom IS NULL OR validFrom <= %d)', $this->periodEnd);
    array_push($q, ' AND (validTo IS NULL OR validTo >= %d)', $this->periodBegin);
    array_push($q, ' ORDER BY [order], [fullName]');
    $rows = $this->app->db()->query($q);
    foreach ($rows as $r)
    {
      $this->itemsGroups[$r['ndx']] = $r->toArray();
    }
  }

  public function loadPersonOid ($personNdx)
	{
    $personOid = '';

		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$personOid = trim($r['valueString']);
      break;
		}

    return $personOid;
	}

  public function subReportsList ()
	{
		$d[] = ['id' => 'overview', 'icon' => 'system/iconFile', 'title' => 'Přehled'];

		return $d;
	}
}
