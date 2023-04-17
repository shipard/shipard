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
  var $varId = '';

  /** @var \vds\base\TableCodeBasesData */
  var $tableData = NULL;

	protected function init()
	{
		parent::init();

    $this->tableData = $this->app()->table('vds.base.codeBasesData');

		if (isset($this->requestParams['cbType']))
		{
			$this->cbTypeNdx = intval($this->requestParams['cbType']);
		}

		if ($this->requestParams['showAs'] === '')
			$this->requestParams['showAs'] = 'html';

    $this->varId = $this->requestParams['varId'] ?? 'cbItems';
	}

	protected function loadData()
	{
    $today = Utils::today();

		$q [] = 'SELECT cbData.* ';
		array_push ($q, ' FROM [vds_base_codeBasesData] AS [cbData]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND cbData.codeBaseDef = %i', $this->cbTypeNdx);
    array_push ($q, ' AND cbData.dateFrom >= %d', $today);
    array_push ($q, ' ORDER BY cbData.dateFrom');
		//array_push ($q, ' ORDER BY cbData.ndx DESC');
    array_push ($q, ' LIMIT %i', $this->maxCount);

    $rows = $this->db()->query($q);

    foreach ($rows as $r)
    {
      $sci = $this->tableData->subColumnsInfo($r, 'data');

      $data = Json::decode($r['data']);
      $data['recFullName'] = $r['fullName'];
      $data['recShortName'] = $r['fullName'];
      $data['recDateFrom'] = $r['dateFrom'];
      $data['recDateTo'] = $r['dateTo'];

      foreach ($sci['columns'] as $colDef)
      {
        if (!isset($colDef['reference']))
          continue;
        $refTable = $this->app()->table($colDef['reference']);
        if (!$refTable)
          continue;
        $refItem = $refTable->loadItem($data[$colDef['id']]);
        if (!$refItem)
          continue;
        $data[$colDef['id']] = $refItem;
      }

      $this->data['items'][] = $data;
      $this->template->data[$this->varId][] = $data;
    }

    $this->data['table'] = $this->data['items'];
	}

	protected function renderDataAs($showAs)
	{
		return '';
	}
}
