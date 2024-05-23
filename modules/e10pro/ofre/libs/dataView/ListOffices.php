<?php

namespace e10pro\ofre\libs\dataView;
use \lib\dataView\DataView, \Shipard\Utils\Utils, \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;

/**
 * class ListOffices
 */
class ListOffices extends DataView
{
	var $orderBy = '';

  protected function init()
	{
		parent::init();

		$this->checkRequestParamsList('docKinds', TRUE);
		$this->checkRequestParamsList('withLabels');
		$this->checkRequestParamsList('withoutLabels');
    $this->checkRequestParamsList('floors', TRUE);
		$this->checkRequestParamsList('directions', TRUE);

		$this->orderBy = $this->requestParam('orderBy', 'wo');
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

		if ($this->orderBy === 'person')
			array_push ($q, ' ORDER BY custs.[fullName], custs.[ndx]');
		else
			array_push ($q, ' ORDER BY wo.[title], wo.[ndx]');

		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
        'ndx' => $r['ndx'],
        'custName' => $r['custName'],
				'officeTitle' => ($r['title'] !== '') ? $r['title'] : $r['custName'],
      ];

      $vdsData = Json::decode($r['vdsData']);
      if ($vdsData)
      {
        if (isset($this->requestParams['floors']) && count($this->requestParams['floors']))
        {
          if (!in_array(($vdsData['floor'] ?? '!'), $this->requestParams['floors']))
            continue;
        }
        if (isset($this->requestParams['directions']) && count($this->requestParams['directions']))
        {
          if (!in_array(($vdsData['direction'] ?? '!'), $this->requestParams['directions']))
            continue;
        }

        $item['data'] = $vdsData;

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

		$this->data['offices'] = array_values($t);
	}

	protected function renderDataAs($showAs)
	{
    return '';
	}
}
