<?php

namespace services\locAddr;

use \Shipard\Utils\Utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail, \Shipard\Utils\Str;
use \Shipard\Viewer\TableViewPanel;

/**
 * class TableAddrPlaces
 */
class TableAddrPlaces extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.locAddr.addrPlaces', 'services_locAddr_addrPlaces', 'Adresní místa');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$idsLabels[] = ['text' => '#'.$recData ['ndx'], 'class' => 'label label-primary pull-right'];
		$idsLabels[] = ['text' => $recData ['addrPlaceId'], 'class' => 'label label-primary pull-right'];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => $idsLabels,
		];

		return $hdr;
	}
}


/**
 * class ViewAddrPlaces
 */
class ViewAddrPlaces extends TableView
{
	public function init()
	{
		parent::init();
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];


		$listItem ['t1'] = [];
		if ($item['streetFullName'])
			$listItem ['t1'][] = ['text' => $item['streetFullName'], 'class' => 'label label-default'];

		$houseNr = ['text' => $item['houseNr'], 'class' => 'label label-default'];
		if ($item['houseNr1Type'])
			$houseNr['prefix'] = 'č.ev.';
		elseif ($item['houseNr1Type'] === 0 && !$item['street'] /*&& !$item['cityPart']*/)
			$houseNr['prefix'] = 'č.p.';
		//else
		//	$houseNr['prefix'] = 'č.p.';

		$listItem ['t1'][] = $houseNr;

		$listItem ['i1'] = ['text' => '#'.Utils::nf($item['addrPlaceId']), 'class' => 'id'];

		$listItem ['t2'] = [];
		//if ($item['cityPartFullName'] && $item['cityPartFullName'] != $item['cityFullName'])
		//	$listItem ['t2'][] = ['text' => $item['cityPartFullName'], 'class' => ''];

		if ($item['cityPart2FullName'])
			$listItem ['t2'][] = ['text' => $item['cityPart2FullName'], 'class' => 'label label-warning'];

		$city = ['text' => $item['cityFullName'], 'class' => 'label label-success'];
		if ($item['zipCodeIdName'])
			$city['suffix'] = $item['zipCodeIdName'];

		if ($item['cityPartFullName'] && $item['cityPartFullName'] != $item['cityFullName'])
			$city['text'] .= ' - '.$item['cityPartFullName'];

		$listItem ['t2'][] = $city;

		if ($item['laUnitOwner2FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner2FullName'], 'class' => 'label label-info'];
		if ($item['laUnitOwner1FullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner1FullName'], 'class' => 'label label-primary'];
		if ($item['laUnitOwner0FullName'] && $item['laUnitOwner0FullName'] != $item['cityFullName'])
			$listItem ['t2'][] = ['text' => $item['laUnitOwner0FullName'], 'class' => 'label label-default'];


		//$listItem ['i2'] = Utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [addrPlaces].*,');
		array_push ($q, ' [cities].[fullName] AS [cityFullName],');
		array_push ($q, ' [zipCodes].[idName] AS [zipCodeIdName],');
		array_push ($q, ' [citiesParts].[fullName] AS [cityPartFullName],');
		array_push ($q, ' [citiesParts2].[fullName] AS [cityPart2FullName],');
		array_push ($q, ' [streets].[fullName] AS [streetFullName],');
    array_push ($q, ' [laUnits2].[fullName] AS [laUnitOwner2FullName],');
    array_push ($q, ' [laUnits1].[fullName] AS [laUnitOwner1FullName],');
    array_push ($q, ' [laUnits0].[fullName] AS [laUnitOwner0FullName]');
		array_push ($q, ' FROM [services_locAddr_addrPlaces] AS [addrPlaces]');
		array_push ($q, ' LEFT JOIN [services_locAddr_cities] AS [cities] ON addrPlaces.city = cities.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_zipCodes] AS [zipCodes] ON addrPlaces.zipCode = zipCodes.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_citiesParts] AS [citiesParts] ON addrPlaces.cityPart = citiesParts.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_citiesParts] AS [citiesParts2] ON addrPlaces.cityPart2 = citiesParts2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_streets] AS [streets] ON addrPlaces.street = streets.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits2] ON cities.laUnit2 = laUnits2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits1] ON cities.laUnit1 = laUnits1.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits0] ON cities.laUnit0 = laUnits0.ndx');

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			$se = new \services\locAddr\libs\SearchAddrPlaceEngine($this->app());
			$se->setText($fts);
			if (count($se->qryStreets) || count($se->qryNumbers))
			{
				array_push ($q, ' AND (1 ');

				if (count($se->qryStreets) || count($se->qryCityParts))
				{
					$operator = ' OR ';
					//if (count($se->qryStreets) && count($se->qryCities))
					//	$operator = ' AND ';
					//elseif(count($se->qryCities) && count($se->qryCityParts))
					//	$operator = ' AND ';

					array_push ($q, ' AND ( ');
					if ($operator === ' OR ')
						array_push ($q, '0');
					else
						array_push ($q, '1');

					if (count($se->qryStreets))
						array_push ($q, $operator.'[addrPlaces].[street] IN %in', $se->qryStreets);
					if (count($se->qryCityParts))
						array_push ($q, $operator.'[addrPlaces].[cityPart] IN %in', $se->qryCityParts);
					array_push ($q, ')');
				}

				//if (count($se->qryCities))
				//	array_push ($q, ' AND [addrPlaces].[city] IN %in', $se->qryCities);

				if (count($se->qryNumbers))
					array_push ($q, ' AND [addrPlaces].[houseNr1] IN %in', $se->qryNumbers);

			//array_push ($q, '[streets].[fullName] LIKE %s', '%'.$fts.'%');

      //array_push ($q, '[streets].[fullName] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [citiesParts].[fullName] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [cities].[fullName] LIKE %s', '%'.$fts.'%');
				array_push ($q, ')');
			}
    }

    array_push ($q, ' ORDER BY ndx');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
	}
}


/**
 * Class FormAddrPlace
 */
class FormAddrPlace extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('addrPlaceId');
			$this->addColumnInput ('houseNr1Type');
			$this->addColumnInput ('houseNr1');
			$this->addColumnInput ('houseNr2');
			$this->addColumnInput ('houseNrLetter');
			$this->addColumnInput ('houseNr');
			$this->addColumnInput ('street');
			$this->addColumnInput ('cityPart');
			$this->addColumnInput ('cityPart2');
			$this->addColumnInput ('city');
			$this->addColumnInput ('zipCode');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('laUnit11');
			$this->addColumnInput ('laUnit10');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('natGeoCoordX');
			$this->addColumnInput ('natGeoCoordY');
			$this->addColumnInput ('wgs84lat');
			$this->addColumnInput ('wgs84lng');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailAddrPlace
 */
class ViewDetailAddrPlace extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.locAddr.libs.dc.DCAddrPlace');
	}
}
