<?php

namespace e10pro\soci\libs;
use Shipard\Utils\Utils;


/**
 * class WOEventInfo
 */
class WOEventInfo extends \e10mnf\core\libs\WorkOrderInfo
{
  protected function loadEntries()
  {
		$h = ['#' => '#', 'personName' => 'Jméno'];

		$q[] = 'SELECT [entries].*,';
		array_push($q, ' [persons].fullName AS personFullName');
		array_push($q, ' FROM [e10pro_soci_entries] AS [entries]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [entries].[dstPerson] = [persons].[ndx]');
		array_push($q, ' WHERE [entries].entryTo = %i', $this->recData ['ndx']);
		array_push($q, ' ORDER BY entries.ndx');

		$rows = $this->db()->query($q);
		$list = [];
		forEach ($rows as $r)
		{
			$item = [];
      if ($r['dstPerson'])
      {
        if ($this->forPrint)
          $item['personName'] = $r['personFullName'];
        else
          $item['personName'] = ['text' => $r['personFullName'], 'docAction' => 'edit', 'pk' => $r['person'], 'table' => 'e10.persons.persons'];
      }
      else
        $item['personName'] = $r['lastName'].' '.$r['firstName'];

			$list[] = $item;
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

  public function loadInfo()
  {
    parent::loadInfo();
    $this->loadEntries();
  }

	public function loadRows ()
	{
    parent::loadRows();
	}
}
