<?php
namespace e10doc\waster\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class WasteCompanyInfo
 */
class WasteCompanyInfo extends Utility
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;
  var $personNdx = 0;
	var $personICOB = '';

  var $codeKindDef = NULL;
  var $codeKindNdx = 1;
  var $dir = 0;


  var $data = [];
  var $sumData = [];
  var $itemsData = [];

  public function setPeriod ($pb, $pe)
  {
    $this->periodBegin = $pb;
    $this->periodEnd = $pe;
  }

  public function setPerson ($personNdx)
  {
    $this->personNdx = $personNdx;

		$this->personICOB = $this->personICOB($this->personNdx);
  }

  protected function personICOB($personNdx)
  {
		$q = [];
    array_push ($q, 'SELECT * FROM [e10_base_properties] AS props');
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'cz_icob');

    $rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			$id = trim($r['valueString']);
      return $id;
		}

    return '';
  }

  public function loadData()
  {
    $this->codeKindDef = $this->app()->cfgItem('e10.witems.codesKinds.'.$this->codeKindNdx, NULL);

    $this->loadData_Rows ();
  }

	public function loadData_Rows ()
	{
		$tableHeads = $this->app()->table ('e10doc.core.heads');

		$q = [];

    array_push ($q, 'SELECT [rows].person, [rows].personOffice, [rows].wasteCodeNomenc, SUM([rows].quantityKG) as quantityKG,');
    array_push ($q, ' nomencItems.fullName, nomencItems.itemId,');
    array_push ($q, ' persons.fullName AS personFullName,');
    array_push ($q, ' addrs.adrSpecification, addrs.adrCity, addrs.adrZipCode, addrs.adrStreet, addrs.id1, addrs.id2, addrs.saAdmUnit11Id,');
		array_push ($q, ' [rows].[addressMode], [rows].[nomencCity],');
		array_push ($q, ' [heads].docNumber, [heads].dateAccounting, [heads].otherAddress1Mode, [heads].personNomencCity');
		array_push ($q, ' FROM e10pro_reports_waste_cz_returnRows AS [rows]');
    array_push ($q, ' LEFT JOIN [e10_base_nomencItems] AS nomencItems ON [rows].wasteCodeNomenc = nomencItems.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_personsContacts] AS addrs ON [rows].personOffice = addrs.ndx');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON [rows].person = persons.ndx');
		array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [rows].[wasteCodeKind] = %i', $this->codeKindNdx);
		array_push ($q, ' AND [rows].personType = %i', 2);
    array_push ($q, ' AND [rows].[dir] = %i', $this->dir);
		array_push ($q, ' AND [rows].[person] = %i', $this->personNdx);
    if ($this->periodBegin)
      array_push ($q, ' AND [rows].[dateAccounting] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [rows].[dateAccounting] <= %d', $this->periodEnd);
		array_push ($q, ' GROUP BY [heads].docNumber, [rows].person, [rows].[addressMode], [rows].personOffice, [rows].nomencCity, wasteCodeNomenc');
    array_push ($q, ' ORDER BY persons.fullName, addrs.id1, addrs.id2, wasteCodeNomenc');

		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
			$wasteCode = $r['itemId'];
			$wasteName = $r['fullName'];
			$wasteCodeSC = $wasteCode;
			if ($this->codeKindDef['reportPersonOutCodeSC'] !== '')
				$wasteCodeSC = $this->codeKindDef['reportPersonOutCodeSC'];

			$quantity = $r['quantityKG'] / 1000; // tons

			$docIdentifiers = $tableHeads->docAdditionsOur ($r, $r);
			$id_icz_our = '';
			$id_icp_our = '';
			foreach ($docIdentifiers as $di)
			{
				if ($di['id'] === 'icz')
					$id_icz_our = $di['identifier'];
				elseif ($di['id'] === 'icp')
					$id_icp_our = $di['identifier'];
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

			if ($r['addressMode'] === 0)
			{ // office
				$id_icp_theirs = ($r['id1'] != '') ? $r['id1'] : '1';
				$id_icp_theirs_text = [];

				if ($this->personICOB != '')
				{
					$id_icp_theirs_text[] = ['text' => 'IČOB: '.$this->personICOB, 'class' => 'break'];//'IČOB: '.;
				}
				else
				{
					if ($r['id2'] != '')
						$id_icp_theirs .= '-'.$r['id2'];
					if ($r['id2'] != '')
					{
						$id_icp_theirs_text[] = ['text' => 'IČZ: '.$r['id2'], 'class' => 'break'];

					}
					elseif ($id_icp_theirs != '')
					{
						$id_icp_theirs_text[] = ['text' => 'IČP: '.$id_icp_theirs, 'class' => ''];
					}
				}
				$id_icp_theirs_text [] = ['text' => implode(', ', $ap), 'class' => 'e10-small break'];
				$id_icp_theirs_text [] = ['text' => 'IČZUJ: '.$r['saAdmUnit11Id'], 'class' => 'e10-small'];
			}
			else
			{ // city
        $nomencCityRecData = $this->app()->loadItem($r['nomencCity'], 'e10.base.nomencItems');
				$id_icp_theirs = $nomencCityRecData['itemId'];
				$id_icp_theirs_text = [
					['text' => 'ORP: '.substr($nomencCityRecData['itemId'], 2), 'class' => ''],
					['text' => $nomencCityRecData['fullName'] ?? '---', 'class' => 'e10-small break']
				];
			}

			$sumRowId = $wasteCode.'-'.$id_icp_our.'-'.$id_icz_our.'-'.$id_icp_theirs;
			if (!isset($this->sumData[$sumRowId]))
			{
				$this->sumData[$sumRowId] = [
					'weight' => 0, 'code' => $wasteCodeSC, 'title' => $wasteName,
					'icp_our' => $id_icp_our, 'icz_our' => $id_icz_our,
					'icp_theirs' => $id_icp_theirs_text
				];
			}
			$this->sumData[$sumRowId]['weight'] += $quantity;

			$itemsRowId = $wasteCode.'-'.$r['docNumber'];
			if (!isset($this->itemsData[$itemsRowId]))
			{
				$this->itemsData[$itemsRowId] = [
					'weight' => 0, 'code' => $wasteCodeSC, 'title' => $wasteName,
					'docNumber' => $r['docNumber'], 'date' => $r['dateAccounting'], 'o' => $r['docNumber'].'-'.$wasteCode
				];
			}
			$this->itemsData[$itemsRowId]['weight'] += $quantity;
		}

		$headerSum = [
			//'icp_our' => 'Naše IČP', 'icz_our' => 'Naše IČZ',

      'icp_theirs' => 'IČP/ORP',
			'code' => 'Kat. č. odpadu', 'title' => 'Název', 'weight' => '+Hmotnost [t]'
		];

		if ($this->dir === 0)
			$periodTitle = 'Přehled odebraných odpadů';
		else
			$periodTitle = 'Přehled dodaných odpadů';

    /*
		if ($this->calendarYear)
		{
			$periodTitle = $this->periodTitleYearBegin.$this->calendarYear;
		}
		else
		{
			$periodTitle = $this->periodTitlePeriodBegin.Utils::datef($this->periodBegin, '%d').' do '.Utils::datef($this->periodEnd, '%d');
		}
    */
		$this->data['sumRows'] = [
			[
				'type' => 'table', 'title' => $periodTitle,
				'table' => \e10\sortByOneKey($this->sumData, 'code'), 'header' => $headerSum,
				'params' => ['precision' => 6]
			]
		];

		$headerItems = ['docNumber' => 'Č. dokladu', 'date' => 'Datum', 'code' => 'Kat. č. odpadu', 'title' => 'Název', 'weight' => '+Hmotnost [t]'];
		$this->data['itemsRows'] = [
			[
				'type' => 'table', 'title' => 'Položkový soupis',
				'table' => \e10\sortByOneKey($this->itemsData, 'o'), 'header' => $headerItems,
				'params' => ['precision' => 6, 'tableClass' => 'rowsSmall']]
		];
	}

}
