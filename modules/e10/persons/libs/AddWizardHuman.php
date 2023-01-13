<?php

namespace e10\persons\libs;
use \Shipard\Form\FormSidebar;
use \Shipard\Viewer\TableView;


/**
 * class AddWizardHuman
 */
class AddWizardHuman extends \e10\persons\libs\AddWizardFromID
{
	public function savePerson ()
	{
    $this->docState = 1000;
		$this->docStateMain = 0;

		parent::savePerson ();

    $this->stepResult ['close'] = 1;
    $this->stepResult ['editDocument'] = 1;
    $this->stepResult ['params'] = ['table' => 'e10.persons.persons', 'pk' => $this->newPersonNdx];
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
