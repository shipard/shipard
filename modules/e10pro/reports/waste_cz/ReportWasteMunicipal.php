<?php

namespace e10pro\reports\waste_cz;


/**
 * Class ReportWasteMunicipal
 * @package e10pro\reports\waste_cz
 */
class ReportWasteMunicipal extends \e10pro\reports\waste_cz\ReportWasteCore
{
	function createContent ()
	{
		$this->createContent_City ();
	}

	function createContent_City ()
	{
		$q[] = 'SELECT quarter(heads.dateAccounting) as qrt, [rows].item as item, [rows].unit as unit, SUM(rows.quantity) as quantity';
		array_push ($q, ' FROM e10doc_core_rows as [rows], e10doc_core_heads as heads, e10_persons_persons as persons');
		array_push ($q, ' where [rows].document = heads.ndx AND heads.person = persons.ndx');
		array_push ($q, ' AND persons.company = 0 AND heads.docType IN %in', ['stockin', 'purchase']);

		if ($this->inventory)
			array_push ($q, ' AND rows.invDirection = 1');
		else
			array_push ($q, ' AND heads.docType IN %in', ['purchase']);

		array_push ($q, ' AND heads.docState = 4000 AND heads.initState = 0 AND YEAR(heads.dateAccounting) = %i', $this->year);
		array_push ($q, ' GROUP BY 1, 2, 3');

		$rows = $this->app->db()->query ($q);
		$data = array ();
		forEach ($rows as $r)
		{
			$itemWasteCode = $this->itemWasteCode ($r['item']);
			if (!$itemWasteCode)
				continue;
			$group = $itemWasteCode['group'];
			$qrt = $r['qrt'];
			$quantity = $this->quantity($r['quantity'], $r['unit']);
			if (!isset ($data[$qrt]))
				$data[$qrt] = array ('qrt' => $qrt, 'paper' => 0, 'metal' => 0);
			if (!isset ($data[$qrt][$group]))
				continue;
			$data[$qrt][$group] += $quantity;
		}

		forEach ($data as &$di)
		{
			$di['metal'] = intval ($di['metal']);
			$di['paper'] = intval ($di['paper']);
		}

		$h = array ('qrt' => ' Čtvrtletí', 'paper' => ' Papír [kg]', 'metal' => ' Kovy [kg]');
		$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));

		$this->setInfo('title', 'Odběr odpadů od fyzických osob');
	}
}
