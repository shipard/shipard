<?php

namespace e10\base\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class PlacesList
 * @package e10\base\dataView
 */
class PlacesList extends DataView
{
	/** @var \e10doc\base\TablePlaces */
	var $tablePlaces;
	/** @var \e10\persons\TableAddress */
	var $tableAddress;

	var $mainType = FALSE;

	protected function init()
	{
		parent::init();
		$this->tablePlaces = $this->app()->table('e10.base.places');
		$this->tableAddress = $this->app()->table('e10.persons.address');
		$this->mainType = $this->requestParam ('placeType', FALSE);
	}

	protected function loadData()
	{
		$q [] = 'SELECT places.*, parent.id as parentId from [e10_base_places] AS places';
		$q [] = ' LEFT JOIN e10_base_places AS parent ON places.placeParent = parent.ndx';
		array_push ($q, ' WHERE 1');

		if ($this->mainType !== FALSE)
			array_push ($q, ' AND places.[placeType] = %s', $this->mainType);
		array_push ($q, ' AND places.docStateMain < %i', 3);
		array_push ($q, ' ORDER BY places.[id], places.[fullName]');

		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['id' => $r['id'], 'fullName' => $r['fullName'], 'shortName' => $r['shortName'], 'shortcutId' => $r['shortcutId']];

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		$placesAddress = $this->tableAddress->loadAddresses($this->tablePlaces, $pks, FALSE);
		foreach ($placesAddress as $placeNdx => $pa)
		{
			if (!count($pa[0]))
				continue;
			$t[$placeNdx]['addresses'] = $pa;
			$t[$placeNdx]['address'] = $pa[0];
			$t[$placeNdx]['addressText'] = $pa[0]['text'];
		}

		$this->data['header'] = ['#' => '#', 'id' => 'id', 'fullName' => 'Název', 'addressText' => 'Adresa'];
		$this->data['table'] = $t;
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'map')
			return $this->renderDataAsGoogleMap();
		if ($showAs === 'list')
			return $this->renderDataAsList();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsGoogleMap()
	{
		$urlPrefix = $this->requestParam('urlPrefix');

		// -- prepare markers
		$markers = [];
		$infoWindows = [];
		foreach ($this->data['table'] as $placeNdx => $place)
		{
			if (!isset($place['address']))
				continue;
			if ($place['address']['locState'] !== 1)
				continue;
			$markers[] = [$place['fullName'], $place['address']['lat'], $place['address']['lon']];

			$iw = '';
			$iw .= '<div class="info_content">';
			$iw .= '<h5>'.utils::es($place['fullName'])."</h5>";
			$iw .= '<p>'.utils::es($place['address']['text']).'</p>';

			if ($urlPrefix !== '' && $place['shortcutId'] !== '')
			{
				$url = $this->app()->urlRoot.'/'.utils::es($urlPrefix).'/'.$place['shortcutId'];
				$iw .= "<a href='$url' class='btn btn-primary btn-sm'>".utils::es('Více informací').'</a>';
			}

			$iw .= '</div>';

			$infoWindows [] = [$iw];
		}


		// -- generate code
		$c = '';
		$mapId = '8767';//strval(mt_rand(10000, 999999));

		$c .= "<div id='map_wrapper_$mapId' class='e10-embedd-map' data-embedd-map-id='$mapId' style='height: 65vh;'>";
		$c .= "<div id='map_canvas_$mapId' class='mapping' style='width: 100%; height:100%;'></div>";
		$c .= '</div>';
		$c .= "\n<script>\n";
		$c .= "var markers = ".json_encode($markers).";\n";
		$c .= "var infoWindowsContent = ".json_encode($infoWindows).";\n";
		$c .= "</script>\n";

		return $c;
	}

	protected function renderDataAsList()
	{
		$urlPrefix = $this->requestParam('urlPrefix');
		$c = '';

		$elementClass = $this->requestParam('elementClass', 'dataView-places-list');

		$c .= "<ul class='$elementClass'>";

		foreach ($this->data['table'] as $placeNdx => $place)
		{
			$c .= '<li>';
			if ($urlPrefix !== '' && $place['shortcutId'] !== '')
			{
				$url = $this->app()->urlRoot.'/'.utils::es($urlPrefix).'/'.$place['shortcutId'];
				$c .= "<a href='$url'>".utils::es($place['fullName']).'</a>';
			}
			else
				$c .= utils::es($place['fullName']);

			$c .= '</li>';
		}

		$c .= "</ul>";

		return $c;
	}
}


