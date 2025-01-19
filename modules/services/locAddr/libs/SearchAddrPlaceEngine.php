<?php

namespace services\locAddr\libs;
use \Shipard\Base\Utility;

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

  public function setText($searchText)
  {
    $this->searchText = $searchText;

    $this->parseSearchText();
    $this->loadAddrParts();
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
      $cityParts = $this->db()->query('SELECT ndx FROM [services_locAddr_citiesParts] WHERE [fullName] LIKE %s', '%'.$word.'%');
      foreach ($cityParts as $r)
        $this->qryCityParts[] = $r['ndx'];
    }

    // -- cities
    foreach ($this->searchWords as $word)
    {
      $cities = $this->db()->query('SELECT ndx FROM [services_locAddr_cities] WHERE [fullName] LIKE %s', '%'.$word.'%');
      foreach ($cities as $r)
        $this->qryCities[] = $r['ndx'];
    }
  }
}
