<?php

namespace services\locAddr\libs;
use \Shipard\Base\Utility;
use \shipard\Utils\Str;
use \shipard\Utils\Json;


/**
 * class SearchAddrPlaceEngine
 */
class SearchAddrPlaceEngine extends Utility
{
  var $searchText = '';

  var $searchWords = [];

  var $qryNumbers = [];
  var $qryStreets = [];
  var $qryCityParts = [];
  var $qryCities = [];
  var $qryZipCodes = [];

  var $cntQueryCrits = 0;

  public function setText($searchText)
  {
    $this->searchText = $searchText;

    $this->parseSearchText();
    $this->loadAddrParts();
  }

  public function setText2($searchText)
  {
    $mainParts = preg_split("/[,]+/", $searchText);
    foreach ($mainParts as $part)
    {
      $part = trim($part);
      if ($part === '')
        continue;

      $subParts = preg_split("/[\s]+/", $part);
      $searchWords = [];
      foreach ($subParts as $sp)
      {
        if (is_numeric($sp) && !str_ends_with($sp, '.'))
        {
          $num = intval($sp);
          if ($num)
          {
            $this->setZipCode($sp);
            if (!count($this->qryZipCodes) && !str_ends_with($sp, '.'))
              $this->qryNumbers[] = intval($sp);
          }
          continue;
        }

        $searchWords[] = trim($sp);
      }
      $st = implode(' ', $searchWords);

      $this->setCityName($st, 0, TRUE);
      if (count($this->qryCities) > 2)
        continue;
      $this->setStreetName($st);
    }

    $this->cntQueryCrits = (count($this->qryCities) > 0) +
                            (count($this->qryCityParts) > 0) +
                            (count($this->qryStreets) > 0) +
                            (count($this->qryNumbers) > 0) +
                            (count($this->qryZipCodes) > 0);
  }

  public function setStreetName($streetName)
  {
    if (Str::strlen($streetName) < 4)
      return;
    $sn = $streetName;
    $sn2 = preg_replace('/(?:www\.(?:(?i:[a-z-]+)\.)+[a-z-]+|(?:i\.e|e\.g)\.?|\d+\.[A-Z]+)(*SKIP)(*FAIL)|(?<=[.,])(?=\S)/u', ' ',$sn);

    $parts = explode(' ', Str::tolower($sn2));
    $lastPart = array_pop($parts);
    $isHouseNr = (strlen($lastPart) === strspn($lastPart, '0123456789abcdefghij/'));
    if (!$isHouseNr)
    {
      array_push($parts, $lastPart);
    }
    else
    {
      $hnrParts = explode('/', $lastPart);
      if (isset($hnrParts[0]) && !str_ends_with($hnrParts[0], '.'))
        $this->qryNumbers[] = intval(trim($hnrParts[0], 'abcdefghij'));
    }
    $streetNameQ = implode(' ', $parts);

    $streets = $this->db()->query('SELECT ndx FROM [services_locAddr_streets] WHERE [fullName] = %s', $streetNameQ);
    foreach ($streets as $r)
    {
      if (!in_array($r['ndx'], $this->qryStreets))
        $this->qryStreets[] = $r['ndx'];
    }
    $streets = $this->db()->query('SELECT ndx FROM [services_locAddr_streets] WHERE [fullName] LIKE %s', $streetNameQ.'%');
    foreach ($streets as $r)
      if (!in_array($r['ndx'], $this->qryStreets))
        $this->qryStreets[] = $r['ndx'];

    // -- cities?
    $cities = $this->db()->query('SELECT ndx FROM [services_locAddr_cities] WHERE [fullName] = %s', $streetNameQ);
    foreach ($cities as $r)
      if (!in_array($r['ndx'], $this->qryCities))
        $this->qryCities[] = $r['ndx'];

    // -- cityParts?
    $cityParts = $this->db()->query('SELECT ndx FROM [services_locAddr_citiesParts] WHERE [fullName] = %s', $streetNameQ);
    foreach ($cityParts as $r)
      if (!in_array($r['ndx'], $this->qryCityParts))
        $this->qryCityParts[] = $r['ndx'];
  }

  public function setCityName($cityName, $tryLevel = 0, $inCitiesParts = FALSE)
  {
    if (Str::strlen($cityName) < 4)
      return;
    $cities = $this->db()->query('SELECT ndx FROM [services_locAddr_cities] WHERE [fullName] = %s', $cityName);
    foreach ($cities as $r)
    {
      if (!in_array($r['ndx'], $this->qryCities))
        $this->qryCities[] = $r['ndx'];
    }

    if (!count($this->qryCities) || $inCitiesParts)
    {
      $cityParts = $this->db()->query('SELECT ndx FROM [services_locAddr_citiesParts] WHERE [fullName] = %s', $cityName);
      foreach ($cityParts as $r)
      {
        if (!in_array($r['ndx'], $this->qryCityParts))
          $this->qryCityParts[] = $r['ndx'];
      }
    }

    if ($tryLevel === 1)
      return;

    if (!count($this->qryCities))
    {
      $parts = explode(' ', $cityName);
      $validParts = [];
      foreach ($parts as $part)
      {
        $isHouseNr = (strlen($part) === strspn($part, '0123456789abcde/'));
        if ($isHouseNr && !str_ends_with($part, '.'))
        {
          $this->qryNumbers[] = intval($part);
          continue;
        }
        array_push($validParts, $part);
      }
      $cityNameQ = implode(' ', $validParts);

      if ($cityNameQ !== '')
        $this->setCityName($cityNameQ, 1);
    }
  }

