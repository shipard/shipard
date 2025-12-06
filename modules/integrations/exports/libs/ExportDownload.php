<?php

namespace integrations\exports\libs;

use \Shipard\Utils\Json, \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * Class ExportDownload
 */
class ExportDownload extends Utility
{
  var $apiKey = '';
  var $exportId = '';
  var $exportRecData = NULL;
  var $export = NULL;

	var $exportData = [];

	protected function getExportData ()
	{
    $export = $this->db()->query('SELECT * FROM [integrations_exports_exports] WHERE 1',
                              ' AND [apiKey] = %s', $this->apiKey,
                              ' AND [exportID] = %s', $this->exportId,
                              ' AND [docState] = %i', 4000,
                              )->fetch();

    if (!$export)
    {
      $this->exportData['error'] = 1;
      $this->exportData['errMsg'] = 'invalid exportId or apiKey';
      return;
    }

		$exportEngine = new \integrations\exports\libs\ExportEngine($this->app);
		$exportEngine->setExport($export['ndx']);
		$exportEngine->run();

    $this->exportData = $exportEngine->data;
	}

	public function init ()
	{
    $this->exportId = $this->app->requestPath(2);
    $this->apiKey = $this->app->requestPath(3);
	}

	public function run ()
	{
		$this->getExportData();

		$response = new Response ($this->app);
    $response->setMimeType('application/json');
    $response->setRawData(Json::lint($this->exportData));
		return $response;
	}
}
