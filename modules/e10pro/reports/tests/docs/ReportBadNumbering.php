<?php

namespace e10pro\reports\tests\docs;

use e10doc\core\libs\E10Utils;


/**
 * Class ReportBadNumbering
 */
class ReportBadNumbering extends \e10doc\core\libs\reports\GlobalReport
{
	var $docTypes;
	var $duplicityDocNumbers = [];

	function init ()
	{
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['enableAll', 'quarters', 'halfs', 'years'], 'defaultValue' => 'Y'.E10Utils::todayFiscalYear($this->app)]);

		parent::init();

		$this->setInfo('icon', 'system/actionCopy');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
	}

	function createContent ()
	{
		$this->loadDuplicityDocNumbers();
		$this->createContent_Duplicties ();
	}

	protected function loadDuplicityDocNumbers()
	{
		$q [] = 'SELECT heads.docNumber';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, ' WHERE heads.docState = 4000');
		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);
		array_push ($q, ' GROUP BY docNumber');
		array_push ($q, ' HAVING count(*) > 1');

		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
			$this->duplicityDocNumbers[] = $r['docNumber'];
		}
	}

	function createContent_Duplicties ()
	{
		$this->setInfo('title', 'Duplicitní čísla dokladů');

		if (!count($this->duplicityDocNumbers))
		{
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
			return;
		}

		$q [] = 'SELECT heads.*, persons.fullName as personName ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND heads.docState = 4000');
		array_push ($q, ' AND heads.docNumber IN %in', $this->duplicityDocNumbers);
		array_push ($q, ' ORDER BY docNumber, dateAccounting');

		$lastDocNumber = '';
		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

			$newItem = [
					'dn' => ['text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx'], 'icon' => $docType ['icon']],
					'person' => $r['personName'], 'title' => $r['title'], 'date' => $r['dateAccounting'], 'dt' => $docType ['shortcut']
			];

			if ($lastDocNumber !== $r['docNumber'])
			{
				$newItem['_options']['beforeSeparator'] = 'separator';
			}

			$data[] = $newItem;

			$lastDocNumber = $r['docNumber'];
		}

		if (count($data))
		{
			$h = ['#' => '#', 'dn' => 'Doklad', 'dt' => 'DD', 'date' => 'Datum', 'person' => 'Osoba', 'title' => 'Popis'];
			$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}
}
