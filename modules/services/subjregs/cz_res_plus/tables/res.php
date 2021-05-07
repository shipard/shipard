<?php

namespace services\subjregs\cz_res_plus;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail;


/**
 * Class TableRes
 * @package services\subjregs\cz_res_plus
 */
class TableRes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjregs.cz_res_plus.res', 'services_subjregs_cz_res_plus_res', 'RES');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['ico']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['nazev']];

		return $hdr;
	}
}


/**
 * Class ViewRes
 * @package services\subjregs\cz_res_plus
 */
class ViewRes extends TableView
{
	public function init()
	{
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['nazev'];
		$listItem ['t2'] = $item['ico'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [services_subjregs_cz_res_plus_res]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				//' [nazev] LIKE %s', '%'.$fts.'%',
				' [ico] LIKE %s', $fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY nazev');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}
}


/**
 * Class FormRes
 * @package services\subjregs\cz_res_plus
 */
class FormRes extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('ic');
			$this->addColumnInput ('nazev');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailPerson
 * @package services\persons
 */
class ViewDetailRes extends TableViewDetail
{
	public function createDetailContent ()
	{
		$h = ['t' => 'text', 'v' => 'hodnota'];
		$t = [];
		$t[] = ['t' => 'IČ', 'v' => $this->item['ico'], '_options' => ['cellClasses' => ['t' => 'width30']]];
		$t[] = ['t' => 'Název', 'v' => $this->item['nazev']];
		$t[] = ['t' => 'Ulice', 'v' => $this->item['ulice']];
		$t[] = ['t' => 'Město', 'v' => $this->item['obec']];
		$t[] = ['t' => 'PSČ', 'v' => $this->item['psc']];

		$naceRecData = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [itemId] = %s', $this->item['okec6a'], ' AND [nomencType] = 2')->fetch();
		if ($naceRecData)
			$t[] = ['t' => 'NACE', 'v' => $this->item['okec6a'].' '.$naceRecData['fullName']];
		else
			$t[] = ['t' => 'NACE', 'v' => $this->item['okec6a']];

		$t[] = ['t' => 'Platné OD', 'v' => $this->item['datvzn']];
		$t[] = ['t' => 'Platné DO', 'v' => $this->item['datzan']];

		$this->addContent([
			'table' => $t, 'header' => $h, 'pane' => 'e10-pane e10-pane-table',
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
	}
}
