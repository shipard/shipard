<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewAddressTechnical
 */
class ViewAddressTechnical extends TableView
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;
	var $classification = [];

	public function init ()
	{
		$this->tablePersons = $this->app()->table('e10.persons.persons');

		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsMain;

		$this->setMainQueries();

		parent::init();
		$this->setPanels (TableView::sptQuery);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [contacts].*,';
		array_push ($q, ' [persons].[fullName] AS [personName], [persons].[personType], [persons].[company],');
		array_push ($q, ' [persons].[docState] AS [personDocState], [persons].[docStateMain] AS [personDocStateMain]');
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [contacts].[person] = [persons].[ndx]');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND [contacts].flagAddress = %i', 1);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [contacts].adrCity LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [contacts].adrStreet LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [contacts].adrSpecification LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		// -- query panel
		$qv = $this->queryValues();
		if (isset ($qv['fiscalPeriods']))
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10doc_core_heads WHERE contacts.person = e10doc_core_heads.person AND [fiscalYear] IN %in', array_keys($qv['fiscalPeriods']), ')');

		$this->queryMain ($q, '[contacts].', ['[systemOrder], [adrCity]', '[ndx]']);
		$this->runQuery ($q);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'system/iconMapMarker';

		$listItem['t2'] = [];

		$prd = ['docState' => $item['personDocState'], 'docStateMain' => $item['personDocStateMain']];
		$personDocState = $this->tablePersons->getDocumentState ($prd);
		$docStateClass = ' e10-ds '.$this->tablePersons->getDocumentStateInfo ($personDocState ['states'], $prd, 'styleClass');

		$pn = ($item['personName'] != '') ? $item['personName'] : '!!!'.$item['person'];
		$listItem['t2'][] = ['text' => $pn, 'class' => 'label label-default'.$docStateClass, 'icon' => $this->tablePersons->tableIcon($item)];


    $address = '';
    $addressFlags = [];

		$ap = [];

		if ($item['adrSpecification'] != '')
			$ap[] = $item['adrSpecification'];
		if ($item['adrStreet'] != '')
			$ap[] = $item['adrStreet'];
		if ($item['adrCity'] != '')
			$ap[] = $item['adrCity'];
		if ($item['adrZipCode'] != '')
			$ap[] = $item['adrZipCode'];

		$country = World::country($this->app(), $item['adrCountry']);
		$ap[] = /*$country['f'].' '.*/$country['t'];

		$address = implode(', ', $ap);

		if ($item['flagMainAddress'])
			$addressFlags[] = ['text' => 'Sídlo', 'class' => 'label label-default'];
		if ($item['flagPostAddress'])
			$addressFlags[] = ['text' => 'Korespondenční', 'class' => 'label label-default'];
		if ($item['flagOffice'])
			$addressFlags[] = ['text' => 'Provozovna', 'class' => 'label label-default'];

		if ($item['id1'] !== '')
			$addressFlags[] = ['text' => 'IČP: '.$item['id1'], 'class' => 'label label-default'];
		if ($item['id2'] !== '')
			$addressFlags[] = ['text' => 'IČZ: '.$item['id2'], 'class' => 'label label-default'];

		$listItem['t1'] = $address;

		if (count($addressFlags))
			$listItem['t2'][] = $addressFlags;

		if ($item['flagContact'])
		{
			$cf = [];
			if ($item['contactName'] != '')
				$cf[] = ['text' => $item['contactName'], 'class' => 'label label-default'];
			if ($item['contactRole'] != '')
				$cf[] = ['text' => $item['contactRole'], 'class' => 'label label-default'];
			if ($item['contactEmail'] != '')
				$cf[] = ['text' => $item['contactEmail'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
			if ($item['contactPhone'] != '')
				$cf[] = ['text' => $item['contactPhone'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];

			if (count($cf))
				$listItem['t3'] = $cf;
		}


		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}
	}

	public function createToolbar ()
	{
		return [];
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		$periods = $this->app->cfgItem ('e10doc.acc.periods');
		$periodsEnum = [];
		forEach ($periods as $periodNdx => $periodCfg)
			$periodsEnum[$periodNdx] = ['title' => $periodCfg['fullName'], 'id' => $periodNdx];

		$paramsFiscalPeriods = new \E10\Params ($panel->table->app());
		$paramsFiscalPeriods->addParam ('checkboxes', 'query.fiscalPeriods', ['items' => $periodsEnum]);
		$qry[] = ['id' => 'fiscalPeriods', 'style' => 'params', 'title' => 'Použito ve fiskálním období', 'params' => $paramsFiscalPeriods];

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}
