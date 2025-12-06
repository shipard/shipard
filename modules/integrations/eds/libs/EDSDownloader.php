<?php

namespace integrations\eds\libs;
use \Shipard\Utils\Json;


/**
 * class EDSDownloader
 */
class EDSDownloader extends \Shipard\Base\Utility
{
  var $dataSetNdx = 0;
  var $dataSetRecData = NULL;
  var $dataSetCfg = NULL;

  var $edsEngine = NULL;

  var $data = NULL;

  public function setDataSet($dataSetNdx)
  {
    $this->dataSetNdx = $dataSetNdx;
    $this->dataSetRecData = $this->app()->loadItem($this->dataSetNdx, 'integrations.eds.sets');
    $this->dataSetCfg = $this->app()->cfgItem('integrations.eds.types.'.$this->dataSetRecData['edsType'], NULL);

    if (isset($this->dataSetCfg['classId']))
    {
      $this->edsEngine = $this->app()->createObject($this->dataSetCfg['classId']);
    }
  }

  public function downloadData()
  {
    if (!$this->dataSetRecData)
      return FALSE;

    $url = $this->dataSetRecData['apiUrl'];
    $this->data = $this->httpGet($url, true);

    if ($this->app()->debug)
    {
      echo "Downloaded data set #{$this->dataSetNdx} from {$url}:\n".Json::lint($this->data)."\n";
    }

    if ($this->edsEngine)
    {
      $this->edsEngine->postProcessData($this->data);
    }

    $exist = $this->app()->db()->query('SELECT * FROM [integrations_eds_data] WHERE [edsSet] = %i', $this->dataSetNdx)->fetch();
    if ($exist)
    {
      $update = ['data' => Json::lint($this->data), 'dateUpdated' => new \DateTime(), 'dateNextUpdate' => $this->nextUpdate()];
      $this->app()->db()->query('UPDATE [integrations_eds_data] SET ', $update, ' WHERE [edsSet] = %i', $this->dataSetNdx);
    }
    else
    {
      $insert = [
        'edsSet' => $this->dataSetNdx,
        'dateUpdated' => new \DateTime(),
        'dateNextUpdate' => $this->nextUpdate(),
        'data' => Json::lint($this->data),
      ];

      $this->app()->db()->query('INSERT INTO [integrations_eds_data]', $insert);
    }
  }

  protected function nextUpdate()
  {
    $nu = new \DateTime("+15 minutes");
    return $nu;
  }

  public function postProcess()
  {
    if (!$this->dataSetRecData)
      return FALSE;

    $dataRec = $this->app()->db()->query('SELECT * FROM [integrations_eds_data] WHERE [edsSet] = %i', $this->dataSetNdx)->fetch();
    if (!$dataRec)
      return FALSE;

    $data = Json::decode($dataRec['data']);

    if ($this->edsEngine)
    {
      $this->edsEngine->postProcessData($data);
    }

    $update = ['data' => Json::lint($data), 'dateUpdated' => new \DateTime()];
    $this->app()->db()->query('UPDATE [integrations_eds_data] SET ', $update, ' WHERE [edsSet] = %i', $this->dataSetNdx);
  }

  public function run()
  {
    $this->downloadData();
  }
}
