<?php

namespace e10\base;

use \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TableNomencTypes
 * @package e10\base
 */
class TableNomencTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.nomencTypes', 'e10_base_nomencTypes', 'Typy nomenklatury');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$allowed = ['cz-nuts' => [3, 4]];
		$rows = $this->app()->db->query ('SELECT * from [e10_base_nomencTypes] WHERE [docState] != 9800 ORDER BY [id]');

		foreach ($rows as $r)
		{
			if (!key_exists($r['id'], $allowed))
				continue;
			foreach ($allowed[$r['id']] as $level)
			{
				$this->saveConfigOneType($r['id'], $r['ndx'], $level);
			}
		}
	}

	function saveConfigOneType ($typeId, $typeNdx, $level)
	{
		$list = [];
		$list ['0'] = ['ndx' => 0, 'fn' => '-- neurčeno --', 'sn' => '---', 'state' => 5];

		$q[] = 'SELECT * FROM [e10_base_nomencItems] WHERE 1';
		array_push($q, ' AND [nomencType] = %i', $typeNdx);
		array_push($q, ' AND [level] = %i', $level);
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' ORDER BY [fullName]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$enumIntNdx = $r['enumIntNdx'];
			if (!$enumIntNdx)
			{
				$max = $this->db()->query ('SELECT MAX(enumIntNdx) AS [enumIntNdx] FROM [e10_base_nomencItems] WHERE 1',
						' AND [nomencType] = %i', $typeNdx, ' AND [level] = %i', $level,
						' AND [docState] != %i', 9800)->fetch();
				$enumIntNdx = $max['enumIntNdx'] + 1;
				$this->db()->query ('UPDATE [e10_base_nomencItems] SET [enumIntNdx] = %i', $enumIntNdx, ' WHERE [ndx] = %i', $r['ndx']);
			}

			$list [strval($enumIntNdx)] = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'state' => $r['docStateMain']];
		}

		// save to file
		$nomencEnumId = $typeId.'-'.$level;
		$cfg ['nomenc'][$nomencEnumId] = $list;
		file_put_contents(__APP_DIR__ . '/config/_nomenc.'.$nomencEnumId.'.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewNomencTypes
 * @package e10\base
 */
class ViewNomencTypes extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['shortName'];
		$listItem ['t2'] = $item['fullName'];
		$listItem ['i2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * from [e10_base_nomencTypes] WHERE 1';

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s OR [id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');


		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailNomencType
 * @package e10\base
 */
class ViewDetailNomencType extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10.base.nomencItems', 'default', ['nomencType' => $this->item ['ndx']]);
	}
}


/**
 * Class FormNomencType
 * @package e10\base
 */
class FormNomencType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
		$this->closeForm ();
	}
}

