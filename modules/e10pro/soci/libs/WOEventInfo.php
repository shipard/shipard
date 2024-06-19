<?php

namespace e10pro\soci\libs;
use Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;

/**
 * class WOEventInfo
 */
class WOEventInfo extends \e10mnf\core\libs\WorkOrderInfo
{
  var $woPersons = [];
  var $entries = [];

  protected function loadEntries()
  {
		$h = ['#' => '#', 'personName' => 'Jméno'];

		$q[] = 'SELECT [entries].*,';
		array_push($q, ' [persons].fullName AS personFullName, [persons].id AS personId');
		array_push($q, ' FROM [e10pro_soci_entries] AS [entries]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [entries].[dstPerson] = [persons].[ndx]');
		array_push($q, ' WHERE [entries].entryTo = %i', $this->recData ['ndx']);
    array_push($q, ' AND [entries].docState != %i', 9800);
		array_push($q, ' ORDER BY persons.fullName, entries.ndx');

		$rows = $this->db()->query($q);
		$list = [];
		forEach ($rows as $r)
		{
      $entryNdx = $r['ndx'];

      $personInfo = [];
			$item = $r->toArray();
      if ($r['dstPerson'])
      {
        if ($this->forPrint)
          $personInfo[] = ['text' => $r['personFullName'], 'class' => 'e10-bold'];
        else
          $personInfo[] = ['text' => $r['personFullName'], 'docAction' => 'edit', 'pk' => $r['dstPerson'], 'table' => 'e10.persons.persons', 'class' => 'e10-bold'];
      }
      else
        $personInfo[] = ['text' => $r['lastName'].' '.$r['firstName'], 'class' => 'e10-bold'];

      $item['personName'] = $personInfo;

      $invoices = [];
      $ie = new \e10pro\soci\libs\EntriesInvoicingEngine($this->app());
      $ie->forPrint = $this->forPrint;
      $ie->init();
      $ie->setEntry($entryNdx);
      $ie->loadInvoices();
      $cnt = 0;
      foreach ($ie->existedInvoicesTable as $ei)
      {
        if (!$cnt)
        {
          $item['invoice'] = $ei['docNumber'];
          $item['price'] = $ei['price'];
          $item['bi'] = $ei['bi'];
          $item ['_options']['cellClasses']['invoice'] = $ei ['_options']['cellClasses']['docNumber'];
          $item ['_options']['cellClasses']['bi'] = $ei['bi']['class'];
          $item['bi']['class'] = '';

          $this->data['peoples'][] = [
            'id' => $r['personId'],
            'fullName' => $r['personFullName'],
          ];
        }
        else
        {
          $ii = [];
          $ii['invoice'] = $ei['docNumber'];
          $ii['price'] = $ei['price'];
          $ii['bi'] = $ei['bi'];
          $ii ['_options']['cellClasses']['invoice'] = $ei ['_options']['cellClasses']['docNumber'];
          $ii ['_options']['cellClasses']['bi'] = $ei['bi']['class'];
          $ii['bi']['class'] = '';
          $invoices[] = $ii;
        }

        $cnt++;
      }

      if (count($invoices))
			  $item['invoices'] = $invoices;

      $this->entries[$entryNdx] = $item;
		}

		if (count ($list))
		{
			$this->data['entriesList'] = [
        'pane' => 'e10-pane e10-pane-table',
        'type' => 'table',
        'title' => ['icon' => 'tables/e10pro.soci.entries', 'text' => 'Přihlášky'],
        'header' => $h, 'table' => $list
      ];
		}
  }

  public function loadPersonsList ()
	{
		$h = ['#' => '#', 'personName' => 'Jméno'];

		$q[] = 'SELECT [rowsPersons].*,';
		array_push($q, ' [persons].fullName AS personFullName');
		array_push($q, ' FROM [e10mnf_core_workOrdersPersons] AS [rowsPersons]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [rowsPersons].[person] = [persons].[ndx]');
		array_push($q, ' WHERE [rowsPersons].workOrder = %i', $this->recData ['ndx']);
		array_push($q, ' ORDER BY rowOrder, rowsPersons.ndx');

		$rows = $this->db()->query($q);
		$list = [];
		forEach ($rows as $r)
		{
      $personNdx = $r['person'];
      $person = $r->toArray();

			$item = [];
			if ($this->forPrint)
				$item['personName'] = ['text' => $r['personFullName'], 'class' => 'e10-bold block'];
			else
				$item['personName'] = ['text' => $r['personFullName'], 'docAction' => 'edit', 'pk' => $r['person'], 'table' => 'e10.persons.persons', 'class' => 'e10-bold block'];

			$this->loadProperties ('e10.persons.persons', $r['person'], $item, $h);
			$list[] = $item;

      $this->woPersons[$personNdx] = $item;
		}

		if (count ($list))
		{
			$this->data['personsList'] = [
        'pane' => 'e10-pane e10-pane-table',
        'type' => 'table',
        'title' => ['icon' => 'system/iconUser', 'text' => 'Osoby'],
        'header' => $h, 'table' => $list
      ];
		}
	}

