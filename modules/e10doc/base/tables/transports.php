<?php

namespace E10Doc\Base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableTransports
 * @package E10Doc\Base
 */
class TableTransports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.transports', 'e10doc_base_transports', 'Způsoby dopravy');
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
		$transports = array ();
		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_transports] WHERE [docState] != 9800 ORDER BY [order], [id]');

		foreach ($rows as $r)
		{
			$transports [$r['ndx']] = [
				'ndx' => $r['ndx'], 'id' => $r['id'],
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'], 'pb' => $r['personBalance']
			];
		}
		// save to file
		$cfg ['e10doc']['transports'] = $transports;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.transports.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewCashBoxes
 * @package E10Doc\Base
 */
class ViewTransports extends TableView
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
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_transports]';
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

		$this->queryMain ($q, '', ['[order]', '[id]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormTransport
 * @package E10Doc\Base
 */
class FormTransport extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
			$this->addColumnInput ('order');
			$this->addColumnInput ('personBalance');
		$this->closeForm ();
	}
}

