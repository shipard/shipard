<?php

namespace e10\witems\libs;
use \Shipard\Viewer\TableViewGrid;


/**
 * class ViewItemCodes
 */
class ViewItemCodes extends TableViewGrid
{
	var $docTypes;

	public function init ()
	{
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		//$this->usePanelLeft = TRUE;
		//$this->createLeftPanel();

		parent::init();


		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

//		$this->setMainQueries ($mq);

		$g = [
      'id' => 'ID',
			'item' => 'Položka',
      'codeId' => 'Kód',
      'codeInfo' => 'Nastavení',
		];

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
    $personTypes = $this->table->columnInfoEnum ('personType', 'cfgText');

    $codeKind = $this->app()->cfgItem('e10.witems.codesKinds.'.$item['codeKind']);
    $codeDir = $this->app()->cfgItem('e10.witems.codeDirs.'.$item['codeDir']);
    $refType = $codeKind['refType'] ?? 0;
    $askDir = $codeKind['askDir'] ?? 0;
    $askPerson = $codeKind['askPerson'] ?? 0;
    $askPersonType = $codeKind['askPersonType'] ?? 0;




		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $listItem ['id'] = $item['itemId'];

    $listItem ['item'] = [['text' => $item['itemFullName'], 'class' => 'block']];
    $listItem ['codeInfo'] = [];

    if($askDir)
      $listItem['codeInfo'][] = ['text' => $codeDir['sn'] ?? '!!!', 'class' => ''];
    if ($item['person'])
      $listItem['codeInfo'][] = ['text' => $item['personName'], 'class' => 'block', 'icon' => 'system/personCompany'];
    if ($item['personsGroup'])
      $listItem['codeInfo'][] = ['text' => $item['groupName'], 'class' => 'block', 'icon' => 'tables/e10.persons.groups'];
    if ($askPersonType)
      $listItem['codeInfo'][] = ['text' => $personTypes[$item['personType']] ?? '!!!', 'class' => 'block', 'icon' => 'tables/e10.persons.persons'];

    $listItem ['codeId'] = $item['itemCodeText'];
		return $listItem;
	}

	public function selectRows ()
	{
		$mq = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [itemCodes].*,');
    array_push ($q, ' [items].[fullName] AS [itemFullName], [items].[id] AS [itemId],');
    array_push ($q, ' persons.fullName AS personName, personsGroups.name AS groupName,');
		array_push ($q, ' nomenc.fullName AS nomencName');
		array_push ($q, ' FROM [e10_witems_itemCodes] AS [itemCodes]');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [itemCodes].[item] = [items].[ndx]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON itemCodes.person = persons.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_groups] AS personsGroups ON itemCodes.personsGroup = personsGroups.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomenc ON itemCodes.itemCodeNomenc = nomenc.ndx');

		array_push ($q, ' WHERE 1');


		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [items].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [itemCodes].[itemCodeText] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [balances].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}


		array_push ($q, ' ORDER BY [itemFullName], [ndx]');


    array_push ($q, $this->sqlLimit());


		//$this->queryMain ($q, '[ba].', ['[balances].[order]', '[balances].[fullName]', '[ba].[systemOrder]', '[accountId]', '[ndx]']);
		$this->runQuery ($q);
	}
}