  public function createMembers()
  {
    /** @var \e10\persons\TablePersons $tablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');
    $h = ['num' => ' #', 'person' => 'Jméno', 'invoice' => '_Faktura', 'price' => ' Cena', 'bi' => '_Uhrazeno'];

    $list = [];

    $rowIdx = 0;
    foreach ($this->entries as $entryNdx => $entry)
    {
      $rowClass = ($rowIdx % 2 === 0) ? 'e10-bg-t9' : '';

      $personNdx = $entry['dstPerson'];
      $personInfo = $entry['personName'];

      if (!$this->forPrint)
      {
        $nyClass= '';
        if ($entry['nextYearContinue'] === 1)
          $nyClass= 'e10-success';
        elseif ($entry['nextYearContinue'] === 2)
          $nyClass= 'e10-error';
        $personInfo[] = ['text' => '', 'type' => 'span', 'title' => 'Přihláška', 'docAction' => 'edit', 'table' => 'e10pro.soci.entries', 'pk' => $entryNdx, 'class' => 'pull-right '.$nyClass, 'icon' => 'tables/e10pro.soci.entries'];
      }

      if (!Utils::dateIsBlank($entry['datePeriodEnd']))
        $personInfo[] = ['text' => 'Do: '.Utils::datef($entry['datePeriodEnd'], '%s'), 'class' => 'e10-me pull-right'];
      if (!Utils::dateIsBlank($entry['datePeriodBegin']))
        $personInfo[] = ['text' => 'Od: '.Utils::datef($entry['datePeriodBegin'], '%d'), 'class' => 'e10-me pull-right'];

      $personInfo[] = ['text' => '', 'class' => 'block', 'css' => 'padding-bottom: .2rem;'];

      $props = [];
      if ($personNdx)
        $props = $tablePersons->loadProperties ($personNdx, ['officialName', 'shortName'], TRUE);
      else
      {

      }

      if (isset($props[$personNdx]))
        $personInfo = array_merge($personInfo, $props[$personNdx]);

      $item = [
        'num' => ($rowIdx + 1).'.',
        'person' => $personInfo,
        'personFullName' => $entry['personName'],
      ];

      if (isset($entry['_options']))
        $item['_options'] = $entry['_options'];

      if ($entry['entryState'] === 1)
      {
        $item['invoice'] = 'Přihláška na zkoušku';
        $item['_options']['colSpan']['invoice'] = 3;
        $item['_options']['cellClasses']['invoice'] = 'e10-bg-t6';
        $item['_options']['cellCss']['invoice'] = 'text-align: center; vertical-align: middle;';
      }
      else
      {
        $item['invoice'] = $entry['invoice'];
        $item['price'] = $entry['price'];
        $item['bi'] = $entry['bi'];
      }

      if (isset($item['person'][0]))
        $item['person'][0]['css'] = 'font-size: 111%; padding-bottom: .2rem;';

      if (isset($entry['invoices']))
      {
        $item['_options']['rowSpan']['person'] = count($entry['invoices']) + 1;
        $item['_options']['rowSpan']['num'] = count($entry['invoices']) + 1;
      }
      $item['_options']['cellCss']['person'] = 'break-inside: avoid;';

      $item['_options']['class'] = $rowClass;


      $list[] = $item;

      if (isset($entry['invoices']))
      {
        foreach ($entry['invoices'] as $invoice)
        {
          $invoice['_options']['class'] = $rowClass;
          $list[] = $invoice;
        }
      }

      $rowIdx++;
    }

    $this->data['members'] = [
      'pane' => 'e10-pane e10-pane-table',
      'type' => 'table',
      //'title' => ['icon' => 'system/iconUser', 'text' => 'Lidé'],
      'header' => $h, 'table' => $list,
    ];
  }


  public function loadInfo()
  {
    parent::loadInfo();
    $this->loadEntries();
    $this->createMembers();
  }

	public function loadRows ()
	{
    parent::loadRows();
	}
}
