<?php

namespace e10pro\fp\libs\apps;
use \Shipard\Base\ApiObject2;


/**
 * class ActionUploadFile
 */
class ActionUploadFile extends ApiObject2
{
	var \e10pro\fp\libs\FilesEngine $filesEngine;

  public function checkData()
  {
    $this->result ['success'] = 0;

    $filePortalUId = $this->uiRouter->urlPath[5] ?? '';
    $fileStorageUId = $this->uiRouter->urlPath[6] ?? '';

    $filesParts = [];
    $urlIdx = 7;
    while (1)
    {
      if (!isset($this->uiRouter->urlPath[$urlIdx]))
        break;
      $filesParts[] = urldecode($this->uiRouter->urlPath[$urlIdx]);
      $urlIdx++;
    }
    $fileName = '/'.implode('/', $filesParts);

    $this->filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());
		$this->filesEngine->setFilePortal($filePortalUId);
		$this->filesEngine->setStorage($fileStorageUId);
    $this->filesEngine->uploadFile($fileName);
  }

  public function run()
  {
    $this->checkData();
  }
}

