<?php

namespace vds\base;
use \Shipard\Utils\Json, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableCodeBasesData
 */
class TableCodeBasesData extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('vds.base.codeBasesData', 'vds_base_codeBasesData', 'Data vlastních číselníků');
	}

  public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'data')
		{
			$codeBaseDef = $this->app()->cfgItem('vds.codeBasesDefs.' . $recData['codeBaseDef'], NULL);

			if (!$codeBaseDef || !isset($codeBaseDef['vds']) || !$codeBaseDef['vds'])
				return FALSE;

      $vdsDefRecData = $this->app()->loadItem($codeBaseDef['vds'], 'vds.base.defs');
      if (!$vdsDefRecData)
        return FALSE;

      $vds = Json::decode($vdsDefRecData['structure']);
      if (!$vds || !isset($vds['fields']))
        return FALSE;

			return $vds['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewCodeBasesData
 */
class ViewCodeBasesData extends TableView
{
	public function init ()
	{
		parent::init();

//		$this->objectSubType = TableView::vsDetail;
//		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT cbData.* ';
		array_push ($q, ' FROM [vds_base_codeBasesData] AS [cbData]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' cbData.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[cbData].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormCodeBaseDataItem
 */
class FormCodeBaseDataItem extends TableForm
{
	public function renderForm ()
	{
    $codeBaseDef = $this->app()->cfgItem('vds.codeBasesDefs.' . $this->recData['codeBaseDef'], NULL);
    $useFullName = intval($codeBaseDef['useFullName'] ?? 0);
    $useShortName = intval($codeBaseDef['useShortName'] ?? 0);
    $useDates = intval($codeBaseDef['useDates'] ?? 0);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		//$tabs ['tabs'][] = ['text' => 'Struktura', 'icon' => 'formStructure'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
          $this->addColumnInput ('codeBaseDef');
          if ($useFullName)
            $this->addColumnInput ('fullName');
          if ($useShortName)
					  $this->addColumnInput ('shortName');

          if ($useDates === 1)
          {
            $this->addColumnInput ('dateFrom');
          }
          elseif ($useDates === 2)
          {
            $this->addColumnInput ('dateFrom');
            $this->addColumnInput ('dateTo');
          }

          $this->addSubColumns ('data');
				$this->closeTab();
        /*
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('structure', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab ();
        */
			$this->closeTabs();
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
  {
    $codeBaseDef = $this->app()->cfgItem('vds.codeBasesDefs.' . $this->recData['codeBaseDef'], NULL);
    $useDates = intval($codeBaseDef['useDates'] ?? 0);

    switch ($colDef ['sql'])
    {
      case	'dateFrom': return ($useDates === 1) ? 'Datum' : 'Datum OD';
    }
    return parent::columnLabel ($colDef, $options);
  }
}
