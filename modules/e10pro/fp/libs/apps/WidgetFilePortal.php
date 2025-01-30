<?php

namespace e10pro\fp\libs\apps;


/**
 * class WidgetFilePortal
 */
class WidgetFilePortal extends \Shipard\UI\Core\UIWidget
{
	var \e10pro\fp\libs\FilesEngine $filesEngine;
	var $filePortalNdx = 1;
	var $storageNdx = 0;

  function loadData()
	{
		$this->storageNdx = $this->requestParams['storage'] ?? 0;

		$this->filesEngine = new \e10pro\fp\libs\FilesEngine ($this->app());
		$this->filesEngine->setFilePortal($this->filePortalNdx);

		if (!$this->storageNdx)
		{
			$this->filesEngine->loadStorages();
			return;
		}

		$this->filesEngine->setStorage($this->storageNdx);

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
		$this->filesEngine->load();
  }

	function renderData()
	{
		if (!$this->uiTemplate)
      $this->uiTemplate = new \Shipard\UI\ng\TemplateUI ($this->app());

		$this->uiTemplate->data['storages'] = $this->filesEngine->storages;
		$this->uiTemplate->data['files'] = $this->filesEngine->files;
		$this->uiTemplate->data['folders'] = $this->filesEngine->folders;
		$this->uiTemplate->data['foldersMenu'] = $this->filesEngine->foldersMenu;
		$this->uiTemplate->data['activeFolder'] = $this->filesEngine->activeFolder;
		$this->uiTemplate->data['storageNdx'] = $this->filesEngine->storageNdx;
		$this->uiTemplate->data['storageName'] = $this->filesEngine->storageRecData['fullName'];

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
