<?php

namespace e10doc\helpers;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableWkfSectionsRelations
 * @package e10doc\helpers
 */
class TableWkfSectionsRelations extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.helpers.wkfSectionsRelations', 'e10doc_helpers_wkfSectionsRelations', 'Vazby dokladů na sekce workflow');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['name']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function documentSections($docRecData, &$documentSections)
	{
		$q[] = 'SELECT ndx FROM [e10doc_helpers_wkfSectionsRelations]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docType] = %s', $docRecData['docType']);

		$pks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
			$pks[] = $r['ndx'];

		if (count($pks))
		{
			$q = [];
			$q[] = 'SELECT docLinks.dstRecId';
			array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
			array_push($q, ' WHERE srcTableId = %s', 'e10doc.helpers.wkfSectionsRelations', 'AND dstTableId = %s', 'wkf.base.sections');
			array_push($q, ' AND docLinks.linkId = %s', 'e10docs-wkfSectionsRelations', 'AND srcRecId IN %in', $pks);

			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$documentSections[] = $r['dstRecId'];
			}
		}

		if (!count($documentSections))
		{
			/** @var \wkf\core\TableIssues $tableIssues */
			$tableIssues = $this->app()->table('wkf.core.issues');

			if ($docRecData['docType'] === 'bank')
				$documentSections[] = $tableIssues->defaultSection(54);
			elseif ($docRecData['docType'] === 'cash')
				$documentSections[] = $tableIssues->defaultSection(55);
			else
				$documentSections[] = $tableIssues->defaultSection(51);
		}
	}
}


/**
 * Class ViewWkfSectionsRelations
 * @package e10doc\helpers
 */
class ViewWkfSectionsRelations extends TableView
{
	var $sections = [];
	var $docsTypes;

	public function init ()
	{
		$this->docsTypes = $this->app->cfgItem ('e10.docs.types', FALSE);

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];

		$props = [];

		$dt = $this->docsTypes[$item['docType']];
		$props[] = ['text' => $dt['pluralName'], 'icon' => $dt['icon'], 'class' => 'label label-info'];

		$listItem ['t2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [sr].* FROM [e10doc_helpers_wkfSectionsRelations] AS [sr]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [sr].[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[sr].', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		// -- sections
		$q[] = 'SELECT docLinks.*, [sections].fullName AS sectionName';
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [wkf_base_sections] AS [sections] ON docLinks.dstRecId = [sections].ndx');
		array_push($q, ' WHERE srcTableId = %s', 'e10doc.helpers.wkfSectionsRelations', 'AND dstTableId = %s', 'wkf.base.sections');
		array_push($q, ' AND docLinks.linkId = %s', 'e10docs-wkfSectionsRelations', 'AND srcRecId IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = ['text' => $r['sectionName'], 'icon' => 'icon-columns', 'class' => 'label label-default'];
			$this->sections[$r['srcRecId']][] = $l;
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->sections [$item ['pk']]))
		{
			$item['t3'] = $this->sections [$item ['pk']];
		}
	}
}


/**
 * Class FormWkfSectionsRelation
 * @package e10doc\helpers
 */
class FormWkfSectionsRelation extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('name');
			$this->addColumnInput ('docType');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}
