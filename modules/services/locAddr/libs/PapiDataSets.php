<?php

namespace services\locAddr\libs;
use \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * Class PapiDataSets
 */
class PapiDataSets extends Utility
{
	var $dataSetId = '';
	var $object = [];

	var $dataSets = ['adm-units'];

	protected function getDataSet ()
	{
    if (!$this->dataSetId || !in_array($this->dataSetId, $this->dataSets))
		{
			$this->object['error'] = 1;
			$this->object['errMsg'] = 'dataSetId not found';
			return;
		}

		if ($this->dataSetId === 'adm-units')
		{
			$exporter = new \services\locAddr\libs\AdmUnitsExport($this->app);
			$exporter->country = 60;
			$exporter->export();
			$this->object['data'] = $exporter->data;
		}

    $this->object['error'] = 0;
	}

	public function init ()
	{
    $this->dataSetId = $this->app->requestPath(2);
	}

	public function run ()
	{
		$this->getDataSet();

		$response = new Response ($this->app);
		$response->add ('objectType', 'dataSet');
		$response->setMimeType('application/json');
		$response->add ('object', $this->object);
		return $response;
	}
}
