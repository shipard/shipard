<?php

namespace hosting\core\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;


/**
 * class InvoicingGroupDSEngine
 */
class InvoicingGroupDSEngine extends Utility
{
	var \hosting\core\TableDataSources $tableDataSources;
	var $partnerNdx = 0;
	var $invoicingGroupNdx = 0;
	var $disableEdit = FALSE;

	var $data = [];
  var $sumTotals;
  var $allPks = [];

  var $print = 0;
  var $firstPart = 1;

	function init ()
	{
		$this->tableDataSources = $this->app()->table('hosting.core.dataSources');
	}

  public function setInvoicingGroup($invoicingGroupNdx)
  {
    $this->invoicingGroupNdx = $invoicingGroupNdx;
  }

  function loadData()
	{
    //$this->loadData_AllDSPks();

    $this->sumTotals = ['usageTotal' => 0, 'cntCashRegs12m' => 0, 'cntDocuments12m' => 0, 'priceTotal' => 0];

    $this->loadDataPart([
      'title' => 'Databáze v ostrém provozu',
      'headerClass' => 'e10-bg-t7',
      'query' => ['invoicingTo' => 4, 'dsType' => 0, 'dsDemo' => 0, 'condition' => 2]
    ]);

    /*
    $this->loadDataPart([
      'title' => 'Databáze fakturované přímo zákazníkovi',
      'subTitle' => 'Poskytujete podporu, ale faktura za provoz jde zákazníkovi od nás',
      'headerClass' => 'e10-bg-t1',
      'query' => ['invoicingTo' => 1, 'dsType' => 0, 'dsDemo' => 0]
    ]);
    */
    if (count($this->data))
    {
      $this->sumTotals['_options'] = ['class' => 'sumtotal pageBreakAfter', 'beforeSeparator' => 'separator', 'colSpan' => ['dsid' => 2]];
      $oldUsageTotal = $this->sumTotals['usageTotal'];
      $this->sumTotals['usageTotal'] = Utils::memf($this->sumTotals['usageTotal']);
      $this->sumTotals['dsid'] = 'Celkem za fakturované databáze:';
      $this->data [] = $this->sumTotals;
      $this->sumTotals['usageTotal'] = $oldUsageTotal;
    }

    if ($this->print)
      $this->firstPart = 1;

    $this->loadDataPart([
      'title' => 'Databáze v ostrém provozu BEZ FAKTURACE',
      //'subTitle' => '',
      'headerClass' => 'e10-bg-t6',
      'query' => ['invoicingTo' => 3, 'dsType' => 0, 'dsDemo' => 0, 'condition' => 2]
    ]);


    $this->loadDataPart([
      'title' => 'DEMO a testovací databáze',
      'subTitle' => 'Databáze pro prezentace, studium, nebo na hraní',
      'headerClass' => 'e10-bg-t4',
      'query' => ['invoicingTo' => 3, 'dsType' => 0, 'dsDemo' => 1]
    ]);

    $this->loadDataPart([
      'title' => 'Zkušební databáze',
      'subTitle' => 'Obvykle se jedná o kopie ostrých databází k testovacím účelům',
      'headerClass' => 'e10-bg-t9',
      'query' => ['dsType' => 1]
    ]);

    $this->loadDataPart([
      'title' => 'Databáze v archívu',
      'subTitle' => 'Již nepoužívané databáze v režimu "pouze pro čtení"',
      'headerClass' => 'e10-bg-t2',
      'query' => ['dsType' => 0, 'condition' => 4]
    ]);

    $this->loadDataPart([
      'title' => 'Ostatní',
      'subTitle' => 'Tady by nemělo nic být - prosím kontaktujte nás...',
      'headerClass' => 'e10-bg-t3',
      'partType' => 'others'
    ]);

  }

