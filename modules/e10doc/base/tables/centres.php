<?php

namespace E10Doc\Base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableCentres
 * @package E10Doc\Base
 */
class TableCentres extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.centres', 'e10doc_base_centres', 'Střediska');
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
		$centres = array ();
		$centres [0] = ['ndx' => 0, 'id' => '', 'fullName' => '', 'shortName' => ''];

		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_centres] WHERE [docState] != 9800 ORDER BY [id]');

		foreach ($rows as $r)
			$centres [$r['ndx']] = ['ndx' => $r ['ndx'], 'id' => $r ['id'], 'fullName' => $r ['fullName'], 'shortName' => $r ['shortName']];

		// save to file
		$cfg ['e10doc']['centres'] = $centres;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.centres.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewCentres
 * @package E10Doc\Base
 */
class ViewCentres extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_centres]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%',
				' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[id]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCentre
 * @package E10Doc\Base
 */
class FormCentre extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
		$this->closeForm ();
	}	
}

