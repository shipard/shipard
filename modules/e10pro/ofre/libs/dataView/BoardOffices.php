<?php

namespace e10pro\ofre\libs\dataView;
use \lib\dataView\DataView, \Shipard\Utils\Utils, \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;

/**
 * class BoardOffices
 */
class BoardOffices extends DataView
{
  var $pageNumber = 0;
  var $cntCols = 0;
  var $cntRows = 0;

  var $countCells = 0;
  var $countPages = 0;
  var $pageSize = 0;

  var $pageBtnSizeX = 0;
  var $pageBtnSizeY = 0;
  var $pageBtnSizeXGap = 0;

  var $displayWidth = 2880;
  var $displayHeight = 2160;

	protected function init()
	{
		parent::init();

		$this->checkRequestParamsList('docKinds', TRUE);
		$this->checkRequestParamsList('withLabels');
		$this->checkRequestParamsList('withoutLabels');

    $this->cntCols = $this->requestParam ('cntCols', 5);
    $this->cntRows = $this->requestParam ('cntRows', 10);

    if ($this->app()->testGetParam('pageNumber') !== '')
      $this->pageNumber = intval($this->app()->testGetParam('pageNumber'));

    $this->pageSize = $this->cntCols * $this->cntRows;

    $this->countCells = $this->countCells();
    $this->countPages = intval($this->countCells / $this->pageSize) + 1;
	}

