<?php


namespace lib\wkf;

require_once __APP_DIR__ . '/e10-modules/e10pro/wkf/wkf.php';


use \e10\widgetBoard;


/**
 * Class WidgetDocuments
 * @package lib\wkf
 */
class WidgetDocuments extends widgetBoard
{
	var $useWiki = FALSE;
	var $useCompany = FALSE;

	/** @var  \e10\DbTable */
	var $table;
	/** @var  \e10\DbTable */
	var $tableProjects;
	var $usersProjects;

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '2';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		$this->addContentViewer('e10pro.wkf.documents', 'lib.wkf.ViewerDocumentsAll', ['viewerMode' => $viewerMode]);
	}

	public function init ()
	{
		$this->table = $this->app->table ('e10pro.wkf.documents');
		$this->tableProjects = $this->app->table ('e10pro.wkf.projects');
		$this->usersProjects = $this->tableProjects->usersProjects(FALSE, TRUE);

		parent::init();
	}

	public function title()
	{
		return FALSE;
	}
}
