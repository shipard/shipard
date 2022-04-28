<?php

namespace hosting\core\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard, \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * Class WizardNewDatasource
 * @package hosting\core\libs
 */
class WizardNewDatasource extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 2)
		{
			$this->createDatasource();
		}

		parent::doStep();
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'system/iconDatabase';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Vytvořit novou databázi'];

		return $hdr;
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormDSType(); break;
			case 1: $this->renderFormDSInfo(); break;
			case 2: $this->renderFormDone(); break;
		}
	}

	public function renderFormDSType ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleWizard');
		//$this->setFlag ('maximize', 1);

		$enum = [];
		$dsCreateModes = $this->app()->cfgItem('hosting.core.dsCreateModes');
		foreach ($dsCreateModes as $dsCreateModeId => $dsCreateMode)
		{
			$enum[$dsCreateModeId] = $dsCreateMode['inputLabel'];
			if (!isset($this->recData['dsCreateModeId']))
				$this->recData['dsCreateModeId'] = $dsCreateModeId;
		}

		$this->openForm (self::ltVertical);
			$this->addInput('test1', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->addInputEnum2('dsCreateModeId', ['text' => 'Vyberte typ databáze', 'class' => 'h1 e10-bold'], $enum, TableForm::INPUT_STYLE_RADIO);
		$this->closeForm ();
	}

	public function renderFormDSInfo ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleWizard');
		$this->recData['partner'] = intval($this->app()->testGetParam('partnerNdx'));

		if ($this->recData['dsCreateModeId'] === 'demo')
		{
			$this->renderFormDSInfoDemo ();
		}
		elseif ($this->recData['dsCreateModeId'] === 'production')
		{
			$this->renderFormDSInfoProduction();
		}
	}

	public function renderFormDSInfoDemo ()
	{
		$enum = [];
		$dsDemoTypes = $this->app()->cfgItem('hosting.core.dsCreateDemoTypes');
		foreach ($dsDemoTypes as $dsDemoTypeId => $dsDemoType)
		{
			if (isset($dsDemoType['disabled']))
				continue;
			$enum[$dsDemoTypeId] = $dsDemoType['inputLabel'];
			if (!isset($this->recData['dsCreateDemoTypeId']))
				$this->recData['dsCreateDemoTypeId'] = $dsDemoTypeId;
		}

		$this->openForm (self::ltVertical);
			$this->addInput('dataSourceName', 'Název databáze', self::INPUT_STYLE_STRING, self::coFocus, 100, FALSE, 'Naše firma s.r.o.');
			$this->addInputEnum2('dsCreateDemoTypeId', ' ', $enum, TableForm::INPUT_STYLE_RADIO);
			$this->addInput('partner', 'Partner', self::INPUT_STYLE_STRING, TableForm::coHidden, 20);
			$this->addInput('dsCreateModeId', 'dsCreateModeId', self::INPUT_STYLE_STRING, TableForm::coHidden, 80);
		$this->closeForm ();		
	}

	public function renderFormDSInfoProduction ()
	{
		$allCountries = $this->app()->cfgItem('e10.base.countries');
		$enumCountries = [];
		foreach ($allCountries as $key => $value)
			$enumCountries[$key] = $value['name'];
		$this->recData['country'] = 'cz';

		$allModules = $this->app->cfgItem ('hosting.core.installModules');
		$enumModules = [];
		foreach ($allModules as $moduleId => $m)
		{
			$enumModules[$moduleId] = ['text' => $m['fn']/*, 'suffix' => $m['description']*/];
		}
		$this->recData['installModule'] = 'install/apps/shipard-economy'; // TODO: settings

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
		$this->closeForm ();
	}

	public function createDatasource ()
	{
		$ownerPersonNdx = 0;
		$isDemo = 0;
		$dataSourceName = '';
		$dsCreateDemoType = '';

		if ($this->recData['dataSourceName'] === '')
			$this->recData['dataSourceName'] = 'Naše firma s.r.o.';

		if ($this->recData['dsCreateModeId'] === 'demo')
		{
			$installModuleId = 'install/apps/shipard-economy';
			$isDemo = 1;
			$dataSourceName = $this->recData['dataSourceName'];
			$dsCreateDemoType = $this->recData['dsCreateDemoTypeId'];
		}
		elseif ($this->recData['dsCreateModeId'] === 'production')
		{
			$installModuleId = $this->recData['installModule'];
			$dataSourceName = $this->recData['companyName'];
		}
		else
		{
			return;
		}


		$now = new \DateTime();
		$dateTrialEnd = Utils::today();
		$dateTrialEnd->add(new \DateInterval('P30D'));

		$server = 0;

		// -- createRequest
		$createRequest = [
			'srcIPAddress' => $_SERVER ['REMOTE_ADDR'] ?? '',
			'dateCreated' => $now,
			'dateStart' => Utils::today(),
			'dateTrialEnd' => $dateTrialEnd,
			'name' => $dataSourceName,
			'installModule' => $installModuleId,
			'server' => $server,
			'dsDemo' => $isDemo,
			'dsCreateDemoType' => $dsCreateDemoType,
		];

		if ($this->recData['dsCreateModeId'] === 'production')
		{
			$createRequest['companyId'] = $this->recData['companyId'];
			$createRequest['vatId'] = $this->recData['vatId'];
			$createRequest['companyName'] = $this->recData['companyName'];
			$createRequest['street'] = $this->recData['street'];
			$createRequest['city'] = $this->recData['city'];
			$createRequest['zipcode'] = $this->recData['zipcode'];
			$createRequest['country'] = $this->recData['country'];
		}
		elseif ($this->recData['dsCreateModeId'] === 'demo')
		{
			$demoCfg = $this->app()->cfgItem('hosting.core.dsCreateDemoTypes.'.$dsCreateDemoType, NULL);
			if ($demoCfg && isset($demoCfg['createRequest']))
			{
				foreach ($demoCfg['createRequest'] as $key => $value)
					$createRequest[$key] = $value;
			}
		}

		Json::polish($createRequest);

		// -- data source
		/** @var \hosting\core\TableDatasources $tableDataSources */
		$tableDataSources = $this->app()->table('hosting.core.dataSources');
		$newDataSource = [
			'name' => $dataSourceName,
			'shortName' => '',
			'installModule' => $installModuleId,
			'gid' => '',
			'server' => $server,

			'condition' => 0, 
			'dsType' => 0,
			'dsDemo' => $isDemo,
			'dsCreateDemoType' => $dsCreateDemoType,
			'invoicingTo' => 3, // none

			'partner' => intval($this->recData['partner']),
			'admin' => $this->app()->userNdx(),

			'dateCreated' => $now,
			'dateStart' => Utils::today(),
			'dateTrialEnd' => $dateTrialEnd,

			'createRequest' => Json::lint($createRequest),

			'docState' => 1100, 'docStateMain' => 0,
		];


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
