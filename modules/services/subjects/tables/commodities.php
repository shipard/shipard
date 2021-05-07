<?php

namespace services\subjects;



use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableCommodities
 * @package services\subjects
 */
class TableCommodities extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.commodities', 'services_subjects_commodities', 'Činnosti subjektů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];
		$list [0] = ['ndx' => 0, 'id' => '', 'fullName' => '', 'shortName' => ''];

		$rows = $this->app()->db->query ('SELECT * FROM [services_subjects_commodities] WHERE docState != 9800 ORDER BY [fullName], [ndx]');

		foreach ($rows as $r)
			$list [$r['ndx']] = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName']];

		// save to file
		$cfg ['services']['subjects']['commodities'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_services.subjects.commodities.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewCommodities
 * @package services\subjects
 */
class ViewCommodities extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * from [services_subjects_commodities] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s OR [shortName] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailCommodity
 * @package services\subjects
 */
class ViewDetailCommodity extends TableViewDetail
{
}


/**
 * Class FormActivity
 * @package services\subjects
 */
class FormCommodity extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
		$this->closeForm ();
	}
}

