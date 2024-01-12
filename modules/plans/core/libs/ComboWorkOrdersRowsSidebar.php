<?php


namespace plans\core\libs;
use \Shipard\Form\FormSidebar, e10\TableView;


/**
 * Class WebTextSidebar
 * @package lib\web
 */
class ComboWorkOrdersRowsSidebar extends FormSidebar
{
	function createSidebar ($dstTableId, $recData)
	{
		$comboParams = ['pageNdx' => strval (1), 'comboSrcTableId' => $dstTableId, 'comboSrcRecId' => $recData['ndx'], 'workOrderNdx' => strval($recData['workOrder'])];
		$browseTable = $this->app()->table ('e10doc.core.rows');
		$viewer = $browseTable->getTableView ('plans.core.libs.ComboWorkOrdersRowsViewer', $comboParams);

		$viewer->objectSubType = TableView::vsMini;
		$viewer->enableToolbar = FALSE;
		$viewer->comboSettings = ['workOrderNdx' => strval($recData['workOrder'])];

		$viewer->renderViewerData ('');
		$c = $viewer->createViewerCode ('html', TRUE);


		$this->addTab('t2', 'Obrázky', 'icon-picture-o', 'Obrázky');
		$this->setTabContent('t2', $c);
	}
}