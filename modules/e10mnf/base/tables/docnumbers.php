<?php

namespace e10mnf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableDocNumbers
 * @package e10mnf\base
 */
class TableDocNumbers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.docnumbers', 'e10mnf_base_docnumbers', 'Číselné řady zakázek');
	}

	public function saveConfig ()
	{
		$docNumbers = array ();
		$rows = $this->app()->db->query ('SELECT * from [e10mnf_base_docnumbers] WHERE docState != 9800 ORDER BY [order], [tabName], [fullName], [docKeyId]');

		foreach ($rows as $r)
		{
			$docNumbers [$r['ndx']] = [
					'ndx' => $r['ndx'], 'docKeyId' => $r ['docKeyId'],
					'name' => $r ['fullName'], 'shortName' => $r ['shortName'],
					'tabName' => $r ['tabName'],
					'manualNumbering' => $r ['manualNumbering'],
					'useDocKinds' => $r['useDocKinds'], 'docKind' => $r['docKind'],
					'invoicesInViewer' => $r ['invoicesInViewer'],
					'personsInViewer' => $r ['personsInViewer'],
			];
		}

		// -- save to file
		$cfg ['e10mnf']['workOrders']['dbCounters'] = $docNumbers;
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.workOrders.docNumbers.json', utils::json_lint (json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewDocNumbers
 * @package e10mnf\base
 */
class ViewDocNumbers extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10mnf_base_docnumbers]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%',
					' OR [docKeyId] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[tabName]', '[fullName]', '[docKeyId]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$docKind = $this->table->app()->cfgItem ('e10mnf.workOrders.kinds.' . $item ['docKind']);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['docKeyId'];
		$listItem ['i2'] = ['text' => '#'.$item['ndx'], 'class' => 'e10-small e10-id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item ['docKind'])
			$props[] = ['text' => $docKind['shortName'], 'icon' => 'icon-flag-o', 'class' => 'label label-default'];

		if ($item['tabName'] !== '')
			$props[] = ['text' => $item['tabName'], 'icon' => 'icon-folder-o', 'class' => 'label label-default'];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}
}


/**
 * Class FormDocNumber
 * @package e10mnf\base
 */
class FormDocNumber extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('manualNumbering');
			if (!$this->recData['manualNumbering'])
				$this->addColumnInput ('docKeyId');
			$this->addColumnInput ('tabName');
			$this->addColumnInput ('order');
			$this->addColumnInput ('useDocKinds');

			if (isset($this->recData['useDocKinds']) && $this->recData['useDocKinds'] !== 0)
				$this->addColumnInput ('docKind');

			$this->addColumnInput ('invoicesInViewer');
			$this->addColumnInput ('personsInViewer');
		$this->closeForm ();
	}
}