  function loadDataPart(array $partDef)
	{
		$q = [];
    array_push($q, 'SELECT stats.*, ');
		array_push($q, ' ds.ndx AS dsNdx, ds.name as dsName, ds.shortName as dsShortName, ds.gid, ds.invoicingTo,');
    array_push($q, ' partners.name as partnerName');
    array_push($q, ' FROM hosting_core_dataSources AS ds');
		array_push($q, ' LEFT JOIN hosting_core_dsStats AS stats ON ds.ndx = stats.dataSource');
    array_push($q, ' LEFT JOIN hosting_core_partners AS partners ON ds.partner = partners.ndx');
		array_push($q, ' WHERE ds.docState IN %in', [4000, 8000]);
		if ($this->partnerNdx)
			array_push($q, ' AND ds.partner = %i', $this->partnerNdx);
    if ($this->invoicingGroupNdx)
			array_push($q, ' AND ds.invoicingGroup = %i', $this->invoicingGroupNdx);

    if (isset($partDef['query']))
    {
      foreach ($partDef['query'] as $colId => $colVal)
        array_push($q, ' AND ds.['.$colId.'] = %i', $colVal);
    }
    elseif (isset($partDef['partType']) && $partDef['partType'] === 'others')
    {
      array_push($q, ' AND ds.[ndx] NOT IN %in', $this->allPks);
    }

    array_push($q, ' ORDER BY ds.name');



    $data = [];
    $totals = ['usageTotal' => 0, 'cntCashRegs12m' => 0, 'cntDocuments12m' => 0];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$plan = $this->tableDataSources->getPlan($r);

			$item = [
				'dsid' => ($this->disableEdit) ? ['text' => substr($r['gid'], 0, 4).'...'.substr($r['gid'], -4)] : [
					'text' => substr($r['gid'], 0, 3).'.'.substr($r['gid'], -3),
					'docAction' => 'edit', 'pk' => $r['dataSource'], 'table' => 'hosting.core.dataSources'
				],
				'name' => (($r['dsShortName'] === '') ? $r['dsName'] : $r['dsShortName'])/*.json_encode($r)*/,
				'partner' => $r['partnerName'],
				'usageDb' => Utils::memf($r['usageDb']),
				'usageFiles' => Utils::memf($r['usageFiles']),
				'usageTotal' => Utils::memf($r['usageTotal']),
				//'cntIssues12m' => $r['cntIssues12m'],
				'cntDocuments12m' => $r['cntDocuments12m'] - $r['cntCashRegs12m'],
				'cntDocumentsAll' => $r['cntDocumentsAll'],
				'cntCashRegs12m' => $r['cntCashRegs12m'],
				//'cntUsersAll1m' => $r['cntUsersAll1m'],
				//'extModulesPoints' => $plan['extModulesPoints'],
				'plan' => $plan['title'],

				//'extModulesPrice' => $plan['extModulesPrice'],
        'priceDocs' => $plan['priceDocs'],
        //'priceUsage' => $plan['extraCharges']['total'],

				'priceTotal' => $plan['priceTotal'],
			];

			if ($plan['extraCharges']['total'])
			{
				//$item['priceUsage'] = ['text' => Utils::nf($plan['priceUsage']), 'prefix' => $plan['priceUsageLegend'].' ='];

        $item['priceUsage'] = $plan['extraCharges']['total'];
        $item['extraCharges'] = [];

        foreach ($plan['extraCharges']['charges'] as $ech)
        {
          $item['extraCharges'][] = ['text' => utils::nf($ech['price']), 'suffix' => '('.$ech['legend'].')', 'class' => 'break e10-small', 'icon' => $ech['icon']];
        }
			}

      if (isset($partDef['headerClass']))
        $item['_options']['cellClasses']['#'] = $partDef['headerClass'];

      $totals['usageTotal'] += $r['usageTotal'];
			$totals['cntDocuments12m'] += $r['cntDocuments12m'];
      $totals['cntCashRegs12m'] += $r['cntCashRegs12m'];
      $totals['priceDocs'] += $item['priceDocs'] ?? 0;
      $totals['priceUsage'] += $item['priceUsage'] ?? 0;
      $totals['priceTotal'] += $item['priceTotal'] ?? 0;

      $this->sumTotals['usageTotal'] += $r['usageTotal'] ?? 0;
			$this->sumTotals['cntDocuments12m'] += $r['cntDocuments12m'];
      $this->sumTotals['cntCashRegs12m'] += $r['cntCashRegs12m'];
      $this->sumTotals['priceDocs'] += $item['priceDocs'] ?? 0;
      $this->sumTotals['priceUsage'] += $item['priceUsage'] ?? 0;
      $this->sumTotals['priceTotal'] += $item['priceTotal'] ?? 0;

      $this->allPks[] = $r['dsNdx'];

			$data[] = $item;
    }

