<?php

namespace e10pro\reports\tests\docs;

require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';

use e10doc\core\e10utils;


/**
 * Class ReportBadFiscalPeriod
 * @package e10pro\reports\tests\docs
 */
class ReportBadFiscalPeriod extends \e10doc\core\GlobalReport
{
	var $docTypes;

	function init ()
	{
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');
		parent::init();
		$this->setInfo('icon', 'icon-warning-sign');
	}

	function createContent ()
	{
		$this->createContent_NoFiscalPeriod ();
	}

	function createContent_NoFiscalPeriod ()
	{
		$q [] = 'SELECT heads.*, persons.fullName as personName ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.docState = 4000');

		if ($this->app->model()->table ('e10doc.debs.journal') === FALSE)
			array_push ($q, ' AND fiscalYear = 0');
		else
		{
			array_push ($q, ' AND (');
			array_push ($q, ' (heads.fiscalYear = 0)');
			array_push ($q, ' OR');
			array_push ($q, ' EXISTS (SELECT ndx FROM e10doc_debs_journal as journal WHERE heads.ndx = journal.document AND journal.fiscalYear = 0)');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

			$newItem = [
					'dn' => ['text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx'], 'icon' => $docType ['icon']],
					'person' => $r['personName'], 'title' => $r['title'], 'date' => $r['dateAccounting'], 'dt' => $docType ['shortcut']
			];
			$data[] = $newItem;
		}

		$this->setInfo('title', 'Doklady bez účetního období');
		if (count($data))
		{
			$h = ['#' => '#', 'dn' => 'Doklad', 'dt' => 'DD', 'date' => 'Datum', 'person' => 'Osoba', 'title' => 'Popis'];
			$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}
}
