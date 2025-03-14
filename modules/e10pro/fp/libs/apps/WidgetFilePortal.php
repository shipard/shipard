<?php

namespace e10pro\fp\libs\apps;


/**
 * class WidgetFilePortal
 */
class WidgetFilePortal extends \Shipard\UI\Core\UIWidget
{
	var \e10pro\fp\libs\FilesEngine $filesEngine;

	var $filePortalNdx = 1;
	var $filePortalUId = '';

	var $storageNdx = 0;
	var $storageUId = '';

  function loadData()
	{
		if (isset($this->widgetSystemParams['data-request-portal-uid']))
			$this->filePortalUId = $this->widgetSystemParams['data-request-portal-uid'];
		else
			$this->filePortalUId = $this->requestParams['portal'] ?? '???';

		$this->filePortalNdx = $this->requestParams['filePortalNdx'] ?? 1;


		$this->storageUId = $this->requestParams['storage'] ?? '';

		$this->filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());
		$this->filesEngine->init();
		$this->filesEngine->setFilePortal($this->filePortalUId);

		if ($this->storageUId === '')
		{
			$this->filesEngine->loadStorages();
			return;
		}

		$this->filesEngine->setStorage($this->storageUId);

		$activeFolder = $this->requestParams['activeFolder'] ?? '';
		$doUpFolder = $this->requestParams['doUpFolder'] ?? '';
		if ($doUpFolder === 'UP')
		{
			$activeFolder = substr($activeFolder, 0, strrpos($activeFolder, '/'));
		}
		else
		{
			$doFolder = $this->requestParams['doFolder'] ?? '';
			if ($doFolder !== '')
			{
				$activeFolder = $doFolder;
				if ($activeFolder === '/')
					$activeFolder = '';
			}
		}

		$this->filesEngine->setActiveFolder($activeFolder);
		$this->filesEngine->loadFiles();
  }

	function renderData()
	{
		$this->uiTemplate->data['storages'] = $this->filesEngine->storages;
		$this->uiTemplate->data['files'] = $this->filesEngine->files;
		$this->uiTemplate->data['folders'] = $this->filesEngine->folders;
		$this->uiTemplate->data['foldersMenu'] = $this->filesEngine->foldersMenu;
		$this->uiTemplate->data['activeFolder'] = $this->filesEngine->activeFolder;
		$this->uiTemplate->data['filePortalNdx'] = $this->filePortalNdx;
		$this->uiTemplate->data['filePortalUId'] = $this->filePortalUId;
		$this->uiTemplate->data['storageNdx'] = $this->filesEngine->storageNdx;
		$this->uiTemplate->data['storageUId'] = $this->filesEngine->storageUId;
		$this->uiTemplate->data['storageName'] = $this->filesEngine->storageRecData ? $this->filesEngine->storageRecData['fullName'] : '';

		$upFolder = substr($this->filesEngine->activeFolder, 0, strrpos($this->filesEngine->activeFolder, '/'));
		$this->uiTemplate->data['upFolder'] = $upFolder;
		$this->uiTemplate->data['activeFolder'] = $this->filesEngine->activeFolder;

		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/fp/libs/apps/subtemplates/filePortal');
		$code = $this->uiTemplate->render($templateStr);

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
	}

	public function createContent ()
	{
		$this->loadData();
		$this->renderData();
	}

	public function title()
	{
		return FALSE;
	}
}
