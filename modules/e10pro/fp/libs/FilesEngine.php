<?php

namespace e10pro\fp\libs;
use \Shipard\Utils\Utils;


/**
 * class FilesEngine
 */
class FilesEngine extends \Shipard\Base\Utility
{
  /*** @var \e10pro\fp\TableFilePortals */
  var $tableFilePortals = NULL;
  var $usersFilePortals = NULL;

  var $userNdx = 0;

  var $fpNdx = 0;
  var $fpCfg = NULL;

  var $storageNdx = 0;
  var $storageUId = '';
  var $storageRecData = NULL;

  var $rootFolder = '';
  var $activeFolder = '';

  var $storages = [];
  var $folders = [];
  var $files = [];
  var $foldersMenu = [];

  public function init()
  {
    $this->tableFilePortals = $this->app()->table('e10pro.fp.filePortals');
    $this->userNdx = $this->app()->uiUserNdx();
    $this->usersFilePortals = $this->tableFilePortals->loadUsersPortals($this->userNdx);
  }

  public function setFilePortal(string $fpUId)
  {
    $this->fpCfg = $this->app()->cfgItem('e10pro.fp.filePortals.' . $fpUId, NULL);
    if (!$this->fpCfg)
      return;
    $this->fpNdx = $this->fpCfg['ndx'];
    $this->rootFolder = __APP_DIR__ . '/res/fp/' . $this->fpCfg['rf'].'/';
  }

  public function setStorage($storageUId)
  {
    $storage = $this->app()->db()->query('SELECT * FROM [e10pro_fp_storages] WHERE [uid] = %s', $storageUId)->fetch();
    if (!$storage)
      return;

    $this->storageRecData = $storage->toArray();
    if (!$this->storageRecData)
      return;

    $this->storageNdx = $this->storageRecData['ndx'];
    $this->storageUId = $this->storageRecData['uid'];
  }

  public function setActiveFolder($activeFolder)
  {
    $this->activeFolder = $activeFolder;
  }

  public function createNewFolder($activeFolder, $newFolderName)
  {
    $fldr = $this->rootFolder . $this->storageRecData['rootFolder'].'/';
    if ($activeFolder !== '')
      $fldr .= $activeFolder . '/';

    $newFolder = $fldr . $newFolderName;
    if (!file_exists($newFolder))
    {
      if (mkdir($newFolder, 0770, true))
        return 0;

      return 2;
    }

    return 1;
  }

  public function loadFiles()
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
          'nameForUrl' => urlencode(basename($f)),
          'path' => $f,
          'fs' => Utils::memf($fs),
        ];
      }

    }

    $this->loadDownloadInvitations();
    $this->loadDownloadsLog();

    $this->createFoldersMenu();
  }

  public function loadStorages()
	{
    $q = [];
    array_push($q, 'SELECT * ');
    array_push($q, ' FROM [e10pro_fp_storages] AS [storages] ');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [ndx] IN %in', array_keys($this->usersFilePortals[$this->fpNdx]['storages']));

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

  public function downloadFile($relFileNameUrl, $inviteNdx = 0)
  {
    $relFileName = urldecode($relFileNameUrl);
    $this->userNdx = $this->app()->uiUserNdx();

    $ffn = $this->rootFolder . $this->storageRecData['rootFolder'] . $relFileName;

    $ipAddr = (isset($_SERVER ['REMOTE_ADDR'])) ? $_SERVER ['REMOTE_ADDR'] : '0.0.0.0';
    $logItem = [
      'storage' => $this->storageNdx,
      'filePath' => dirname($relFileName),
      'baseFileName' => basename($relFileName),
      'user' => $this->userNdx,
      'ipAddress' => $ipAddr,
      'tsDownload' => new \DateTime(),
      'invite' => $inviteNdx,
    ];
    if ($logItem['filePath'] === '/')
      $logItem['filePath'] = '';

    $this->app()->db()->query('INSERT INTO [e10pro_fp_downloadsLog]', $logItem);

		$mime = mime_content_type ($ffn);
		header ("Content-type: $mime");
		header ("Cache-control: no-cache, no-store, must-revalidate");
    header ("Content-Disposition: ".'attachment'."; filename*=UTF-8''" . rawurlencode(Utils::safeChars(basename($relFileName), TRUE)));
    header ('X-Accel-Redirect: ' . $this->app->urlRoot.substr($ffn, strlen(__APP_DIR__)));

    die();
  }

  public function uploadFile($relFileName)
  {
    $destFileName = $this->rootFolder . $this->storageRecData['rootFolder'] . $relFileName;

    $fileReader = fopen('php://input', "r");
    $fileWriter = fopen($destFileName, "w+");

    while (true)
    {
      $buffer = fgets($fileReader, 4096);
      if (strlen($buffer) == 0)
      {
        fclose($fileReader);
        fclose($fileWriter);
        break;
      }
      fwrite($fileWriter, $buffer);
    }
  }

  protected function loadDownloadInvitations()
  {
		$q = [];
    array_push ($q, 'SELECT [di].* ');
		array_push ($q, ', [storages].[fullName] AS [storageFullName]');
    array_push ($q, ', [authors].[fullName] AS [authorFullName]');
		array_push ($q, ' FROM [e10pro_fp_downloadInvites] AS [di]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [di].[storage] = [storages].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_users_users] AS [authors] ON [di].[authorUser] = [authors].[ndx]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [di].[filePath] = %s', $this->activeFolder);
    array_push ($q, ' AND [di].[storage] = %i', $this->storageNdx);

    $rows = $this->app()->db()->query($q);
    foreach ($rows as $r)
    {
      foreach ($this->files as &$file)
      {
        if ($file['name'] === $r['baseFileName'])
        {
          $file['invite'] = $r->toArray();
          $file['invite']['createDateTime'] = Utils::datef($file['invite']['tsCreated'], '%D %T');
          break;
        }
      }
    }
  }

  protected function loadDownloadsLog()
  {
		$q = [];
    array_push ($q, 'SELECT [dl].* ');
		array_push ($q, ', [storages].[fullName] AS [storageFullName]');
    array_push ($q, ', [users].[fullName] AS [userFullName]');
		array_push ($q, ' FROM [e10pro_fp_downloadsLog] AS [dl]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [dl].[storage] = [storages].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_users_users] AS [users] ON [dl].[user] = [users].[ndx]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [dl].[filePath] = %s', $this->activeFolder);
    array_push ($q, ' AND [dl].[storage] = %i', $this->storageNdx);

    $rows = $this->app()->db()->query($q);
    foreach ($rows as $r)
    {
      foreach ($this->files as &$file)
      {
        if ($file['name'] === $r['baseFileName'])
        {
          $file['download'] = $r->toArray();
          $file['download']['dlDateTime'] = Utils::datef($file['download']['tsDownload'], '%D %T');
          break;
        }
      }
    }
  }
}
