<?php

namespace e10doc\waster;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableWasteReturns
 */
class TableWasteReturns extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.waster.wasteReturns', 'e10doc_waster_wasteReturns', 'Hlášení o odpadech');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		return $hdr;
	}
}


/**
 * class ViewWasteReturns
 */
class ViewWasteReturns extends TableView
{
	public function init ()
	{

		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['id'] = $item['ndx'];

    $listItem ['t1'] = $item['year'];
		$listItem ['t2'] = $item['title'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push($q, 'SELECT [wr].*');
    array_push($q, ' FROM [e10doc_waster_wasteReturns] AS [wr]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q,' [wr].[title] LIKE %s', '%'.$fts.'%');
			array_push($q, ')');
		}

		$this->queryMain($q, 'wr.', ['[year] DESC', '[ndx]']);
		$this->runQuery($q);
	}
}


/**
 * class FormWasteReturn
 */
class FormWasteReturn extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
      $this->addColumnInput ('year');
			$this->addColumnInput ('dateFrom');
			$this->addColumnInput ('dateTo');
			$this->addColumnInput ('title');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('person');
			$this->addColumnInput ('personORPId');
			$this->addColumnInput ('personZUJId');
			$this->addColumnInput ('personOffice');
			$this->addColumnInput ('personOfficeORPId');
			$this->addColumnInput ('personOfficeZUJId');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('returnToORPId');
			$this->addColumnInput ('returnToORPName');
			$this->addSeparator(self::coH4);
			$this->addStatic(['text' => '        Hlášení vyplnil', 'class' => 'e10-bold pl1']);
			$this->addColumnInput ('authorFirstName');
			$this->addColumnInput ('authorLastName');
			$this->addColumnInput ('authorEmail');
			$this->addColumnInput ('authorPhonePrefix');
			$this->addColumnInput ('authorPhone');
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.waster.wasteReturns' && $srcColumnId === 'personOffice')
		{
			$cp = [
				'personNdx' => strval ($allRecData ['recData']['person'])
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * class ViewDetailWasteReturn
 */
class ViewDetailWasteReturn extends TableViewDetail
{
}
