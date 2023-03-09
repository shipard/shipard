<?php

namespace e10\witems;

use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TableCodesKinds
 */
class TableCodesKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.codesKinds', 'e10_witems_codesKinds', 'Druhy kódů položek');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['shortName']];
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$codeTypes = $this->app()->cfgItem('e10.witems.codeTypes', []);
		$list = [];

		$rows = $this->app()->db->query ('SELECT * from [e10_witems_codesKinds] WHERE [docState] != 9800 ORDER BY [order], [shortName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'],
				'sn' => $r ['shortName'],
				'codeType' => $r['codeType'],
				'reportSwitchTitle' => $r ['reportSwitchTitle'],
				'reportPersonTitle' => $r ['reportPersonTitle'],
				'reportPersonOutTitle' => $r ['reportPersonOutTitle'],
				'showInDocRows' => $r ['showInDocRows'],
			];

			$ct = $codeTypes[$r['codeType']];
			if (isset($ct['cfg']))
			{
				foreach ($ct['cfg'] as $key => $value)
					$item[$key] = $value;
			}

			$list [$r['ndx']] = $item;
		}

		// save to file
		$cfg ['e10']['witems']['codesKinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10.witems.codesKinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewCodesKinds
 */
class ViewCodesKinds extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10_witems_codesKinds]';
		array_push($q, '  WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND ([fullName] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR [shortName] LIKE %s)', '%' . $fts . '%');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];

		$props = [];
		if ($item ['order'] != 0)
			$props [] = ['icon' => 'icon-sortsystem/iconOrder', 'text' => utils::nf ($item ['order'], 0)];
		if (count($props))
			$listItem ['i2'] = $props;


		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * Class ViewDetailCodeKind
 */
class ViewDetailCodeKind extends TableViewDetail
{
}


/**
 * Class FormCodeKind
 */
class FormCodeKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('codeType');
			$this->addColumnInput ('icon');
			$this->addColumnInput ('order');

			$this->addSeparator(self::coH4);
			$this->addColumnInput ('reportSwitchTitle');
			$this->addColumnInput ('reportPersonTitle');
			$this->addColumnInput ('reportPersonOutTitle');
			$this->addColumnInput ('showInDocRows');
		$this->closeForm ();
	}
}

