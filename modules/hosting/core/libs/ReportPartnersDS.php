<?php

namespace hosting\core\libs;
use \Shipard\Utils\Utils;


/**
 * @class ReportPartnersDS
 */
class ReportPartnersDS extends \Shipard\Report\GlobalReport
{
	var \hosting\core\TableDataSources $tableDataSources;
	var $partnerNdx = 0;
	var $disableEdit = FALSE;

	var $data = [];
  var $sumTotals;
  var $allPks = [];

	function init ()
	{
		$this->tableDataSources = $this->app()->table('hosting.core.dataSources');

		if ($this->partnerNdx === 0)
			$this->addParamPartner();

		parent::init();

		if ($this->partnerNdx === 0)
			$this->partnerNdx = intval($this->reportParams ['partner']['value']);

		$this->setInfo('param', 'Partner', $this->reportParams ['partner']['activeTitle']);
		$this->setInfo('icon', 'system/iconDatabase');
	}

	function createContent ()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'detail': $this->createContent_Detail(); break;
		}

		$this->setInfo('title', 'Databáze');
	}

  function loadData()
	{
    $this->sumTotals = ['usageTotal' => 0, 'cntCashRegs12m' => 0, 'cntDocuments12m' => 0, 'priceTotal' => 0];

    $this->loadDataPart([
      'title' => 'Fakturované databáze v ostrém provozu',
      'headerClass' => 'e10-bg-t7',
      'query' => ['invoicingTo' => 0, 'dsType' => 0]
    ]);

    $this->loadDataPart([
      'title' => 'Databáze v ostrém provozu BEZ FAKTURACE',
      'subTitle' => 'Máme vás rádi...',
      'headerClass' => 'e10-bg-t6',
      'query' => ['invoicingTo' => 3, 'dsType' => 0, 'dsDemo' => 0]
    ]);

    $this->loadDataPart([
      'title' => 'Databáze fakturované přímo zákazníkovi',
      'subTitle' => 'Poskytujete podporu, ale faktura za provoz jde zákazníkovi od nás',
      'headerClass' => 'e10-bg-t1',
      'query' => ['invoicingTo' => 1, 'dsType' => 0]
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
      'headerClass' => 'e10-bg-t3',
      'query' => ['dsType' => 1]
    ]);

    $this->loadDataPart([
      'title' => 'Ostatní',
      'subTitle' => 'Tady by nemělo nic být - prosím kontaktujte nás...',
      'partType' => 'others'
    ]);

    $this->sumTotals['_options'] = ['class' => 'sumtotal', 'beforeSeparator' => 'separator', 'colSpan' => ['dsid' => 2]];
    $this->sumTotals['usageTotal'] = Utils::memf($this->sumTotals['usageTotal']);
    $this->sumTotals['dsid'] = 'Celkem za všechny databáze:';
    $this->data [] = $this->sumTotals;
  }

  function loadDataPart(array $partDef)
	{
		$q[] = 'SELECT stats.*, ';

		array_push($q, ' ds.name as dsName, ds.shortName as dsShortName, ds.gid, partners.name as partnerName');
    array_push($q, ' FROM hosting_core_dataSources AS ds');
		array_push($q, ' LEFT JOIN hosting_core_dsStats AS stats ON ds.ndx = stats.dataSource');
    array_push($q, ' LEFT JOIN hosting_core_partners AS partners ON ds.partner = partners.ndx');
		array_push($q, ' WHERE ds.docState IN %in', [4000, 8000]);
		if ($this->partnerNdx)
			array_push($q, ' AND ds.partner = %i', $this->partnerNdx);

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
					'text' => substr($r['gid'], 0, 4).'...'.substr($r['gid'], -4),
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

				'priceTotal' => $plan['priceTotal'],
			];

			if ($plan['priceUsage'])
			{
				$item['priceUsage'] = ['text' => Utils::nf($plan['priceUsage']), 'prefix' => $plan['priceUsageLegend'].' ='];
			}

      if (isset($partDef['headerClass']))
        $item['_options']['cellClasses']['#'] = $partDef['headerClass'];

      $totals['usageTotal'] += $r['usageTotal'];
			$totals['cntDocuments12m'] += $r['cntDocuments12m'];
      $totals['cntCashRegs12m'] += $r['cntCashRegs12m'];
      $totals['priceDocs'] += $item['priceDocs'] ?? 0;
      $totals['priceTotal'] += $item['priceTotal'] ?? 0;

      $this->sumTotals['usageTotal'] += $r['usageTotal'];
			$this->sumTotals['cntDocuments12m'] += $r['cntDocuments12m'];
      $this->sumTotals['cntCashRegs12m'] += $r['cntCashRegs12m'];
      $this->sumTotals['priceDocs'] += $item['priceDocs'] ?? 0;
      $this->sumTotals['priceTotal'] += $item['priceTotal'] ?? 0;

      $this->allPks[] = $r['dataSource'];

			$data[] = $item;
    }

    if (count($data))
    {
      $itemHeader = [
        'dsid' => [['text' => $partDef['title'], 'class' => 'e10-bold block h2']],

        'cntDocuments12m' => ' Doklady/rok',
        'cntCashRegs12m' => ' Prodejky/rok',
  			'usageTotal' => ' Velikost',
        '_options' => [
          'noIncRowNum' => 1,
          'class' => $partDef['headerClass'] ?? 'e10-bg-t9', 'beforeSeparator' => 'separator',
          'colSpan' => ['dsid' => 2],
        ],
      ];
      if (isset($partDef['subTitle']))
        $itemHeader['dsid'][] = ['text' => $partDef['subTitle'], 'class' => ''];

      $this->data[] = $itemHeader;

      $this->data = array_merge($this->data, $data);

      $totals['_options'] = ['class' => 'subtotal', /*'afterSeparator' => 'separator',*/ 'colSpan' => ['dsid' => 2]];
      $totals['usageTotal'] = Utils::memf($totals['usageTotal']);
      $totals['dsid'] = 'CELKEM:';

      $this->data[] = $totals;
    }
  }

	function createContent_Detail ()
	{
		$h = [
			'#' => '#',
			'dsid' => 'ID',
			'name' => 'Jméno',
			'partner' => 'Partner',
			//'cntDocumentsAll' => '+Dokl. celkem',
			'cntDocuments12m' => ' Doklady',
			'cntCashRegs12m' => ' Prodejky',
			//'cntIssues12m' => '+Zprávy',

			'usageTotal' => ' Velikost',
			'plan' => 'Tarif',
			'priceDocs' => ' Základní cena',
			'priceUsage' => ' Příplatek za místo',
			//'extModulesPrice' => ' Rozšíření',
			'priceTotal' => ' Cena celkem',
		];

		if ($this->partnerNdx)
			unset($h['partner']);

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data, 'main' => TRUE, 'params' => ['tableClass' => 'e10-print-small']]);

		$this->addPlansLegend();
	}

	protected function addPlansLegend()
	{
		$plansLegend = $this->tableDataSources->getPlansLegend();
		$plansLegend['type'] = 'table';
		$plansLegend['title'] = 'Přehled tarifů';

		$this->addContent ($plansLegend);
	}

	protected function addParamPartner ()
	{
		$q[] = 'SELECT ndx, name FROM hosting_core_partners AS partners';
		array_push($q, ' WHERE partners.docState = 4000');
		array_push($q, ' ORDER BY partners.name, partners.ndx');

		$enum = [/*'0' => 'Vše'*/];
		$enum += $this->db()->query($q)->fetchPairs();
		$this->addParam('switch', 'partner', ['title' => 'Partner', 'switch' => $enum]);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'detail', 'icon' => 'icon-table', 'title' => 'Přehled'];

		return $d;
	}
}
