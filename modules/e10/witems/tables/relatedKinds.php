<?php

namespace e10\witems;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Utils\Utils;


/**
 * class TableRelatedKinds
 */
class TableRelatedKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.relatedKinds', 'e10_witems_relatedKinds', 'Druhy souvisejících položek');
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
}


/**
 * class ViewRelatedKinds
 */
class ViewRelatedKinds extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10_witems_relatedKinds]';
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
			$props [] = ['icon' => 'icon-sortsystem/iconOrder', 'text' => Utils::nf ($item ['order'], 0)];
		if (count($props))
			$listItem ['i2'] = $props;


		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * class ViewDetailRelatedKind
 */
class ViewDetailRelatedKind extends TableViewDetail
{
}


/**
 * class FormRelatedKind
 */
class FormRelatedKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('useAsVariantItem');
			$this->addColumnInput ('icon');
			$this->addColumnInput ('order');
		$this->closeForm ();
	}
}

