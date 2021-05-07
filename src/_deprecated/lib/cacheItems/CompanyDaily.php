<?php

namespace lib\cacheItems;

use \e10\utils;


/**
 * Class CompanyDaily
 * @package lib\cacheItems
 */
class CompanyDaily extends \Shipard\Base\CacheItem
{
	CONST ptDay = 1, ptWeek = 2, ptMonth = 3;

	var $periodType = self::ptDay;

	var $queryDocTypes = ['invno', 'invni'];

	var $infoDocTypes = [
			'invno' => ['order' => 100, 'title' => 'Vydané faktury', 'icon' => 'e10-docs-invoices-out'],
			'invni' => ['order' => 200, 'title' => 'Přijaté faktury', 'icon' => 'e10-docs-invoices-in'],
	];

	var $data = [];

	function loadSummary ()
	{
		$q[] = 'SELECT heads.docType, SUM(heads.sumBaseHc) as baseHc, COUNT(*) as cnt';

		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' WHERE [docType] IN %in', $this->queryDocTypes);
		array_push ($q, ' AND [docState] = %i', 4000);
		$this->periodQuery($q);
		array_push ($q, ' GROUP BY 1');

		$rows = $this->app->db()->query ($q);

		foreach ($rows as $r)
		{
			$dt = $r['docType'];
			$item = [
					'title' => $this->infoDocTypes[$dt]['title'], 'icon' => $this->infoDocTypes[$dt]['icon'],
					'order' => $this->infoDocTypes[$dt]['order'],
					'count' => $r['cnt'], 'baseHc' => $r['baseHc'], 'docs' => []
			];
			$this->data[$dt] = $item;
		}
	}

	function loadDocs ($docType)
	{
		$q[] = 'SELECT heads.sumBaseHc as baseHc, heads.activateTimeFirst as [dateTime], heads.docType as docType,';
		array_push ($q, ' heads.docNumber as docNumber,');
		array_push ($q, ' persons.fullName as personName');

		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.[docType] = %s', $docType);
		array_push ($q, ' AND heads.[docState] = %i', 4000);
		$this->periodQuery($q);
		array_push ($q, ' ORDER BY heads.[activateTimeFirst] DESC');
		array_push ($q, ' LIMIT 8');

		$rows = $this->app->db()->query ($q);

		foreach ($rows as $r)
		{
			$dt = $r['docType'];

			$item = [
					'docNumber' => $r['docNumber'], 'dateTime' => $r['dateTime'], 'baseHc' => $r['baseHc'],
					'personName' => $r['personName'], 'ts' => $this->timeStamp ($r['dateTime'])
			];
			$this->data[$dt]['docs'][] = $item;
		}
	}

	function timeStamp ($ts)
	{
		$s = '';

		$dow = intval($ts->format('N')) - 1;
		$s .= utils::$dayShortcuts[$dow].' ';

		$s .= $ts->format ('H:i');

		return $s;
	}

	function periodQuery(&$q)
	{
		if (1)
		{
			$today = utils::today();
			array_push($q, ' AND DATE(heads.[activateDateFirst]) = %d', $today);
		}
		else
		{
			//$today = utils::today();
			$today = new \DateTime('2016-11-02');
			$year = $today->format('Y');
			$week = $today->format('W');

			$dateBegin = utils::weekDate ($week, $year, 1);
			$dateEnd = utils::weekDate ($week, $year, 7);
			array_push($q, ' AND DATE(heads.[activateDateFirst]) BETWEEN %d AND %d', $dateBegin, $dateEnd);
		}
	}

	function createData()
	{
		$this->loadSummary();

		foreach ($this->data as $docType => $docInfo)
		{
			$this->loadDocs($docType);
		}

		$this->data['docs'] = $this->data;

		parent::createData();
	}
}
