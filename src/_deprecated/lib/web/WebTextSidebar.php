<?php


namespace lib\web;

use \Shipard\Form\FormSidebar, e10\TableView;


/**
 * Class WebTextSidebar
 * @package lib\web
 */
class WebTextSidebar extends FormSidebar
{
	function createSidebar ($dstTableId, $recData)
	{
		$comboParams = ['pageNdx' => strval (1), 'comboSrcTableId' => $dstTableId, 'comboSrcRecId' => $recData['ndx']];
		$browseTable = $this->app()->table ('e10.base.attachments');
		$viewer = $browseTable->getTableView ('lib.web.ViewerSidebarImages', $comboParams);

		$viewer->objectSubType = TableView::vsMini;
		$viewer->enableToolbar = FALSE;
		$viewer->comboSettings = ['ABCDE' => 'WWWWW'];

		$viewer->renderViewerData ('');
		$c = $viewer->createViewerCode ('html', TRUE);



		//$this->addTab('t1', 'Text', 'icon-keyboard-o', 'Běžný text');
		//$this->setTabContent('t1', '');


		$this->addTab('t2', 'Obrázky', 'icon-picture-o', 'Obrázky');
		$this->setTabContent('t2', $c);

		//$this->addTab('t3', 'Objekty', 'icon-code', 'Prvky stránky');
		//$this->setTabContent('t3', '');
	}
}