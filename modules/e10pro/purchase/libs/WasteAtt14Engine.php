<?php

namespace e10pro\purchase\libs;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils;
use \Shipard\Utils\World;


/**
 * class WasteAtt14Engine
 */
class WasteAtt14Engine extends Utility
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;

  var $dataTable = [];
  var $dataHeader = [];

  var $enabledWasteCodes = [
    '020110', '120101', '120103', '150104', '160104', '160106', '160117',
    '160118', '160801', '170401', '170402', '170403', '170404', '170405', '170406', '170407',
    '170411', '200140',
  ];


  public function setPeriod($periodBegin, $periodEnd)
  {
    $this->periodBegin = Utils::createDateTime($periodBegin->format('Y-m-d').' 00:00:00', TRUE);
    $this->periodEnd = Utils::createDateTime($periodEnd->format('Y-m-d').' 23:59:59', TRUE);
  }

  public function loadData()
  {
    /** @var \e10doc\core\TableHeads $tableHeads */
    $tableHeads = $this->app()->table('e10doc.core.heads');

    $pms = [
      0 => 'BP', 1 => 'HP', 2 => 'BK', 3 => 'DOB', 4 => 'FP', 5 => 'PL',
      6 => 'SD doklad', 7 => 'INK', 8 => 'LP', 9 => 'ŠK', 10 => 'PP',
      11 => 'PayPal', 12 => 'Platební brána'
    ];

    $q = [];
    array_push($q, 'SELECT heads.*');
    array_push($q, ', persons.fullName AS personFullName');
    array_push($q, ' FROM [e10doc_core_heads] AS [heads]');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND heads.[docType] = %s', 'purchase');
    array_push($q, ' AND heads.[docState] = %i', 4000);
    array_push($q, ' AND heads.[activateTimeFirst] >= %t', $this->periodBegin);
    array_push($q, ' AND heads.[activateTimeFirst] <= %t', $this->periodEnd);
    array_push($q, ' ORDER BY heads.[activateTimeFirst], heads.[ndx]');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $pioReport = new \e10pro\purchase\libs\WasteInfoInReport($tableHeads, $r->toArray());
      $pioReport->loadData();

      foreach ($pioReport->data['infoWasteCodes'] as $wc)
      {
        $baseWasteCode = substr($wc['wc'], 0, 6);
        if (!in_array($baseWasteCode, $this->enabledWasteCodes))
          continue;

        $item = [
          'docNumber' => $r['docNumber'],
          'wasteCode' => $wc['wc'],
          'date' => Utils::datef($r['activateTimeFirst'], '%d'),
          'time' => Utils::datef($r['activateTimeFirst'], '%T'),
          'personNdx' => $r['person'],
          'personType' => $r['personType'],

          'amount' => $wc['price'],
          'currency' => $r['currency'],
        ];

        if ($r['personType'] === 1)
        { // citizen
          $item['personCardId'] = $this->loadPersonId($r['person'], 'idcn');
          $item['personName'] = $r['personFullName'];

          $personAddress = $this->loadPersonAddress($r['person'], 1);
          $item['personAddress'] = $personAddress['addressText'] ?? '---';
        }
        else
        { // company
          $item['companyId'] = $this->loadPersonId($r['person'], 'oid');
          $item['companyName'] = $r['personFullName'];

          if ($r['personHandover'] != 0)
          {
            $personHandover = $this->app()->loadItem($r['personHandover'], 'e10.persons.persons');
            if ($personHandover)
            {
              $item['personName'] = $personHandover['fullName'];
              $item['personCardId'] = $this->loadPersonId($r['personHandover'], 'idcn');

              $personHandoverAddress = $this->loadPersonAddress($r['personHandover'], 1);
              $item['personAddress'] = $personHandoverAddress['addressText'] ?? '---';
            }
          }
          elseif ($r['cashPersonName'] != '')
          {
            $item['personName'] = $r['cashPersonName'];
            if ($r['cashPersonID'] != '')
              $item['personCardId'] = $r['cashPersonID'];
          }
        }

        if ($r['paymentMethod'] !== 8) // LP
          $item['amountPaid'] = ['text' => Utils::nf($wc['price'], 2), 'suffix' => strtoupper($r['currency'])];
        $item['pm'] = $pms[$r['paymentMethod']] ?? '#'.$r['paymentMethod'];

        $this->dataTable[] = $item;
      }
    }

    $this->dataHeader = [
      '#' => '#',
      'docNumber' => 'Poř. č.',
      'wasteCode' => 'KČ odpadu',
      'date' => 'Datum',
      'time' => 'Čas',
      'personName' => 'Jméno osoby',
      'personAddress' => 'Adresa',
      'personCardId' => 'Průkaz totožnosti',
      'companyName' => 'Název firmy',
      'companyId' => 'IČO',
      'amountPaid' => ' Výše platby',
      'pm' => 'Způs. platby',
    ];
  }

  protected function loadPersonId ($personNdx, $oidType)
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', $oidType);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			return $r['valueString'];
		}

    return '';
	}

	function loadPersonAddress($personNdx, $mainAddress = 0, $addressNdx = 0)
	{
		if (!$personNdx && !$addressNdx)
			return [];

		$q = [];
		array_push($q, 'SELECT [addrs].*');
		array_push($q, ' FROM [e10_persons_personsContacts] AS [addrs]');
		array_push($q, ' WHERE 1');
		if ($personNdx)
		{
			array_push($q, ' AND [addrs].[person] = %i', $personNdx);
			array_push($q, ' AND [addrs].[docState] = %i', 4000);
		}

		array_push($q, ' AND [addrs].[flagAddress] = %i', 1);
		if ($mainAddress)
			array_push($q, ' AND [addrs].[flagMainAddress] = %i', 1);

		if ($addressNdx)
			array_push($q, ' AND [addrs].[ndx] = %i', $addressNdx);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$addr = [
        'ndx' => $r['ndx'],
        'recid' => $r['person'],
        'specification' => $r['adrSpecification'],
        'street' => $r['adrStreet'],
        'city' => $r['adrCity'],
        'zipcode' => $r['adrZipCode'],
        'worldCountry' => $r['adrCountry'],

				'ids' => [],
			];

			if ($r['id1'] !== '')
			{
				$addr['ids'][] = ['title' => 'IČP', 'value' => $r['id1']];
				$addr['id1'] = $r['id1'];
			}
			if ($r['id2'] !== '')
			{
				$addr['ids'][] = ['title' => 'IČZ', 'value' => $r['id2']];
				$addr['id2'] = $r['id2'];
			}

      $ap = [];
      if ($r['adrSpecification'] != '')
        $ap[] = $r['adrSpecification'];
      if ($r['adrStreet'] != '')
        $ap[] = $r['adrStreet'];
      if ($r['adrCity'] != '')
        $ap[] = $r['adrCity'];
      if ($r['adrZipCode'] != '')
        $ap[] = $r['adrZipCode'];

      if ($r['adrCountry'] != 60 && $r['adrCountry'] != 0)
      {
        $country = World::country($this->app(), $r['adrCountry']);
        $ap[] = strtoupper($country['i'] ?? '--');
      }
      $addr['addressText'] = implode(', ', $ap);

			return $addr;
		}
	}
}
