<?php

namespace services\subjregs\cz_res_plus;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail;


/**
 * Class TableRzpProvoz
 * @package services\subjregs\cz_res_plus
 */
class TableRzpProvoz extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjregs.cz_res_plus.rzpProvoz', 'services_subjregs_cz_res_plus_rzpProvoz', 'RZP Provozovny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['ico']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['icp']];

		return $hdr;
	}
}


/**
 * Class ViewRzpProvoz
 * @package services\subjregs\cz_res_plus
 */
class ViewRzpProvoz extends TableView
{
	public function init()
	{
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['subjNazev'];
		$listItem ['i2'] = $item['ico'];
		$listItem ['t2'] = $item['icp'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT provoz.*, subjects.nazev AS subjNazev FROM [services_subjregs_cz_res_plus_rzpProvoz] AS provoz';
		array_push ($q, ' LEFT JOIN services_subjregs_cz_res_plus_rzpSubj AS subjects ON provoz.ico = subjects.ico');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' provoz.[icp] LIKE %s', $fts.'%',
				' OR provoz.[ico] LIKE %s', $fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY provoz.ico, icp');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}
}


/**
 * Class FormRzpProvoz
 * @package services\subjregs\cz_res_plus
 */
class FormRzpProvoz extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
		$this->addColumnInput ('ico');
		$this->addColumnInput ('ico');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailRzpProvoz
 * @package services\subjregs\cz_res_plus
 */
class ViewDetailRzpProvoz extends TableViewDetail
{
	public function createDetailContent ()
	{
		$h = ['t' => 'text', 'v' => 'hodnota'];

		$subjectRecData = $this->db()->query ('SELECT * FROM [services_subjregs_cz_res_plus_rzpSubj] WHERE [ico] = %s', $this->item['ico'])->fetch();
		if ($subjectRecData)
		{
			$t = [];
			$t[] = ['t' => 'IČ', 'v' => $subjectRecData['ico'], '_options' => ['cellClasses' => ['t' => 'width30']]];
			$t[] = ['t' => 'Název', 'v' => $subjectRecData['nazev']];
			$t[] = ['t' => 'Ulice', 'v' => $subjectRecData['ulice']];
			$t[] = ['t' => 'Město', 'v' => $subjectRecData['obec']];
			$t[] = ['t' => 'PSČ', 'v' => $subjectRecData['psc']];

			$this->addContent([
				'table' => $t, 'header' => $h, 'pane' => 'e10-pane e10-pane-table', 'title' => 'Firma',
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
			]);
		}

		$t = [];
		$t[] = ['t' => 'IČP', 'v' => $this->item['icp'], '_options' => ['cellClasses' => ['t' => 'width30']]];
		$t[] = ['t' => 'Ulice', 'v' => $this->item['ulice']];
		$t[] = ['t' => 'Město', 'v' => $this->item['obec']];
		$t[] = ['t' => 'PSČ', 'v' => $this->item['psc']];

		$this->addContent([
			'table' => $t, 'header' => $h, 'pane' => 'e10-pane e10-pane-table', 'title' => 'Provozovna',
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
	}
}
