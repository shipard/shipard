<?php


namespace lib\web;

use \Shipard\Form\FormSidebar, \Shipard\Viewer\TableView;


/**
 * Class WebTextSidebarWiki
 * @package lib\web
 */
class WebTextSidebarWiki extends FormSidebar
{
	function createSidebar ($dstTableId, $recData)
	{
		$tableWikies = $this->app()->table ('e10pro.kb.wikies');
		$userSections = $tableWikies->userSections ();
		$userSectionsParam = implode ('.', $userSections);

		$comboParams = [
			'comboSrcTableId' => $dstTableId, 'comboSrcRecId' => $recData['ndx'],
			'userSections' => $userSectionsParam
		];
		$browseTable = $this->app()->table ('e10.base.attachments');
		$viewer = $browseTable->getTableView ('lib.web.ViewerSidebarImagesWiki', $comboParams);

		$viewer->objectSubType = TableView::vsMini;
		$viewer->enableToolbar = FALSE;
		$viewer->comboSettings = ['ABCDE' => 'WWWWW'];

		$viewer->renderViewerData ('');
		$c = $viewer->createViewerCode ('html', TRUE);

		$this->addTab('t2', 'Obrázky', 'icon-picture-o', 'Obrázky');
		$this->setTabContent('t2', $c);
	}
}