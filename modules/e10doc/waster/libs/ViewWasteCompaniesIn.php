<?php

namespace e10doc\waster\libs;
use \Shipard\Viewer\TableView;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewWasteCompaniesIn
 */
class ViewWasteCompaniesIn extends TableView
{
	public $mainGroup = 0;
	public $properties = array();
	var $classification = [];
	public $addresses = array();
	protected $loadAddresses = FALSE;
	protected $searchInProperties = TRUE;
	protected $searchInBA = 0;
	protected $showValidity = TRUE;
	protected $ftsInArchive = TRUE;

	var $wasteDir = 0;
	var $wasteReturns = [];

	public function init ()
	{
		$this->addTopTabs();
		parent::init();
	}

	public function icon ($recData, $iconSet = NULL)
	{
		return $this->table->icon ($recData, $iconSet);
	}

	public function selectRows ()
	{
    $q = [];
    array_push ($q, 'SELECT persons.*');
    array_push ($q, ' FROM [e10_persons_persons] AS persons');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND persons.company = 1');

		$bottomTabId = $this->mainQueryId ();
		$wrNdx = intval($bottomTabId);
		if ($wrNdx)
		{
			$wr = $this->wasteReturns[$wrNdx] ?? NULL;

			array_push ($q, ' AND EXISTS (SELECT DISTINCT person FROM e10pro_reports_waste_cz_returnRows WHERE persons.ndx = e10pro_reports_waste_cz_returnRows.person ',
																	' AND [calendarYear] = %i', $wr['year'],
																	' AND e10pro_reports_waste_cz_returnRows.[dir] = %i', $this->wasteDir,
																	' AND e10pro_reports_waste_cz_returnRows.[wasteCodeKind] = %i', 1,
																	')');
		}

		$fs = $this->fullTextSearch ();
		if ($fs != '')
		{
			array_push ($q, ' AND (');
			array_push( $q, ' (persons.[fullName] LIKE %s)', '%'.$fs.'%');
			array_push ($q, ')');
		}

    array_push( $q, ' ORDER BY [lastName], [firstName] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

  public function addTopTabs()
  {
		$mq = [];

		$q = [];
		array_push ($q, 'SELECT wasteReturns.* FROM [e10doc_waster_wasteReturns] AS wasteReturns');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND wasteReturns.docState = 4000');
		array_push ($q, ' ORDER BY wasteReturns.[year] DESC');
		$rows = $this->table->db()->query ($q);
		foreach ($rows as $r)
		{
			$mq[] = ['id' => strval($r['ndx']), 'title' => $r['tabTitle'] === '' ? $r['year'] : $r['tabTitle'], 'icon' => 'system/filterActive', 'side' => 'left'];
			$this->wasteReturns[$r['ndx']] = $r->toArray();
		}

		$mq[] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
		$this->setMainQueries ($mq);
  }

	public function qryFullTextExt (array &$q)
	{
	}

	public function qryPanel (array &$q)
	{
		$qv = $this->queryValues();

		// -- type
		$humans = isset ($qv['personTypes']['humans']);
		$companies = isset ($qv['personTypes']['companies']);
		if ($humans xor $companies)
		{
			if ($humans)
				array_push ($q, ' AND [company] = 0');
			else
				array_push ($q, ' AND [company] = 1');
		}

		// -- groups
		if (isset ($qv['personGroups']))
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person AND [group] IN %in', array_keys($qv['personGroups']), ')');

		// -- countries
		if (isset ($qv['personCountries']))
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
											' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [country] IN %in', array_keys($qv['personCountries']),
											')');
		// -- city
		if (isset ($qv['geo']['city']) && $qv['geo']['city'] != '')
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
				' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [city] LIKE %s', '%'.$qv['geo']['city'].'%',
				')');
		// -- street
		if (isset ($qv['geo']['street']) && $qv['geo']['street'] != '')
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
				' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [street] LIKE %s', '%'.$qv['geo']['street'].'%',
				')');
		// -- zipcode
		if (isset ($qv['geo']['zipcode']) && $qv['geo']['zipcode'] != '')
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_address',
				' WHERE persons.ndx = e10_persons_address.recid AND [tableid] = %s', 'e10.persons.persons', ' AND [zipcode] LIKE %s', $qv['geo']['zipcode'].'%',
				')');

		// -- tags
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE persons.ndx = recid AND tableId = %s', 'e10.persons.persons');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		if (isset ($qv['fiscalPeriods']))
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10doc_core_heads WHERE persons.ndx = e10doc_core_heads.person AND [fiscalYear] IN %in', array_keys($qv['fiscalPeriods']), ')');

		// -- others - with error
		$withError = isset ($qv['others']['withError']);
		if ($withError)
			array_push($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person AND [valid] = 2)');

		$unused = isset ($qv['others']['unused']);
		if ($unused)
			array_push($q, ' AND persons.lastUseDate IS NULL');

		$canceled = isset ($qv['others']['canceled']);
		if ($canceled)
			array_push($q, ' AND persons.personCanceled = 1');

		$withoutMainAddress = isset ($qv['others']['withoutMainAddress']);
		if ($withoutMainAddress)
		{
			array_push ($q, ' AND NOT EXISTS (SELECT ndx FROM e10_persons_personsContacts WHERE persons.ndx = person ');
			array_push ($q, ' AND e10_persons_personsContacts.flagAddress = 1 AND e10_persons_personsContacts.flagMainAddress = 1');
			array_push ($q, ')');
		}

		$withMoreMainAddress = isset ($qv['others']['withMoreMainAddress']);
		if ($withMoreMainAddress)
		{
			array_push ($q, ' AND persons.ndx IN ');
			array_push ($q, ' (select * FROM (');
			array_push ($q, ' SELECT person FROM e10_persons_personsContacts WHERE flagAddress = 1 AND flagMainAddress = 1 AND docState = 4000 GROUP BY person HAVING count(*) > 1');
			array_push ($q, ' ) AS [persMainAddrDups] )');
		}

		$withoutCompanyId = isset ($qv['others']['withoutCompanyId']);
		if ($withoutCompanyId)
		{
			array_push ($q, ' AND (persons.company = %i', 1);

			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsContacts WHERE persons.ndx = person ');
			array_push ($q, ' AND e10_persons_personsContacts.flagAddress = 1 AND e10_persons_personsContacts.adrCountry = %i', 60);
			array_push ($q, ')');

			array_push ($q, ' AND NOT EXISTS (SELECT ndx FROM e10_base_properties WHERE persons.ndx = e10_base_properties.recid ',
							' AND tableid = %s', 'e10.persons.persons',
							' AND [group] = %s', 'ids', ' AND [property] = %s', 'oid',
							')');
			array_push ($q, ')');
		}
	}

	public function XXX_selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->properties = $this->table->loadProperties ($this->pks, ['officialName', 'shortName']);
		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		// -- addresses
		if ($this->loadAddresses)
			$this->addresses = $this->table->loadAddresses($this->pks);
	}

	function decorateRow (&$item)
	{
		if (isset ($this->properties [$item ['pk']]['groups']))
			$item ['i2'] = $this->properties [$item ['pk']]['groups'];

		if (isset ($this->properties [$item ['pk']]['ids']))
			$item ['t2'] = array_merge($item ['t2'], $this->properties [$item ['pk']]['ids']);
		else
		if (isset ($this->properties [$item ['pk']]['contacts']))
			$item ['t2'] = array_merge ($item ['t2'], array_slice ($this->properties [$item ['pk']]['contacts'], 0, 2, TRUE));

		//if (!count($item ['t2']))
		//	$item ['t2'] = ' ';

		if (isset ($this->classification [$item ['pk']]))
		{
			$item ['t3'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);
		}
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['wasteReturn'] = intval($this->mainQueryId ());
		$listItem ['icon'] = $this->icon ($item);
		$listItem ['t1'] = $item['fullName'];

		$listItem ['i1'] = ['text' => '#'.$item['id'], 'class' => 'id'];

		$listItem ['t2'] = [];

		if ($item['personCanceled'])
		{
			$listItem ['t2'][] = ['text' => 'ZRUŠENO', 'icon' => 'user/ban', 'class' => 'e10-error'];
			$listItem ['!error'] = 'e10-error';
		}
		return $listItem;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- people/company
		$chbxPersonTypes = [
			'humans' => ['title' => 'Lidé', 'id' => 'humans'], 'companies' => ['title' => 'Společnosti', 'id' => 'companies']
		];
		$paramsPersonTypes = new \E10\Params ($this->app());
		$paramsPersonTypes->addParam ('checkboxes', 'query.personTypes', ['items' => $chbxPersonTypes]);
		$qry[] = array ('id' => 'itemTypes', 'style' => 'params', 'title' => 'Osoby', 'params' => $paramsPersonTypes);

		// -- groups
		if (!$this->mainGroup)
		{
			$grps = $this->app()->cfgItem ('e10.persons.groups');
			if (count($grps) !== 0)
			{
				$chbxPersonGroups = [];
				forEach ($grps as $g)
					$chbxPersonGroups[$g['id']] = ['title' => $g['name'], 'id' => $g['id']];

				$paramsPersonGroups = new \E10\Params ($panel->table->app());
				$paramsPersonGroups->addParam ('checkboxes', 'query.personGroups', ['items' => $chbxPersonGroups]);
				$qry[] = array ('id' => 'itemGroups', 'style' => 'params', 'title' => 'Skupiny', 'params' => $paramsPersonGroups);
			}
		}

		$this->createPanelContentQry1 ($panel, $qry);

		// -- countries
		$paramsPersonCountries = new \E10\Params ($panel->table->app());

		$countriesCfg = $this->app()->cfgItem ('e10.base.countries');
		$countriesQry = 'SELECT distinct country FROM [e10_persons_address] ORDER BY country';
		$countriesRows = $this->table->db()->query ($countriesQry);
		if (count($countriesRows) !== 0)
		{
			$chbxPersonCountries = [];
			forEach ($countriesRows as $r)
				$chbxPersonCountries[$r['country']] = ['title' => $countriesCfg[$r['country']]['name'] ?? '---', 'id' => $r['country']];

			$paramsPersonCountries->addParam ('checkboxes', 'query.personCountries', ['items' => $chbxPersonCountries]);
			$qry[] = array ('id' => 'personCountries', 'style' => 'params', 'title' => 'Zeměpis', 'params' => $paramsPersonCountries);
		}
		$paramsPersonCountries->addParam ('string', 'query.geo.city', ['title' => 'Město']);
		$paramsPersonCountries->addParam ('string', 'query.geo.street', ['title' => 'Ulice']);
		$paramsPersonCountries->addParam ('string', 'query.geo.zipcode', ['title' => 'PSČ']);

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		// -- active in fiscal period
		$periods = $this->app->cfgItem ('e10doc.acc.periods', NULL);
		if ($periods)
		{
			$periodsEnum = [];
			forEach ($periods as $periodNdx => $periodCfg)
				$periodsEnum[$periodNdx] = ['title' => $periodCfg['fullName'], 'id' => $periodNdx];

			$paramsFiscalPeriods = new \E10\Params ($panel->table->app());
			$paramsFiscalPeriods->addParam ('checkboxes', 'query.fiscalPeriods', ['items' => $periodsEnum]);
			$qry[] = ['id' => 'fiscalPeriods', 'style' => 'params', 'title' => 'Použito ve fiskálním období', 'params' => $paramsFiscalPeriods];
		}

		// -- others
		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));

		$chbxOthers = [
				'withError' => ['title' => 'S chybou', 'id' => 'withError'],
				'unused' => ['title' => 'Nepoužité', 'id' => 'unused'],
		];

		if ($testNewPersons)
		{
			$chbxOthers['withoutMainAddress'] = ['title' => 'Bez sídla', 'id' => 'withoutMainAddress'];
			$chbxOthers['withMoreMainAddress'] = ['title' => 'S více sídly', 'id' => 'withMoreMainAddress'];
			$chbxOthers['withoutCompanyId'] = ['title' => 'Firmy bez IČ', 'id' => 'withoutCompanyId'];
			$chbxOthers['canceled'] = ['title' => 'Zrušené', 'id' => 'canceled'];
		}

		$paramsOthers = new \E10\Params ($this->app());
		$paramsOthers->addParam ('checkboxes', 'query.others', ['items' => $chbxOthers]);
		$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Ostatní', 'params' => $paramsOthers];



		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	protected function createPanelContentQry1 (TableViewPanel $panel, &$qry)
	{
	}
}