    if (count($data))
    {
      $partHeader = [
        'dsid' => [['text' => $partDef['title'], 'class' => 'e10-bold block h2']],
        '_options' => [
          'noIncRowNum' => 1,
          'class' => $partDef['headerClass'] ?? 'e10-bg-t9',

          'colSpan' => ['dsid' => 10],
        ],
      ];
      if (isset($partDef['subTitle']))
        $partHeader['dsid'][] = ['text' => $partDef['subTitle'], 'class' => ''];
      if (!$this->firstPart)
        $partHeader['_options']['beforeSeparator'] = 'separator';
      $this->firstPart = 0;

      $this->data[] = $partHeader;

      $itemHeader = [
        'dsid' => 'Databáze',

        'cntDocuments12m' => ' Doklady',
        'cntCashRegs12m' => ' Prodejky',
  			'usageTotal' => ' Velikost',

        'plan' => 'Tarif',
        'priceDocs' => ' Základní cena',

        'priceUsage' => ' Příplatek',
        'extraCharges' => 'Za',
        'priceTotal' => ' Cena celkem',

        '_options' => [
          'noIncRowNum' => 1,
          'class' => $partDef['headerClass'] ?? 'e10-bg-t9',
          'colSpan' => ['dsid' => 2],
        ],
      ];

      $this->data[] = $itemHeader;

      $this->data = array_merge($this->data, $data);

      $totals['_options'] = ['class' => 'subtotal', /*'afterSeparator' => 'separator',*/ 'colSpan' => ['dsid' => 2]];
      $totals['usageTotal'] = Utils::memf($totals['usageTotal']);
      $totals['dsid'] = 'CELKEM:';

      $this->data[] = $totals;
    }
  }

  function loadData_AllDSPks()
	{
		$q = [];
    array_push($q, 'SELECT ds.ndx, ');
		array_push($q, ' ds.name as dsName, ds.shortName as dsShortName');
    array_push($q, ' FROM hosting_core_dataSources AS ds');
		array_push($q, ' WHERE ds.docState IN %in', [4000, 8000]);
    if ($this->partnerNdx)
		  array_push($q, ' AND ds.partner = %i', $this->partnerNdx);
    if ($this->invoicingGroupNdx)
		  array_push($q, ' AND ds.invoicingGroup = %i', $this->invoicingGroupNdx);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
      $this->allPks[] = $r['ndx'];
    }
  }

	function createContentDataSources ()
	{
		$h = [
			'#' => '#',
			'dsid' => 'ID',
			'name' => 'Jméno',
			//'partner' => 'Partner',
			//'cntDocumentsAll' => '+Dokl. celkem',
			'cntDocuments12m' => ' Doklady',
			'cntCashRegs12m' => ' Prodejky',
			//'cntIssues12m' => '+Zprávy',

			'usageTotal' => ' Velikost',

      'plan' => '|Tarif',
			'priceDocs' => ' Základní cena',

			'priceUsage' => ' Příplatek',
			'extraCharges' => '_Za',
			//'extModulesPrice' => ' Rozšíření',
			'priceTotal' => ' Cena celkem',
		];

		//if ($this->partnerNdx)
		//	unset($h['partner']);

		$content = ['type' => 'table', 'header' => $h, 'table' => $this->data, 'main' => TRUE];

    return $content;
		//$this->addPlansLegend();
	}

	public function createContentPlansLegend()
	{
		$plansLegend = $this->tableDataSources->getPlansLegend();
		$plansLegend['type'] = 'table';
		$plansLegend['title'] = 'Přehled tarifů';


    return $plansLegend;
	}

  public function createContentRecap ()
	{
    if (!$this->partnerNdx)
      return;

		$i = $this->app()->loadItem($this->partnerNdx, 'hosting.core.partners');

		$info = [];
		$info[] = ['p1' => 'Název', 't1' => $i['name']];
		$info[] = ['p1' => 'Web', 't1' => $i['webUrl']];
		$info[] = ['p1' => 'Email na podporu', 't1' => $i['supportEmail']];
		$info[] = ['p1' => 'Telefon na podporu', 't1' => $i['supportPhone']];

		$this->addLogo ('Logo partnera', $i['logoPartner'], $info);
		$this->addLogo ('Logo - ikona', $i['logoIcon'], $info);

		$this->addPersons($info);

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

    if ($this->print)
    {
      $content = [
        'type' => 'table',
        'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1]
      ];
    }
    else
    {
      $content = [
        'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
        'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
      ];
    }

    return $content;
	}

  function addLogo ($title, $ndx, &$dstTable)
	{
		if (!$ndx)
		{
			$dstTable[] = [
				'p1' => $title,
				];
			return;
		}

		$att = $this->db()->query ('SELECT * FROM [e10_attachments_files] WHERE [ndx] = %i', $ndx)->fetch();
    $fn = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/att/'.$att['path'].$att['filename'];

		$dstTable[] = [
			'p1' => $title,
			't1' => [
				['text' => '#'.$ndx], ['code' => "<img src='$fn' class='pull-right' style='max-height: 3em; padding: .5ex; '>"]
			]
		];
	}

	function addPersons(&$dstTable)
	{
    if (!$this->partnerNdx)
      return;

		$q[] = 'SELECT pp.*, persons.fullName AS personName, persons.id AS personId';
		array_push ($q, ' FROM [hosting_core_partnersPersons] AS pp');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON pp.person = persons.ndx');

		array_push($q, ' WHERE pp.partner = %i', $this->partnerNdx);
		array_push($q, ' ORDER BY persons.lastName');

		$rows = $this->db()->query ($q);
		$label = 1;
		foreach ($rows as $r)
		{
			$item = [];
			if ($label)
				$item['p1'] = 'Osoby';
			$item['t1'] = [['text' => $r['personName']]];
			$item['t1'][] = ['text' => '#'.$r['personId'], 'class' => 'pull-right id'];

      if ($this->print)
      {
        if ($r['isSupport'])
          $item['t1'][] = ['text' => 'Technická podpora', 'class' => 'pull-right e10-small', 'icon' => 'system/actionSupport'];
        if ($r['isAdmin'])
          $item['t1'][] = ['text' => 'Správce', 'class' => 'pull-right e10-small', 'icon' => 'system/actionSettings'];
      }
      else
      {
        if ($r['isSupport'])
          $item['t1'][] = ['text' => '', 'title' => 'Technická podpora zákazníků', 'class' => 'pull-right', 'icon' => 'system/actionSupport'];
        if ($r['isAdmin'])
          $item['t1'][] = ['text' => '', 'title' => 'Správce partnera', 'class' => 'pull-right', 'icon' => 'system/actionSettings'];
      }
			$dstTable[] = $item;
			$label = 0;
		}
	}

  public function run()
  {
    $this->init();
		$this->loadData();
  }
}
