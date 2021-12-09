<?php

namespace hosting\core\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard, \Shipard\Utils\Utils;


/**
 * Class WizardNewDatasource
 * @package hosting\core\libs
 */
class WizardNewDatasource extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->createDatasource();
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-database';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vytvořit novou databázi'];

		return $hdr;
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');


		$allCountries = $this->app()->cfgItem('e10.base.countries');
		$enumCountries = [];
		foreach ($allCountries as $key => $value)
			$enumCountries[$key] = $value['name'];
		$this->recData['country'] = 'cz';

		$allModules = $this->app->cfgItem ('hosting.core.installModules');
		$enumModules = [];
		foreach ($allModules as $moduleId => $m)
		{
			//if ($m['private'] && !$this->app->hasRole('hstng'))
			//	continue;
			$enumModules[$moduleId] = ['text' => $m['fn']/*, 'suffix' => $m['description']*/];
		}
		$this->recData['installModule'] = 'shipard-economy'; // TODO: settings

		$this->recData['partner'] = intval($this->app()->testGetParam('partnerNdx'));

		$this->openForm ();
			$this->addInput('partner', 'Partner', self::INPUT_STYLE_STRING, TableForm::coHidden, 20);

			$this->addInputEnum2 ('installModule', 'Aplikace', $enumModules, self::INPUT_STYLE_RADIO);
			$this->addSeparator(self::coH4);
			$this->addInput('companyId', 'IČ', self::INPUT_STYLE_STRING, 0, 12);
			$this->addInput('vatId', 'DIČ', self::INPUT_STYLE_STRING, 0, 12);
			$this->addSeparator(self::coH4);
			$this->addInput('companyName', 'Název společnosti', self::INPUT_STYLE_STRING, 0, 100);
			$this->addInput('street', 'Ulice', self::INPUT_STYLE_STRING, 0, 90);
			$this->addInput('city', 'Město', self::INPUT_STYLE_STRING, 0, 90);
			$this->addInput('zipcode', 'PSČ', self::INPUT_STYLE_STRING, 0, 20);
			$this->addInputEnum2 ('country', 'Země', $enumCountries, self::INPUT_STYLE_OPTION);
			//$this->addSeparator(self::coH4);
			//$this->addCheckBox('testNG', 'Testovací databáze Shipard NG');
		$this->closeForm ();
	}

	public function createDatasource ()
	{
		// -- owner person
		$newPerson ['person'] = ['fullName' => $this->recData['companyName'], 'lastName' => $this->recData['companyName'], 'company' => 1];

		$newPerson ['address'][] = [
			'street' => $this->recData['street'], 'city' => $this->recData['city'],
			'zipcode' => $this->recData['zipcode'], 'country' => $this->recData['country']
		];

		if ($this->recData['companyId'] != '')
			$newPerson ['ids'][] = ['type' => 'oid', 'value' => $this->recData['companyId']];
		if ($this->recData['vatId'] != '')
			$newPerson ['ids'][] = ['type' => 'taxid', 'value' => $this->recData['vatId']];

		$newPersonNdx = \E10\Persons\createNewPerson ($this->app(), $newPerson);

		$server = 0;
		//$server = 16; // TODO: settings...
		//if (intval($this->recData['testNG']))
		//	$server = 17;

		// -- data source
		/** @var \hosting\core\TableDatasources $tableDataSources */
		$tableDataSources = $this->app()->table('hosting.core.dataSources');
		$newDataSource = [
			'name' => $this->recData['companyName'],
			'shortName' => '',
			'installModule' => $this->recData['installModule'],
			'gid' => '',
			'server' => $server,

			'condition' => 0, 
			'dsType' => 0,
			'invoicingTo' => 3, // none

			'partner' => intval($this->recData['partner']),
			'admin' => $this->app()->userNdx(),
			'owner' => $newPersonNdx,

			'dateCreated' => new \DateTime(),
			'dateStart' => Utils::today(),

			'docState' => 1100, 'docStateMain' => 0,
		];

		$newDataSource['dateTrialEnd'] = Utils::today();
		$newDataSource['dateTrialEnd']->add(new \DateInterval('P30D'));

		$newDatasourceNdx = $tableDataSources->dbInsertRec($newDataSource);

		// -- link new data source to admin
		$newLinkedDataSource = [
			'user' => $newDataSource['admin'], 'dataSource' => $newDatasourceNdx,
			'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2
		];
		/** @var \hosting\core\TableDSUsers $tableUsersDS */
		$tableUsersDS = $this->app()->table('hosting.core.dsUsers');
		$tableUsersDS->addUsersDSLink($newLinkedDataSource);

		$tableDataSources->docsLog($newDatasourceNdx);

		$this->stepResult ['close'] = 1;
	}
}
