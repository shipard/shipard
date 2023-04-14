<?php

namespace vds\base\dataView;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Str;
use \Shipard\Utils\Json;
use \lib\dataView\DataView;


/**
 * class DataViewCBItems
 */
class DataViewCBItems extends DataView
{
	var $maxCount = 7;
  var $cbTypeNdx = 0;

	protected function init()
	{
		parent::init();

		if (isset($this->requestParams['cbType']))
		{
			$this->cbTypeNdx = intval($this->requestParams['cbType']);
		}

		if ($this->requestParams['showAs'] === '')
			$this->requestParams['showAs'] = 'html';
	}

	protected function loadData()
	{
		$q [] = 'SELECT cbData.* ';
		array_push ($q, ' FROM [vds_base_codeBasesData] AS [cbData]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND cbData.codeBaseDef = %i', $this->cbTypeNdx);
		array_push ($q, ' ORDER BY cbData.ndx DESC');
    array_push ($q, ' LIMIT %i', $this->maxCount);

    $rows = $this->db()->query($q);

    foreach ($rows as $r)
    {
      $data = Json::decode($r['data']);
      $data['_fullName'] = $r['fullName'];
      $this->data['items'][] = $data;
      $this->template->data['cbItems'][] = $data;
    }

    $this->data['table'] = $this->data['items'];
	}

	protected function renderDataAs($showAs)
	{
		return '';
	}
}