  public function setZipCode($zipCode)
  {
    $zc = intval(str_replace(' ', '', $zipCode));
    if ($zc === 0)
      return;

    $zipCodes = $this->db()->query('SELECT ndx FROM [services_locAddr_zipCodes] WHERE [zipCodeId] = %i', $zc);
    foreach ($zipCodes as $r)
      $this->qryZipCodes[] = $r['ndx'];
  }

  protected function parseSearchText()
  {
    $words = preg_split("/[^\w]*([\s]+[^\w]*|$)/", $this->searchText, -1, PREG_SPLIT_NO_EMPTY);

    $w = '';
    foreach ($words as $word)
    {
      if (is_numeric($word))
      {
        $num = intval($word);
        if ($num)
          $this->qryNumbers[] = intval($word);
        continue;
      }

      if (strlen($word) < 3)
      {
        $w .= ' '.$word;
        continue;
      }

      if ($w !== '')
      {
        $this->searchWords[] = trim($w.' '.$word);
        $w = '';
        continue;
      }

      $this->searchWords[] = trim($word);
    }
  }

  protected function loadAddrParts()
  {
    // -- streets
    foreach ($this->searchWords as $word)
    {
      $streets = $this->db()->query('SELECT ndx FROM [services_locAddr_streets] WHERE [fullName] LIKE %s', '%'.$word.'%');
      foreach ($streets as $r)
        $this->qryStreets[] = $r['ndx'];
    }

    // -- cityParts
    foreach ($this->searchWords as $word)
    {
      $cityParts = $this->db()->query('SELECT ndx FROM [services_locAddr_citiesParts] WHERE [fullName] LIKE %s', $word.'%');
      foreach ($cityParts as $r)
        $this->qryCityParts[] = $r['ndx'];
    }

    // -- cities
    foreach ($this->searchWords as $word)
    {
      $cities = $this->db()->query('SELECT ndx FROM [services_locAddr_cities] WHERE [fullName] LIKE %s', $word.'%');
      foreach ($cities as $r)
      {
        if (!in_array($r['ndx'], $this->qryCities))
          $this->qryCities[] = $r['ndx'];
      }
    }
  }

  public function makeQueryBase(&$q)
  {
		array_push ($q, ' SELECT [addrPlaces].*,');
		array_push ($q, ' [cities].[fullName] AS [cityFullName], [cities].[cityId] AS [saCityId],');
		array_push ($q, ' [zipCodes].[idName] AS [zipCodeIdName],');
		array_push ($q, ' [citiesParts].[fullName] AS [cityPartFullName], [citiesParts].[cityPartId] AS [saCityPartId],');
		array_push ($q, ' [citiesParts2].[fullName] AS [cityPart2FullName], [citiesParts2].[cityPartId] AS [saCityPart2Id],');
		array_push ($q, ' [streets].[fullName] AS [streetFullName], [streets].[streetId] AS [saStreetId],');
    array_push ($q, ' [laUnits2].[laUnitId] AS [admUnit2Id], [laUnits2].[fullName] AS [admUnit2FullName],');
    array_push ($q, ' [laUnits1].[laUnitId] AS [admUnit1Id], [laUnits1].[fullName] AS [admUnit1FullName],');
    array_push ($q, ' [laUnits0].[laUnitId] AS [admUnit0Id], [laUnits0].[fullName] AS [admUnit0FullName],');
    array_push ($q, ' [laUnits11].[laUnitId] AS [admUnit11Id], [laUnits11].[fullName] AS [admUnit11FullName],');
    array_push ($q, ' [laUnits10].[laUnitId] AS [admUnit10Id], [laUnits10].[fullName] AS [admUnit10FullName]');
		array_push ($q, ' FROM [services_locAddr_addrPlaces] AS [addrPlaces]');
		array_push ($q, ' LEFT JOIN [services_locAddr_cities] AS [cities] ON addrPlaces.city = cities.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_zipCodes] AS [zipCodes] ON addrPlaces.zipCode = zipCodes.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_citiesParts] AS [citiesParts] ON addrPlaces.cityPart = citiesParts.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_citiesParts] AS [citiesParts2] ON addrPlaces.cityPart2 = citiesParts2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_streets] AS [streets] ON addrPlaces.street = streets.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits2] ON cities.laUnit2 = laUnits2.ndx');
		array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits1] ON cities.laUnit1 = laUnits1.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits0] ON cities.laUnit0 = laUnits0.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits11] ON addrPlaces.laUnit11 = laUnits11.ndx');
    array_push ($q, ' LEFT JOIN [services_locAddr_laUnits] AS [laUnits10] ON addrPlaces.laUnit10 = laUnits10.ndx');
		array_push ($q, ' WHERE 1');
  }

  public function clearAddrPlaceRec(&$addrPlaceRec)
  {
    unset($addrPlaceRec['ndx']);
    unset($addrPlaceRec['laUnit10']);
    unset($addrPlaceRec['laUnit11']);
    unset($addrPlaceRec['zipCode']);
    unset($addrPlaceRec['street']);
    unset($addrPlaceRec['city']);
    unset($addrPlaceRec['cityPart']);
    unset($addrPlaceRec['cityPart2']);

    Json::polish($addrPlaceRec);
  }
}
