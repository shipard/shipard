<?php

namespace e10pro\hosting\server\libs;
use E10\utils, e10pro\Hosting\Server\TableDatasources;


/**
 * Class Report
 * @package e10pro\lan
 */
class DataSourcesPlansReport extends \E10\GlobalReport
{
	/** @var TableDatasources */
	var $tableDataSources;
	var $partnerNdx = 0;
	var $disableEdit = FALSE;

	var $data = [];

	function init ()
	{
		$this->tableDataSources = $this->app()->table('e10pro.hosting.server.datasources');

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

		$this->setInfo('title', 'Zdroje dat');
	}

	function loadData()
	{
		$totals = ['usageTotal' => 0, 'cntDocuments12m' => 0];


		$q[] = 'SELECT stats.*, ';
//		array_push($q, ' stats.cntDocuments12m as cntDocuments12m, stats.cntDocumentsAll, stats.cntUsersAll1m as cntUsersAll1m,');
		array_push($q, ' ds.name as dsName, ds.shortName as dsShortName, ds.gidstr, partners.name as partnerName');
		array_push($q, ' FROM e10pro_hosting_server_datasourcesStats AS stats');
		array_push($q, ' LEFT JOIN e10pro_hosting_server_datasources AS ds ON stats.datasource = ds.ndx');

		array_push($q, ' LEFT JOIN e10pro_hosting_server_partners AS partners ON ds.partner = partners.ndx');

		array_push($q, ' WHERE ds.docState = 4000');
		array_push($q, ' AND ds.dsType = %i', 0, ' AND ds.condition = %i', 1);

		if ($this->partnerNdx)
			array_push($q, ' AND ds.partner = %i', $this->partnerNdx);

		array_push($q, ' ORDER BY ds.name');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$plan = $this->tableDataSources->getPlan($r);

			$item = [
				'dsid' => ($this->disableEdit) ? ['text' => substr($r['gidstr'], 0, 4).'...'.substr($r['gidstr'], -4)] : [
					'text' => substr($r['gidstr'], 0, 4).'...'.substr($r['gidstr'], -4),
					'docAction' => 'edit', 'pk' => $r['datasource'], 'table' => 'e10pro.hosting.server.datasources'
				],
				'name' => ($r['dsShortName'] === '') ? $r['dsName'] : $r['dsShortName'],
				'partner' => $r['partnerName'],
				'usageDb' => utils::memf($r['usageDb']),
				'usageFiles' => utils::memf($r['usageFiles']),
				'usageTotal' => utils::memf($r['usageTotal']),
				'cntIssues12m' => $r['cntIssues12m'],
				'cntDocuments12m' => $r['cntDocuments12m'] - $r['cntCashRegs12m'],
				'cntDocumentsAll' => $r['cntDocumentsAll'],
				'cntCashRegs12m' => $r['cntCashRegs12m'],
				'cntUsersAll1m' => $r['cntUsersAll1m'],
				'extModulesPoints' => $plan['extModulesPoints'],
				'plan' => $plan['title'],
				'priceDocs' => $plan['priceDocs'],
				'priceUsage' => $plan['priceUsage'],
				'priceTotal' => $plan['priceTotal'],
			];
			$this->data[] = $item;

			$totals['usageTotal'] += $r['usageTotal'];
			$totals['cntDocuments12m'] += $r['cntDocuments12m'];
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
			'cntDocuments12m' => '+Doklady',
			'cntCashRegs12m' => '+Prodejky',
			'cntIssues12m' => '+Zprávy',

			'plan' => '|Tarif',
			'priceDocs' => '+Základní cena',
			'usageTotal' => '+Velikost',
			'priceUsage' => '+Příplatek',
			'extModulesPoints' => '+Rozšíření',
			'priceTotal' => '+CELKEM',
		];

		if ($this->partnerNdx)
			unset($h['partner']);

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data, 'main' => TRUE]);

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
		$q[] = 'SELECT ndx, name FROM e10pro_hosting_server_partners AS partners';
		array_push($q, ' WHERE partners.docState = 4000');
		array_push($q, ' ORDER BY partners.name, partners.ndx');

		$enum = ['0' => 'Vše'];
		$enum += $this->db()->query($q)->fetchPairs();
		$this->addParam('switch', 'partner', ['title' => 'Partner', 'switch' => $enum]);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'detail', 'icon' => 'icon-table', 'title' => 'Přehled'];

		return $d;
	}
}
