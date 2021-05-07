<?php

namespace e10mnf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableWorkRecsNumbers
 * @package e10mnf\base
 */
class TableWorkRecsNumbers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.workRecsNumbers', 'e10mnf_base_workRecsNumbers', 'Číselné řady Pracovních záznamů');
	}

	public function saveConfig ()
	{
		$docNumbers = array ();
		$rows = $this->app()->db->query ('SELECT * FROM [e10mnf_base_workRecsNumbers] WHERE docState != 9800 ORDER BY [order], [tabName], [fullName], [docKeyId]');

		foreach ($rows as $r)
		{
			$docNumbers [$r['ndx']] = [
				'ndx' => $r['ndx'], 'docKeyId' => $r ['docKeyId'],
				'name' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'tabName' => $r ['tabName'],
				'useDocKinds' => $r['useDocKinds'], 'docKind' => $r['docKind'],
			];
		}

		// -- save to file
		$cfg['e10mnf']['workRecs']['wrNumbers'] = $docNumbers;
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.base.wrNumbers.json', utils::json_lint (json_encode ($cfg)));
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
 * Class ViewWorkRecsNumbers
 * @package e10mnf\base
 */
class ViewWorkRecsNumbers extends TableView
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

		$q [] = 'SELECT * FROM [e10mnf_base_workRecsNumbers]';
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
		$docKind = $this->table->app()->cfgItem ('e10mnf.workRecs.wrKinds.' . $item ['docKind']);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['docKeyId'];
		$listItem ['i2'] = ['text' => '#'.$item['ndx'], 'class' => 'e10-small e10-id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item ['docKind'])
			$props[] = ['text' => $docKind['sn'], 'icon' => 'icon-flag-o', 'class' => 'label label-default'];

		if ($item['tabName'] !== '')
			$props[] = ['text' => $item['tabName'], 'icon' => 'icon-folder-o', 'class' => 'label label-default'];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'icon-sort', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}
}


/**
 * Class FormWorkRecNumber
 * @package e10mnf\base
 */
class FormWorkRecNumber extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('docKeyId');
			$this->addColumnInput ('tabName');
			$this->addColumnInput ('order');
			$this->addColumnInput ('useDocKinds');

			$this->addColumnInput ('docKind');
		$this->closeForm ();
	}
}

