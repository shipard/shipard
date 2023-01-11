<?php
namespace e10doc\purchase\libs;

use \Shipard\Form\FormSidebar;
use \Shipard\Viewer\TableView;


/**
 * class AddWizardFromID
 */
class AddWizardFromID extends \e10\persons\libs\AddWizardFromID
{
	public function savePerson ()
	{
		parent::savePerson ();
		$this->stepResult ['addDocument'] = 1;
		$this->stepResult ['params'] = array('table' => 'e10doc.core.heads', 'addparams' => "__docType=purchase&__person={$this->newPersonNdx}");
	}

	protected function renderMainSidebar ($allRecData, $recData)
	{
		/** @var \e10\persons\TablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');

		$comboParams = [];
    if ($recData['lastName'] !== '')
      $comboParams['lastName'] = $recData['lastName'];
    if ($recData['firstName'] !== '')
      $comboParams['firstName'] = $recData['firstName'];
    if ($recData['idcn'] !== '')
      $comboParams['idcn'] = $recData['idcn'];
    if ($recData['email'] !== '')
      $comboParams['email'] = $recData['email'];

    if (!count($comboParams))
      return '';

		$viewer = $tablePersons->getTableView ("e10.persons.libs.viewers.ViewSimilarPersons", $comboParams);

		$viewer->objectSubType = TableView::vsMini;
		$viewer->enableToolbar = FALSE;
		$viewer->comboSettings = ['ABCDE' => 'WWWWW'];

		$viewer->renderViewerData ('html');
		$c = $viewer->createViewerCode ('html', 'fullCode');

		$sideBar = new FormSidebar ($this->app());
		$sideBar->addTab('t1', $tablePersons->tableName());
		$sideBar->setTabContent('t1', $c);

		$this->sidebar = $sideBar->createHtmlCode();
	}
}
