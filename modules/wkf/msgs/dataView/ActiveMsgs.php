<?php
namespace wkf\msgs\dataView;
use \lib\dataView\DataView;


/**
 * class ActiveMsgs
 */
class ActiveMsgs extends DataView
{
	var $maxCount = 7;
  var $cbTypeNdx = 0;
  var $varId = '';

  /** @var \wkf\msgs\TableMsgs */
  var $tableMsgs = NULL;

	protected function init()
	{
		parent::init();

    $this->tableMsgs = $this->app()->table('wkf.msgs.msgs');

		$this->checkRequestParamsList('esigns', TRUE);

		if ($this->requestParams['showAs'] === '')
			$this->requestParams['showAs'] = 'html';

    $this->varId = $this->requestParams['varId'] ?? 'msgs';
	}

	protected function loadData()
	{
    $now = new \DateTime();
		$textRenderer = new \lib\core\texts\Renderer($this->app());

		$q [] = 'SELECT msgs.* ';
		array_push ($q, ' FROM [wkf_msgs_msgs] AS [msgs]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND msgs.docState IN %in', [4000, 8000]);
    array_push ($q, ' AND ([msgs].[validFrom] IS NULL', ' OR [msgs].[validFrom] <= %t)', $now);
		array_push ($q, ' AND ([msgs].[validTo] IS NULL', ' OR [msgs].[validTo] >= %d)', $now);

		if (isset($this->requestParams['esigns']) && count($this->requestParams['esigns']))
		{
			error_log("__ESIGN: `{$this->requestParams['esigns'][0]}`");

			array_push ($q, ' AND EXISTS (',
			'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
			' WHERE [msgs].ndx = srcRecId AND srcTableId = %s', 'wkf.msgs.msgs',
			' AND dstTableId = %s', 'mac.iot.esigns',
			' AND docLinks.dstRecId = %i)', $this->requestParams['esigns'][0]);
		}

		array_push ($q, ' ORDER BY msgs.ndx');
    array_push ($q, ' LIMIT %i', $this->maxCount);

    $rows = $this->db()->query($q);

    foreach ($rows as $r)
    {
      $data = $r->toArray();

			$textRenderer->render ($r ['text']);
			$data ['htmlText'] = $textRenderer->code;
      $this->data[$this->varId][] = $data;
    }
		if (isset($this->data[$this->varId]) && count($this->data[$this->varId]))
			$this->template->data['msgsExists'] = $data;

    $this->data['table'] = $this->data['items'];
	}

	protected function renderDataAs($showAs)
	{
		return '';
	}
}
