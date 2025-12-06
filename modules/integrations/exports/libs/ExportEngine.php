<?php

namespace integrations\exports\libs;
use \Shipard\Utils\Json;


/**
 * class ExportEngine
 */
class ExportEngine extends \Shipard\Base\Utility
{
  var $exportNdx = 0;
  var $exportRecData = NULL;
  var $exportCfg = NULL;

  var $data = NULL;
  var \integrations\exports\libs\TemplateExport $template;


  public function setExport($exportNdx)
  {
    $this->exportNdx = $exportNdx;
    $this->exportRecData = $this->app()->loadItem($this->exportNdx, 'integrations.exports.exports');
    $this->exportCfg = $this->app()->cfgItem('integrations.exports.types.'.$this->exportRecData['exportType'], NULL);
  }

  public function exportData()
  {
    if (!$this->exportRecData)
      return FALSE;

    $this->template = new \integrations\exports\libs\TemplateExport($this->app());
    $dataStr = $this->template->renderTextSafe($this->exportRecData['codeTemplate']);
    $data = Json::decode($dataStr);
    $this->data = $data;

    if ($this->app()->debug)
      echo "Exported data:\n".Json::lint($this->data)."\n";
  }

  protected function nextUpdate()
  {
    $nu = new \DateTime("+15 minutes");
    return $nu;
  }

  public function run()
  {
    $this->exportData();
  }
}
