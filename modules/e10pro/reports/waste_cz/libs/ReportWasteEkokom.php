<?php

namespace e10pro\reports\waste_cz\libs;


use \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;


/**
 * class ReportWasteEkokom
 */
class ReportWasteEkokom extends \e10doc\core\libs\reports\GlobalReport
{
  var $firstYear = 2024;

  var $periods = [];
  var $activePeriodId = '';
  var $showDetails = 0;
  var $periodBegin = NULL;
  var $periodEnd = NULL;

  var $calendarYear = 0;
  var $calendarQuarter = 0;

  var $itemsGroups = [];
  var $data = [];

  public function init ()
	{
    $today = Utils::today();
    $lastYear = intval($today->format('Y'));
    $defYear = intval($today->format('Y') - 1);

    // --year param
    $yearEnum = [];
    for ($i = $this->firstYear; $i <= $lastYear; $i++)
      $yearEnum[$i] = strval($i);
    $this->addParam ('switch', 'calendarYear', ['title' => 'Rok', 'switch' => $yearEnum, 'defaultValue' => $defYear]);

    // -- quarters param
    $quatersEnum = ['1' => '1Q', '2' => '2Q', '3' => '3Q', '4' => '4Q'];
    $this->addParam ('switch', 'calendarQuarter', ['title' => 'Čtvrtletí', 'switch' => $quatersEnum, 'defaultValue' => '1', 'radioBtn' => 1]);

    // -- details param
    $detailsEnum = ['1' => 'Ano', '0' => 'Ne'];
    $this->addParam ('switch', 'details', ['title' => 'Detailně', 'switch' => $detailsEnum, 'defaultValue' => '1', 'radioBtn' => 1]);

		parent::init();

    $this->calendarYear = intval($this->reportParams ['calendarYear']['value']);
    $this->calendarQuarter = intval($this->reportParams ['calendarQuarter']['value']);
    $this->activePeriodId = $this->calendarYear.'-'.$this->calendarQuarter;

    $this->showDetails = intval($this->reportParams ['details']['value']);

    $this->setInfo('icon', 'reportMonthlyReport');
  }

