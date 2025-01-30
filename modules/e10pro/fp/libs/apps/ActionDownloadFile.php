<?php

namespace e10pro\fp\libs\apps;
use \Shipard\Base\ApiObject2;


class ActionDownloadFile extends ApiObject2
{
	var \e10pro\fp\libs\FilesEngine $filesEngine;
	var $filePortalNdx = 1;

  public function checkData()
  {
    $filesParts = [];
    $urlIdx = 4;
    while (1)
    {
      if (!isset($this->uiRouter->urlPath[$urlIdx]))
        break;
      $filesParts[] = $this->uiRouter->urlPath[$urlIdx];
      $urlIdx++;
    }
    $fileName = '/'.implode('/', $filesParts);


    $this->filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());
		$this->filesEngine->setFilePortal($this->filePortalNdx);
		$this->filesEngine->setStorage(1);
    $this->filesEngine->downloadFile($fileName);

    $this->result ['success'] = 0;
  }

  public function run()
  {
    $this->checkData();
  }
}

