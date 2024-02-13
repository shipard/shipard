<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/*
 * class WasteCheckEngine
 */
class WasteCheckEngine extends Utility
{
  var $docNdx = 0;
  var $docRecData = NULL;

  var $currentWRData = [];
  var $currentWRTable = [];
  var $currentWRTableHeader;

  var $checkWRTable = [];
  var $checkWRTableHeader;
  var $checkWRContent = NULL;

  var $newWRData = NULL;
  var $newWRErrors = NULL;

  var $checkOk = 0;

  public function setDocument($docNdx)
  {
    $this->docNdx = $docNdx;
    $this->docRecData = $this->app()->loadItem($this->docNdx, 'e10doc.core.heads');
  }

  protected function loadWasteReturn()
  {
    $this->currentWRData = [];
    $this->currentWRTable = [];

		$q = [];

    array_push($q, 'SELECT [rows].*');
		array_push($q, ' FROM [e10pro_reports_waste_cz_returnRows] as [rows]');
		array_push($q, ' WHERE [document] = %i', $this->docNdx);
		array_push($q, ' ORDER BY [ndx] DESC');

		$data = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
      $this->currentWRData[] = $r->toArray();

			$item = [
				'wc' => $r['wasteCodeText'],
				'quantity' => $r['quantityKG'],
			];

			$this->currentWRTable[] = $item;

		}

    $this->currentWRTableHeader = [
      'wc' => 'Kód odpadu', 'quantity' => ' Množství kg',
    ];
  }

  protected function createWasteReturn()
  {
		$cy = intval(Utils::createDateTime($this->docRecData['dateAccounting'] ?? NULL)->format('Y'));
		$wasteSettings = $this->app()->cfgItem('e10doc.waster.settings.'.$cy, NULL);
		if (!$wasteSettings)
			return;

		$docType = $this->docRecData['docType'] ?? '';
		if (!isset($wasteSettings['docModes'][$docType]))
			return;

    $this->newWRErrors = NULL;

		$wre = new \e10pro\reports\waste_cz\libs\WasteReturnEngine($this->app);
		$wre->year = $cy;
		$wre->createDataForDocument($this->docRecData['ndx']);
    $this->newWRData = $wre->wasteReturnRows;
    if ($wre->wasteReturnErrorLabels && count($wre->wasteReturnErrorLabels))
      $this->newWRErrors = $wre->wasteReturnErrorLabels;
  }

  protected function checkData()
  {
    $this->checkWRTable = [];
    $this->checkWRTableHeader = [
      '#' => '#', 'wc' => 'Kód odpadu', 'quantity' => '+Množství kg', 'note' => 'Pozn.'
    ];

    foreach ($this->currentWRData as $wrRow)
    {
			$item = [
				'wc' => $wrRow['wasteCodeText'],
				'quantity' => $wrRow['quantityKG'],
			];

      $existedRow = $this->searchNewWRRow($wrRow);
      if (!$existedRow)
      {
        $item['_options']['class'] = 'e10-warning2';
        $item['note'] = 'PŘEBÝVÁ v hlášení';
        $this->checkOk = 0;
      }
      else
      {
        $item['_options']['class'] = 'e10-row-plus';
      }

      $this->checkWRTable[] = $item;
    }

    if ($this->newWRData)
    {
      foreach ($this->newWRData as $wrRow)
      {
        if (isset($wrRow['isUsed']))
          continue;

        $item = [
          'wc' => $wrRow['wasteCodeText'],
          'quantity' => $wrRow['quantityKG'],
        ];

        $item['note'] = 'CHYBÍ v hlášení';
        $item['_options']['class'] = 'e10-warning2';
        $this->checkWRTable[] = $item;
        $this->checkOk = 0;
      }
    }

    if ($this->newWRErrors)
    {
      $this->checkOk = 0;
      foreach ($this->newWRErrors as $errLbl)
      {
        $item = [
          'wc' => $errLbl,
          '_options' => ['class' => 'e10-warning3', 'colSpan' => ['wc' => 2]]
        ];
        $this->checkWRTable[] = $item;
      }
    }

    $t = [['icon' => 'system/actionRecycle', 'text' => 'Hlášení o odpadech']];
		$this->checkWRContent = [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'table' => $this->checkWRTable, 'header' => $this->checkWRTableHeader,
			'title' => $t, 'params' => ['disableZeros' => 1, 'precision' => 3]
		];
  }

  protected function searchNewWRRow($wrRow)
  {
    if (!$this->newWRData)
      return NULL;

    foreach ($this->newWRData as &$newWRRow)
    {
      if ($newWRRow['wasteCodeNomenc'] !== $wrRow['wasteCodeNomenc'])
        continue;
      if ($newWRRow['item'] !== $wrRow['item'])
        continue;
      if (round($newWRRow['quantityKG'], 3) !== round($wrRow['quantityKG'], 3))
        continue;
      if (isset($newWRRow['isUsed']))
        continue;

      $newWRRow['isUsed'] = 1;

      return $newWRRow;
    }

    return NULL;
  }

  public function checkDocument()
  {
    $this->checkOk = 1;

    $this->loadWasteReturn();
    $this->createWasteReturn();
    $this->checkData();
  }

  public function repair($dateBegin, $dateEnd)
  {
    $q [] = 'SELECT heads.*, persons.fullName as personName ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.docState = %i', 4000);

    array_push ($q, ' AND heads.docType IN %in', ['purchase', 'invno']);
    array_push ($q, ' AND heads.dateAccounting >= %d', $dateBegin);
    array_push ($q, ' AND heads.dateAccounting <= %d', $dateEnd);

		array_push ($q, ' ORDER BY dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
      $this->setDocument($r['ndx']);
      $this->checkDocument();

      if ($this->checkOk)
        continue;

      if ($this->app()->debug)
        echo "* ".$r['docNumber'];

      $wre = new \e10pro\reports\waste_cz\libs\WasteReturnEngine($this->app);
      $wre->year = intval(Utils::createDateTime($r['dateAccounting'])->format('Y'));
      $wre->resetDocument($r['ndx']);

      if ($this->app()->debug)
        echo "\n";
    }
  }
}