  function createContent ()
	{
    $this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'overview': $this->createContent_Overview (); break;
      case 'purchases': $this->createContent_Purchases (); break;
      case 'invoices': $this->createContent_Invoices (); break;
		}
	}

  public function loadData()
  {
    parent::loadData();

    $this->loadData_ItemsGroups();
    $this->loadData_AllPeriods();

    if ($this->subReportId === 'purchases')
    {
      $this->loadData_Purchases($this->periods[$this->activePeriodId]);
    }
    if ($this->subReportId === 'invoices')
    {
      $this->loadData_Invoices($this->periods[$this->activePeriodId]);
    }

    $this->setInfo('param', 'Období', $this->calendarYear.' / '.$this->calendarQuarter.'Q'.' ('.Utils::datef($this->periods[$this->activePeriodId]['periodBegin']).' - '.Utils::datef($this->periods[$this->activePeriodId]['periodEnd']).')');
  }

  public function loadData_AllPeriods()
  {
    $prevPeriodId = '';
    for($year = $this->firstYear; $year <= $this->calendarYear; $year++)
    {
      for ($quarter = 1; $quarter <= 4; $quarter++)
      {
        if ($year === $this->calendarYear && $quarter > $this->calendarQuarter)
          break;

        $periodId = $year.'-'.$quarter;
        $periodBegin = Utils::createDateTime($year.'-'.(($quarter - 1) * 3 + 1).'-01');
        $periodEnd = Utils::createDateTime($year.'-'.($quarter * 3).'-'.$periodBegin->format('t'));

        $period = [
          'id' => $periodId,
          'year' => $year,
          'quarter' => $quarter,
          'periodBegin' => $periodBegin,
          'periodEnd' => $periodEnd,
          'prevPeriodId' => $prevPeriodId,
        ];

        $this->periods[$periodId] = $period;
        $prevPeriodId = $periodId;
      }

      // -- load periods
      foreach ($this->periods as $periodId => $period)
      {
        $this->loadData_InitStates($periodId);
        $this->loadData_In($period);
        $this->loadData_Out($period);
        $this->loadData_Out_ByPersons($period);
      }
    }
  }

  public function createContent_Overview()
  {
    $this->createContent_InitStates();
    $this->createContent_In();
    $this->createContent_Out();

		$this->setInfo('title', 'EKOKOM');
    $this->setInfo('note', '1', 'Všechna množství jsou v tunách');
  }

  public function createContent_In()
  {
		$data = [];
		forEach ($this->data[$this->activePeriodId]['IN'] as $groupNdx => $groupData)
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
    $title = 'Vstup příjem';
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => $title, 'params' => ['precision' => 3]]);
  }

  public function createContent_Out()
  {
		$data = [];
		forEach ($this->data[$this->activePeriodId]['OUT_BP'] as $groupData)
    {
      $personOid = $this->loadPersonOid ($groupData['personNdx']);

      $item = [
        'groupId' =>  $groupData['groupId'],
        'personOid' => $personOid,
        'personName' => $groupData['personName'],
        'sumQuantity' => round($groupData['sumQuantity'] / 1000, 3),
        'sumQuantityAccepted' => round($groupData['sumQuantityAccepted'] / 1000, 3),
      ];
      $data[] = $item;
    }

    if ($this->showDetails)
      $h = [
        'personName' => 'Název odběratele',
        'personOid' => 'IČO odb.',
        'groupId' => 'Komodita',
        'sumQuantity' => '+Prodej CELKEM',
        'sumQuantityAccepted' => '+Množ. UZNANÉ',
      ];
    else
      $h = [
        'personName' => 'Název odběratele',
        'personOid' => 'IČO odběratele',
        'personType' => 'Typ odběratele',
        'groupId' => 'Komodita',
        'sumQuantityAccepted' => '+Množství',
        'useType' => 'Způsob využití',
      ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Výstup odběratelé', 'params' => ['precision' => 3]]);


    // -- summary
		$data = [];
		forEach ($this->data[$this->activePeriodId]['OUT'] as $groupNdx => $groupData)
    {
      $item = [
        'groupId' => $this->itemsGroups[$groupNdx]['fullName'],
        'sumQuantity' => round($groupData['sumQuantity'] / 1000, 3),
        'sumQuantityBuy' => round($groupData['sumQuantityBuy'] / 1000, 3),
        'sumQuantityIn' => round($groupData['sumQuantityIn'] / 1000, 3),
        'sumQuantityIS' => round($groupData['sumQuantityIS'] / 1000, 3),
        'sumQuantityAccepted' => round($groupData['sumQuantityAccepted'] / 1000, 3),
        'acceptedRatio' => strval($groupData['acceptedRatio']),
      ];
      $data[] = $item;
    }

    if ($this->showDetails)
      $h = [
        'groupId' => 'Komodita',
        'sumQuantity' => '+Prodej CELKEM',
        'sumQuantityBuy' => '+Nákup OBČANÉ',
        'sumQuantityIS' => '+Sklad',
        'sumQuantityIn' => '+Vstup OBČANÉ + Sklad',
        'sumQuantityAccepted' => '+Množství UZNANÉ',
        'acceptedRatio' => ' Poměr',
      ];
    else
      $h = [
        'groupId' => 'Komodita',
        'sumQuantityAccepted' => '+Množství',
      ];

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Výstup odběratelé CELKEM', 'params' => ['precision' => 3]]);
  }

  public function createContent_InitStates()
  {
		$data = [];
		forEach ($this->data[$this->activePeriodId]['IS'] as $groupNdx => $groupData)
    {
      $item = [
        'groupId' => $this->itemsGroups[$groupNdx]['fullName'],
        'initState' => round($groupData['initState'] / 1000, 3),
      ];
      $data[] = $item;
    }

		$h = [
      'groupId' => 'Komodita',
      'initState' => '+Množství',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'title' => 'Vstup sklad', 'params' => ['precision' => 3]]);
  }

  public function createContent_Purchases()
  {
		$data = [];
		forEach ($this->data[$this->activePeriodId]['PURCHASES'] as $groupNdx => $groupData)
    {
      $data = [];
      foreach ($groupData as $doc)
      {
        $data[] = $doc;
      }

      $h = [
        '#' => '#',
        'docNumber' => ' Doklad',
        'docDate' => ' Datum',
        'item' => ' Položka',
        'rowText' => 'Popis',
        'quantity' => '+Množství kg',
      ];
      $this->addContent (['type' => 'table', 'header' => $h, 'table' => $data,
      'title' => 'Výkupy: '.$this->itemsGroups[$groupNdx]['fullName'],
      'params' => ['precision' => 2, 'tableClass' => 'pageBreakAfter']]);
    }

    $this->setInfo('title', 'EKOKOM - Přehled výkupů');
  }

  public function createContent_Invoices()
  {
		$data = [];
		forEach ($this->data[$this->activePeriodId]['INVOICES'] as $groupNdx => $groupData)
    {
      $data = [];
      foreach ($groupData as $doc)
      {
        $data[] = $doc;
      }

      $h = [
        '#' => '#',
        'docNumber' => ' Doklad',
        'docDate' => ' Datum',
        'personName' => 'Odběratel',
        'item' => ' Položka',
        'rowText' => 'Popis',
        'quantity' => '+Množství kg',
      ];
      $this->addContent (['type' => 'table', 'header' => $h, 'table' => $data,
      'title' => 'Faktury: '.$this->itemsGroups[$groupNdx]['fullName'],
      'params' => ['precision' => 2, 'tableClass' => 'pageBreakAfter']]);
    }

    $this->setInfo('title', 'EKOKOM - Přehled faktur');
  }

  protected function loadData_In($period)
  {
    $periodId = $period['id'];
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $this->data[$periodId]['IN'][$groupNdx] = [
        'fullName' => $groupCfg['fullName'],
        'sumQuantity' => 0.0,
      ];

      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit]');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [rowType] = %i', 0);
      array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'purchase');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push($q, ' AND [heads].[dateAccounting] >= %d', $period['periodBegin']);
      array_push($q, ' AND [heads].[dateAccounting] <= %d', $period['periodEnd']);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');
        $this->data[$periodId]['IN'][$groupNdx]['sumQuantity'] += $r['sumQuantity'] * $ucc;
      }
    }
  }

  protected function loadData_Out($period)
  {
    $periodId = $period['id'];
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $this->data[$periodId]['OUT'][$groupNdx] = [
        'fullName' => $groupCfg['fullName'],
        'sumQuantity' => 0.0,
        'sumQuantityAccepted' => 0.0,
        'sumQuantityBuy' => $this->data[$periodId]['IN'][$groupNdx]['sumQuantity'],
        'sumQuantityIn' => $this->data[$periodId]['IN'][$groupNdx]['sumQuantity'] + $this->data[$periodId]['IS'][$groupNdx]['initState'],
        'sumQuantityIS' => $this->data[$periodId]['IS'][$groupNdx]['initState'],
        'acceptedRatio' => 1,
      ];

      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit]');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [rowType] = %i', 0);
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'invno');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push($q, ' AND [heads].[dateAccounting] >= %d', $period['periodBegin']);
      array_push($q, ' AND [heads].[dateAccounting] <= %d', $period['periodEnd']);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');
        $this->data[$periodId]['OUT'][$groupNdx]['sumQuantity'] += $r['sumQuantity'] * $ucc;
        $this->data[$periodId]['OUT'][$groupNdx]['sumQuantityAccepted'] += $r['sumQuantity'] * $ucc;
      }
    }

    foreach ($this->data[$periodId]['OUT'] as $groupNdx => $groupData)
    {
      if ($groupData['sumQuantityAccepted'] > $groupData['sumQuantityIn'])
      {
        $this->data[$periodId]['OUT'][$groupNdx]['sumQuantityAccepted'] = $groupData['sumQuantityIn'];
        $this->data[$periodId]['OUT'][$groupNdx]['acceptedRatio'] = $groupData['sumQuantityIn'] / $groupData['sumQuantityAccepted'];
      }
    }
  }

  protected function loadData_Out_ByPersons($period)
  {
    $periodId = $period['id'];
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $q = [];
      array_push($q, 'SELECT SUM([rows].quantity) AS sumQuantity, [rows].[unit], [heads].[person] AS headPerson,');
      array_push($q, ' [persons].[fullName] AS personName');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [rowType] = %i', 0);
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'invno');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push ($q, ' AND [heads].[dateAccounting] >= %d', $period['periodBegin']);
      array_push ($q, ' AND [heads].[dateAccounting] <= %d', $period['periodEnd']);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' GROUP BY 2, 3');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $iid = $groupNdx.'_'.$r['headPerson'];

        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');

        if (!isset($this->data[$periodId]['OUT_BP'][$iid]))
        {
          $this->data[$periodId]['OUT_BP'][$iid] = [
            'groupId' => $groupCfg['fullName'],
            'groupNdx' => $groupNdx,
            'sumQuantity' => 0.0,
            'sumQuantityAccepted' => 0.0,
            'acceptedRatio' => 1,
            'personNdx' => $r['headPerson'],
            'personName' => $r['personName'],
          ];
        }

        $this->data[$periodId]['OUT_BP'][$iid]['sumQuantity'] += $r['sumQuantity'] * $ucc;
        $this->data[$periodId]['OUT_BP'][$iid]['sumQuantityAccepted'] += $r['sumQuantity'] * $ucc;
      }
    }

    foreach ($this->data[$periodId]['OUT_BP'] as $iid => $iidData)
    {
      $totalDataOut = $this->data[$periodId]['OUT'][$iidData['groupNdx']];
      if ($totalDataOut['acceptedRatio'] == 1)
        continue;

      $this->data[$periodId]['OUT_BP'][$iid]['sumQuantityAccepted'] = round($iidData['sumQuantity'] * $totalDataOut['acceptedRatio'], 3);
    }
  }

  protected function loadData_InitStates($periodId)
  {
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $this->data[$periodId]['IS'][$groupNdx] = [
        'fullName' => $groupCfg['fullName'],
        'sumQuantityIn' => 0.0,
        'sumQuantityOut' => 0.0,
        'sumQuantityIS' => 0.0,
        'initState' => 0.0,
      ];

      $prevPeriodId = $this->periods[$periodId]['prevPeriodId'];
      if ($prevPeriodId === '')
        continue;

      $in = $this->data[$prevPeriodId]['IN'][$groupNdx]['sumQuantity'] + $this->data[$prevPeriodId]['IS'][$groupNdx]['initState'];
      $out = $this->data[$prevPeriodId]['OUT'][$groupNdx]['sumQuantity'];

      if ($out < $in)
        $this->data[$periodId]['IS'][$groupNdx]['initState'] = $in - $out;
    }
  }

  protected function loadData_Purchases($period)
  {
    $periodId = $period['id'];
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $q = [];
      array_push($q, 'SELECT [rows].[quantity], [rows].[unit], [rows].[item], [rows].[text] AS rowText, [rows].[document] AS docNdx,');
      array_push($q, ' [heads].[docNumber], [heads].[dateAccounting], [heads].[person] AS headPerson, [persons].[fullName] AS personName,');
      array_push($q, ' [items].[id] AS itemId');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' LEFT JOIN e10_witems_items AS [items] ON [rows].[item] = [items].[ndx]');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [rowType] = %i', 0);
      array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'purchase');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push($q, ' AND [heads].[dateAccounting] >= %d', $period['periodBegin']);
      array_push($q, ' AND [heads].[dateAccounting] <= %d', $period['periodEnd']);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' ORDER BY [heads].[docNumber], [rows].[ndx]');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');

        $item = [
          'docNumber' => ($this->showDetails) ? ['text' => $r['docNumber'], 'docAction' => 'edit', 'pk' => $r['docNdx'], 'table' => 'e10doc.core.heads'] : $r['docNumber'],
          'docDate' => $r['dateAccounting'],
          'item' => $r['itemId'],
          'personName' => $r['personName'],
          'rowText' => $r['rowText'],

          'groupId' => $groupNdx,
          'quantity' => $r['quantity'] * $ucc,
        ];

        $this->data[$periodId]['PURCHASES'][$groupNdx][] = $item;
      }
    }
  }

  protected function loadData_Invoices($period)
  {
    $periodId = $period['id'];
    foreach ($this->itemsGroups as $groupNdx => $groupCfg)
    {
      $q = [];
      array_push($q, 'SELECT [rows].[quantity], [rows].[unit], [rows].[item], [rows].[text] AS rowText, [rows].[document] AS docNdx,');
      array_push($q, ' [heads].[docNumber], [heads].[dateAccounting], [heads].[person] AS headPerson, [persons].[fullName] AS personName,');
      array_push($q, ' [items].[id] AS itemId');
      array_push($q, ' FROM e10doc_core_rows AS [rows]');
      array_push($q, ' LEFT JOIN e10doc_core_heads AS [heads] ON [rows].document = [heads].ndx');
      array_push($q, ' LEFT JOIN e10_persons_persons AS [persons] ON [heads].person = [persons].ndx');
      array_push($q, ' LEFT JOIN e10_witems_items AS [items] ON [rows].[item] = [items].[ndx]');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [rowType] = %i', 0);
      //array_push($q, ' AND [persons].personType = %i', 1); // citizens
      array_push($q, ' AND [heads].[docType] = %s', 'invno');
      array_push($q, ' AND [heads].[docState] = %s', 4000);
      array_push($q, ' AND [heads].[dateAccounting] >= %d', $period['periodBegin']);
      array_push($q, ' AND [heads].[dateAccounting] <= %d', $period['periodEnd']);

      array_push($q, ' AND EXISTS (',
                     ' SELECT 1 FROM e10_witems_itemsGroupsItems WHERE [item] = [rows].[item] AND itemsGroup = %i', $groupNdx,
                     ')');
      array_push($q, ' ORDER BY [heads].[docNumber], [rows].[ndx]');
      $rows = $this->app->db()->query($q);
      foreach ($rows as $r)
      {
        $ucc = E10Utils::unitsConversionCoefficient($this->app(), $r['unit'], 'kg');

        $item = [
          'docNumber' => ($this->showDetails) ? ['text' => $r['docNumber'], 'docAction' => 'edit', 'pk' => $r['docNdx'], 'table' => 'e10doc.core.heads'] : $r['docNumber'],
          'docDate' => $r['dateAccounting'],
          'item' => $r['itemId'],
          'personName' => $r['personName'],
          'rowText' => $r['rowText'],

          'groupId' => $groupNdx,
          'quantity' => $r['quantity'] * $ucc,
        ];

        $this->data[$periodId]['INVOICES'][$groupNdx][] = $item;
      }
    }
  }

  protected function loadData_ItemsGroups()
  {
    $q = [];
    array_push($q, 'SELECT * FROM e10_witems_itemsGroups');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND docState != %i', 9000);
    //array_push($q, ' AND (validFrom IS NULL OR validFrom <= %d)', $this->periodEnd);
    //array_push($q, ' AND (validTo IS NULL OR validTo >= %d)', $this->periodBegin);
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
		$d[] = ['id' => 'purchases', 'icon' => 'docTypeRedemptions', 'title' => 'Výkupy'];
    $d[] = ['id' => 'invoices', 'icon' => 'docType/invoicesOut', 'title' => 'Faktury'];

		return $d;
	}
}
