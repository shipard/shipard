<?php

namespace vds\base\libs;
use \Shipard\Viewer\TableView;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewCodeBases
 */
class ViewCodeBases extends TableView
{
  var $codeBaseDefs = NULL;
	var $cbParam = NULL;

	public function init ()
	{
    $this->codeBaseDefs = $this->app()->cfgItem('vds.codeBasesDefs', []);

		$this->setMainQueries ();

    $this->usePanelLeft = TRUE;
    $this->linesWidth = 33;

    $enum = [];
    forEach ($this->codeBaseDefs as $cbd)
    {
      $enum[$cbd['ndx']] = ['text' => $cbd['fn'], 'addParams' => ['codeBaseDef' => $cbd['ndx']]];
    }

		$enum[0] = ['text' => 'Vše'];

    $this->cbParam = new \E10\Params ($this->app);
    $this->cbParam->addParam('switch', 'cbdNdx', ['title' => '', 'switch' => $enum, 'list' => 1]);
    $this->cbParam->detectValues();

		parent::init();
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
		$cbdNdx = 0;
		if ($this->cbParam)
			$cbdNdx = intval($this->cbParam->detectValues()['cbdNdx']['value']);

		$cbDef = NULL;

		if ($cbdNdx)
			$cbDef = $this->codeBaseDefs[$cbdNdx] ?? NULL;

		$q [] = 'SELECT cbData.* ';
		array_push ($q, ' FROM [vds_base_codeBasesData] AS [cbData]');
		array_push ($q, ' WHERE 1');

		if ($cbdNdx)
			array_push ($q, ' AND cbData.codeBaseDef = %i', $cbdNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' cbData.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}


		$orderCols = [];
		$primaryOCID = '';

		if ($cbDef)
			$primaryOCID = $cbDef['orderByColumn'] ?? 'ndx';

		if ($primaryOCID !== '')
		{
			if (intval($cbDef['orderByDesc'] ?? 0))
				$orderCols [] = '['.$primaryOCID.']'.' DESC';
			else
				$orderCols [] = '['.$primaryOCID.']';

			$orderCols [] = '[ndx]';
		}

		if (!count($orderCols))
			$orderCols = ['[ndx] DESC', '[fullName]'];

		$this->queryMain ($q, '[cbData].', $orderCols);
		$this->runQuery ($q);
	}


	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->cbParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->cbParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}

