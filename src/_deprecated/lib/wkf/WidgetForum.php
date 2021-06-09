<?php


namespace lib\wkf;

require_once __APP_DIR__ . '/e10-modules/e10pro/wkf/wkf.php';



/**
 * Class WidgetForum
 * @package lib\wkf
 */
class WidgetForum extends \lib\wkf\DocumentsWallWidget
{
	function createTabs ()
	{
		$tabs = [];
		$tabs['bboard'] = ['icon' => 'system/iconPinned', 'text' => 'Nástěnka', 'action' => 'load-bboard'];
		$this->addProjectsTabs($tabs);
		$tabs['newsBoard'] = ['icon' => 'icon-smile-o', 'text' => 'Moje úkoly', 'action' => 'load-news-board'];
		$tabs['search'] = ['icon' => 'icon-search', 'text' => '', 'action' => 'load-search'];

		$this->toolbar = ['tabs' => $tabs];

		$rt = [
				'viewer-mode-2' => ['text' =>'', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
				'viewer-mode-1' => ['text' =>'', 'icon' => 'icon-th-list', 'action' => 'viewer-mode-1'],
				'viewer-mode-3' => ['text' =>'', 'icon' => 'icon-square', 'action' => 'viewer-mode-3'],
				'viewer-mode-0' => ['text' =>'', 'icon' => 'icon-th-large', 'action' => 'viewer-mode-0'],
		];

		$this->toolbar['rightTabs'] = $rt;

		$logo = $this->app->cfgItem ('appSkeleton.logo', '');
		if ($logo !== '')
			$this->toolbar['logo'] = $logo;
	}
}
