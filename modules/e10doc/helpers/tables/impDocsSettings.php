<?php

namespace e10doc\helpers;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableImpDocsSettings
 */
class TableImpDocsSettings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.helpers.impDocsSettings', 'e10doc_helpers_impDocsSettings', 'Nastavení importu dokladů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['name']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewImpDocsSettings
 */
class ViewImpDocsSettings extends TableView
{
	var $settingsTypes;

  var $personNdx = 0;

	public function init ()
	{
		$this->settingsTypes = $this->app()->cfgItem ('e10doc.helpers.rowsSettingsTypes');

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

    $this->personNdx = intval($this->queryParam('personNdx'));
    if ($this->personNdx)
      $this->addAddParam ('qryHeadPerson', $this->personNdx);

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = ['text' => $item['personFullName'], 'icon' => 'tables/e10.persons.persons', 'class' => ''];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [imps].*, [persons].[fullName] AS [personFullName]');
    array_push ($q, ' FROM [e10doc_helpers_impDocsSettings] AS [imps]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [imps].[qryHeadPerson] = [persons].[ndx]');
		array_push ($q, ' WHERE 1');

    if ($this->personNdx)
      array_push ($q, ' AND [imps].[qryHeadPerson] = %i', $this->personNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [imps].[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[imps].', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormImpDocsSetting
 */
class FormImpDocsSetting extends TableForm
{
	public function renderForm ()
	{
		$st = $this->recData['settingType'];

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('name');
      $this->addColumnInput ('qryHeadPerson');
			$this->addColumnInput ('settingType');

			$this->addSeparator(self::coH2);
			$this->layoutOpen (TableForm::ltGrid);
				if ($st === 0)
				{
					$this->openRow ();
						$this->addStatic('Text řádku', TableForm::coColW3|TableForm::coRight);
						$this->addColumnInput ('qryRowTextType', TableForm::coColW2);
						$this->addColumnInput ('qryRowTextValue',TableForm::coColW7);
					$this->closeRow ();

					$this->openRow (/*'grid-form-tabs'*/);
						$this->addStatic('Kód dodavatele je', TableForm::coColW3|TableForm::coRight);
						$this->addColumnInput ('qryRowSupplierCodeType', TableForm::coColW2);
						$this->addColumnInput ('qryRowSupplierCodeValue',TableForm::coColW7);
					$this->closeRow ();
				}
        $this->openRow ();
          $this->addStatic('Text dokladu', TableForm::coColW3|TableForm::coRight);
          $this->addColumnInput ('qryHeadTextType', TableForm::coColW2);
          $this->addColumnInput ('qryHeadTextValue',TableForm::coColW7);
        $this->closeRow ();
			$this->layoutClose ();

			$this->addSeparator(self::coH2);
			$this->layoutOpen (TableForm::ltGrid);
				$this->openRow ();
					$this->addStatic('Položka', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowItemType', TableForm::coColW2);
					$this->addColumnInput ('valRowItemValue', TableForm::coColW7);
				$this->closeRow ();

				$this->openRow ();
					$this->addStatic('Cena za položku', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowItemPriceType', TableForm::coColW2);
					$this->addColumnInput ('valRowItemPriceValue', TableForm::coColW7);
				$this->closeRow ();

				$this->openRow ();
					$this->addStatic('Středisko', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowCentreType', TableForm::coColW2);
					$this->addColumnInput ('valRowCentreValue', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Zakázka', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowWorkOrderType', TableForm::coColW2);
					$this->addColumnInput ('valRowWorkOrderValue', TableForm::coColW7);
					$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Text řádku', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowTextType', TableForm::coColW2);
					$this->addColumnInput ('valRowTextValue', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Text Dokladu', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valHeadTitleType', TableForm::coColW2);
					$this->addColumnInput ('valHeadTitleValue', TableForm::coColW7);
				$this->closeRow ();
			$this->layoutClose ();
		$this->closeForm ();
	}
}
