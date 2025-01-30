<?php

namespace e10pro\fp\libs;
use \Shipard\Utils\Utils;

/**
 * class FilesEngine
 */
class FilesEngine extends \Shipard\Base\Utility
{
  var $fpNdx = 0;
  var $fpCfg = NULL;

  var $storageNdx = 0;
  var $storageRecData = NULL;

  var $rootFolder = '';
  var $activeFolder = '';

  var $storages = [];
  var $folders = [];
  var $files = [];
  var $foldersMenu = [];

  public function setFilePortal($fpNdx)
  {
    $this->fpNdx = $fpNdx;
    $this->fpCfg = $this->app()->cfgItem('e10pro.fp.filePortals.' . $fpNdx, NULL);
    if (!$this->fpCfg)
      return;

    $this->rootFolder = __APP_DIR__ . '/res/fp/' . $this->fpCfg['rf'].'/';
  }

  public function setStorage($storageNdx)
  {
    $this->storageNdx = $storageNdx;
    $this->storageRecData = $this->app()->loadItem($storageNdx, 'e10pro.fp.storages');

    if (!$this->storageRecData)
      return;
  }

  public function setActiveFolder($activeFolder)
  {
    $this->activeFolder = $activeFolder;
  }

  public function load()
  {
    $fldr = $this->rootFolder . $this->storageRecData['rootFolder'].'/';
    if ($this->activeFolder !== '')
      $fldr .= $this->activeFolder . '/';

    // -- load files and folders
    foreach (glob($fldr . '*', GLOB_MARK) as $f)
    {
      if (str_ends_with($f, '/'))
      { // dir
        $this->folders[] = [
          'type' => 'folder',
          'name' => basename($f),
          'path' => $f,
          'rpath' => $this->activeFolder . '/' . basename($f),
        ];
      }
      else
      {
        $fs = filesize($f);
        $this->files[] = [
          'type' => 'file',
          'name' => basename($f),
          'path' => $f,
          'fs' => Utils::memf($fs),
        ];
      }
    }

    $this->createFoldersMenu();
  }

  public function loadStorages()
	{
    $q = [];
    array_push($q, 'SELECT * ');
    array_push($q, ' FROM [e10pro_fp_storages] AS [storages] ');

    $rows = $this->app()->db()->query($q);
    foreach ($rows as $r)
    {
      $this->storages[] = $r->toArray();
    }
	}

  protected function createFoldersMenu()
  {
    $af = '';
    $parts = explode('/', $this->activeFolder);
    foreach ($parts as $i => $p)
    {
      if ($p === '')
        continue;
      if ($af !== '')
        $af .= '/';
      $af .= $p;
      $this->foldersMenu[] = [
        'name' => $p,
        'rpath' => $af,
        'path' => implode('/', array_slice($parts, 0, $i + 1)),
      ];
    }
  }

  public function downloadFile($relFileName)
  {
    $ffn = $this->rootFolder . $this->storageRecData['rootFolder'] . $relFileName;
    //error_log("DOWNLOAD FILE: `$ffn`");

		$mime = mime_content_type ($ffn);
		header ("Content-type: $mime");
		header ("Cache-control: max-age=10368000");
		header ('Expires: '.gmdate('D, d M Y H:i:s', time()+10368000).'GMT'); // 120 days
		header ('Content-Disposition: inline; filename=' . basename ($ffn));

    header ('X-Accel-Redirect: ' . $this->app->urlRoot.substr($ffn, strlen(__APP_DIR__)));
    die();
  }
}