	protected function loadData()
	{
		$q [] = 'SELECT wo.*, ';
    array_push ($q, ' custs.fullName AS custName');
		array_push ($q, ' FROM [e10mnf_core_workOrders] AS wo');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS custs ON wo.customer = custs.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wo.docStateMain = %i', 1);

		if (isset($this->requestParams['withLabels']) && count($this->requestParams['withLabels']))
		{
			array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->requestParams['withLabels'],
				')');
		}
		if (isset($this->requestParams['withoutLabels']) && count($this->requestParams['withoutLabels']))
		{
			array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->requestParams['withoutLabels'],
				')');
		}

    if (isset($this->requestParams['docKinds']))
      array_push ($q, ' AND wo.docKind IN %in', $this->requestParams['docKinds']);
		array_push ($q, ' ORDER BY custs.[fullName], custs.[ndx]');

    $sqlLimitStart = $this->pageNumber * $this->pageSize;
    array_push ($q, ' LIMIT %i', $sqlLimitStart, ', %i', $this->pageSize);
		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
        'ndx' => $r['ndx'],
        'custName' => $r['custName'],
      ];

      $vdsData = Json::decode($r['vdsData']);
      if ($vdsData)
      {
        if (isset($vdsData['publicEmail']) && $vdsData['publicEmail'] !== '')
          $item['email'] = $vdsData['publicEmail'];
      }

			$t[$r['customer']] = $item;
			$pks[] = $r['customer'];
		}

    // -- properties
    $personsProperties = UtilsBase::getPropertiesTable ($this->app, 'e10.persons.persons', $pks);
    foreach ($personsProperties as $personNdx => $pp)
    {
      $t[$personNdx]['properties'] = $pp;

      if (isset($pp['ids']['oid'][0]))
        $t[$personNdx]['oid'] = $pp['ids']['oid'][0]['value'];
    }

		$this->data['header'] = ['#' => '#', 'id' => 'id', 'custName' => 'Jméno', 'email' => 'E-mail', 'phone' => 'Telefon'];
		$this->data['table'] = $t;
	}

	protected function renderDataAs($showAs)
	{
		//if ($showAs === 'html')
			return $this->renderDataAsBoardTable($showAs);

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsBoardTable($showAs)
	{
    $c = '';
    $tableCells = '';

    $colNum = 0;
    $rowNum = 0;
		$tableCells .= "<table style='width: 100%;' class='bo-offices'>\n";

		foreach ($this->data['table'] as $person)
		{
      if ($colNum % $this->cntCols === 0)
      {
        $rowNum++;

        if ($rowNum > $this->cntRows)
          break;

        if ($colNum)
        $tableCells .= "</tr>\n";

        $tableCells .= "<tr data-style='border-bottom: 1px solid rgba(0,0,0,.2);'>";
      }
      $tableCells .= "<td id='office-btn-{$person['ndx']}' data-link-url='?officeNdx={$person['ndx']}' data-link-type='1' data-page-number='-1' style='vertical-align: top; width:20%;' class='sc-click-element-pu'>";

      $tableCells .= "<div style=' border-left: 6px solid #AAA; padding: .4rem; margin: 1rem; height: 100%;'>";

      $tableCells .= '<h5>'.utils::es($person['custName']).'</h5>';
      if (isset($person['oid']))
      $tableCells .= "<span class='text-nowrap'>".Utils::es('IČ').' '.Utils::es($person['oid']).'</span> ';
      if (isset($person['email']))
      $tableCells .= "<span class='text-nowrap'>".$this->app()->ui()->icon('user/envelope').' '.Utils::es($person['email']).'</span><br>';

      $tableCells .= "</div>";
      $tableCells .= "</td>";

      $colNum++;
		}
    $tableCells .= "</tr>\n";
		$tableCells .= "</table>\n";

    $c .= $tableCells;

    $this->data['tableCells'] = $tableCells;
    $this->data['pageNumber'] = strval($this->pageNumber);

    $this->pageBtnSizeX = 255;
    $this->pageBtnSizeY = 100;
    $this->pageBtnSizeXGap = 20;

    $paginationDivOpen = '';
    $paginationDivOpen .= "<div class='bo-pagination-bar' style=''";
    $paginationDivOpen .= " id='shp-sc-page-info'";
    $paginationDivOpen .= " data-this-id='page-btn-{$this->pageNumber}'";
    $paginationDivOpen .= ">";
    $this->data['paginationDivOpen'] = $paginationDivOpen;

    $c .= "\n\n".$paginationDivOpen;

    $c .= "<table style='width: 100%;' class='bo-pagination'>\n";
    $c .= "<tr style='height: {$this->pageBtnSizeY}px;'>\n";

    $c .= "<td style='width: 10%;'>";
    $c .= "</td>\n";

    $c .= "<td style='width: 80%; vertical-align: middle; text-align: center; padding:0; margin: 0; font-size: 40px;'>";
    $c .= "<div style='display:flex; align-items: center; justify-content: center;'>";

    $paginationButtons = '';
    for ($i = 0; $i < $this->countPages; $i++)
    {
      $active = ($this->pageNumber === $i) ? ' active' : '';
      $paginationButtons .= "<div id='page-btn-$i' data-link-url='?pageNumber={$i}' data-link-type='0' data-page-number='{$i}' class='sc-click-element-fs board-office-page-btn$active'>".strval($i + 1).'</div>';
    }

    $this->data['paginationButtons'] = $paginationButtons;
    $c .= $paginationButtons;
    $c .= "</div>\n";

    $c .= "</td>\n";

    $c .= "<td style='width: 10%; text-align: right;'>";
    $c .= "</td>\n";
    $c .= "</tr>\n";
    $c .= "</table>\n";
    $c .= '</div>';

    $c .= "\n<textarea id='shp-sc-page-info-result' style='display: none;'></textarea>";

    $jsScripts = '';
    $jsScripts .= "<script type='text/javascript' src='".$this->app()->dsRoot."/www-root/templates/web/libs/scCreator.js?v=22'></script>";
    $this->data['jsScripts'] = $jsScripts;
    $c .= $jsScripts;

    if ($showAs === 'none')
      return '';

		return $c;
	}

  protected function countCells()
  {
    $q [] = 'SELECT COUNT(*) AS [cnt]';
    array_push ($q, ' FROM [e10mnf_core_workOrders] AS wo');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS custs ON wo.customer = custs.ndx');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND wo.docStateMain = %i', 1);

		if (isset($this->requestParams['withLabels']) && count($this->requestParams['withLabels']))
		{
			array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->requestParams['withLabels'],
				')');
		}
		if (isset($this->requestParams['withoutLabels']) && count($this->requestParams['withoutLabels']))
		{
			array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10_base_clsf WHERE wo.ndx = recid AND tableId = %s', 'e10mnf.core.workOrders',
				' AND [clsfItem] IN %in', $this->requestParams['withoutLabels'],
				')');
		}

    if (isset($this->requestParams['docKinds']))
      array_push ($q, ' AND wo.docKind IN %in', $this->requestParams['docKinds']);
    array_push ($q, ' ORDER BY custs.[fullName], custs.[ndx]');

    $cnt = $this->db()->query($q)->fetch();
    if ($cnt)
      return intval($cnt['cnt']);

    return 0;
  }
}
