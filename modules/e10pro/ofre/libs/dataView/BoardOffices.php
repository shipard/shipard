<?php

namespace e10pro\ofre\libs\dataView;
use \lib\dataView\DataView, \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class BoardOffices
 */
class BoardOffices extends DataView
{
	protected function init()
	{
		parent::init();

		$this->checkRequestParamsList('docKinds', TRUE);
	}

	protected function loadData()
	{
		$q [] = 'SELECT wo.*, ';
    array_push ($q, ' custs.fullName AS custName');
		array_push ($q, ' FROM [e10mnf_core_workOrders] AS wo');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS custs ON wo.customer = custs.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wo.docStateMain = %i', 1);

    if (isset($this->requestParams['docKinds']))
      array_push ($q, ' AND wo.docKind IN %in', $this->requestParams['docKinds']);
		array_push ($q, ' ORDER BY custs.[fullName], custs.[ndx]');

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
    $personsProperties = \E10\Base\getPropertiesTable ($this->app, 'e10.persons.persons', $pks);
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
			return $this->renderDataAsBoardTable();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsBoardTable()
	{
    $cntCols = $this->requestParam ('cntCols', 5);

    $c = '';

    $colNum = 0;
		$c .= "<table style='width: 100%;'>";
    $c .= "<tr>";
		foreach ($this->data['table'] as $person)
		{
      if ($colNum % $cntCols === 0)
      {
        $c .= "</tr>";
        $c .= "<tr data-style='border-bottom: 1px solid rgba(0,0,0,.2);'>";
      }
      $c .= "<td style='vertical-align: top;'>";

      $c .= "<div style=' border-left: 6px solid #AAA; padding: .4rem; margin: 1rem; height: 100%;'>";

      $c .= '<h5>'.utils::es($person['custName']).'</h5>';
      if (isset($person['oid']))
        $c .= "<span class='text-nowrap'>".Utils::es('IČ').' '.Utils::es($person['oid']).'</span> ';
      if (isset($person['email']))
        $c .= "<span class='text-nowrap'>".$this->app()->ui()->icon('user/envelope').' '.Utils::es($person['email']).'</span><br>';

      $c .= "</div>";
      $c .= "</td>";

        $colNum++;

		}
    $c .= "</tr>";
		$c .= '</table>';

		return $c;
	}
}
