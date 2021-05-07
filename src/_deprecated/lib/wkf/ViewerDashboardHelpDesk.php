<?php

namespace lib\wkf;

use \e10\TableViewPanel, e10\utils;


/**
 * Class ViewerDashboardHelpDesk
 * @package lib\wkf
 */
class ViewerDashboardHelpDesk extends \lib\wkf\ViewerDashboardIssues
{
	public function init ()
	{
		parent::init();

		$this->usePanelLeft = FALSE;
		$this->hasProjectsFilter = TRUE;
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
	}

	public function createTopMenuSearchCode ()
	{
		if ($this->fixedProjectNdx === -2)
			return '';
		return $this->createCoreSearchCode('e10-sv-search-toolbar-dark');
	}

	function createStaticContent()
	{
		if ($this->fixedProjectNdx !== -2)
			return;

		$c = '';

		$c .= "<div class='e10-sv-search e10-sv-search-toolbar e10-sv-search-toolbar-dark' data-style='padding: .5ex 1ex 1ex 1ex; display: inline-block; width: 100%;' id='{$this->vid}Search'>";
		$c .=	"<table style='width: 100%'><tr>";
		$c .= $this->createCoreSearchCodeBegin();
		$c .= "<td class='h1' style='width:95%;'>";
		$c .= utils::es('Technická podpora není aktivována...');
		$c .= '</td>';

		$c .= '</tr></table>';
		$c .= '</div>';

		$this->objectData ['staticContent'] = $c;
	}

	function createCoreSearchCodeBegin()
	{
		$portalInfo = $this->app->portalInfo ();

		$logoUrl = "https://{$portalInfo['pages']['support']['host']}/e10-modules/e10templates/web/shipard1/files/shipard/logo-header-web.svg";
		$linkUrl = "https://{$portalInfo['pages']['support']['host']}/";
		$c = '';
		$c .= "<td><a href='$linkUrl' target='_new'><img src='$logoUrl' style='height: 2.8ex; padding-right: 1em;'></a></td>";

		return $c;
	}
}

