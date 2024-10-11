<?php

namespace e10pro\loyp\libs\dc;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;



/**
 * class DCRobot
 */
class DCPersonPointsSummary extends \Shipard\Base\DocumentCard
{
  var $personNdx = 0;

  var \e10\persons\TablePersons $tablePersons;

	public function createContentBody ()
	{
    $this->journal ();
	}

  public function journal ()
	{
    $q = [];
		array_push($q, 'SELECT journal.*,');
    array_push($q, ' heads.docNumber, heads.dateAccounting AS docDateAccounting');
    array_push($q, ' FROM e10pro_loyp_pointsJournal AS journal');
    array_push($q, ' LEFT JOIN e10doc_core_heads AS heads ON journal.document = heads.ndx');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND [journal].person = %i', $this->personNdx);
    array_push($q, ' ORDER BY heads.dateAccounting DESC, heads.docNumber');
		$t = [];
		$rows = $this->table->db()->query($q);
		foreach ($rows as $r)
		{
      $docRecData = $this->app()->loadItem($r['document'], 'e10doc.core.heads');
      $dpe = new \e10pro\loyp\libs\DocsPointsEngine($this->app);
      $dpe->doDocument($docRecData);

      $item = [
        'docNumber' => ['text' => $r['docNumber'], 'docAction' => 'edit', 'pk' => $r['document'], 'table' => 'e10doc.core.heads'],
        'docDateAccounting' => $r['docDateAccounting'],
        'cntPoints' => $r['cntPoints'],
        'explain' => $dpe->calcExplain['stepsLabels'],
      ];

			$t[] = $item;
		}

		$title = [];
		$title[] = ['icon' => 'icon-plug', 'text' => 'Body ' .json_encode($this->recData)];

		$h = [
      '#' => '#',
      'docNumber' => 'Doklad',
      'docDateAccounting' => ' Datum',
      'cntPoints' => '+Body',
      'explain' => 'Výpočet',
    ];
		$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
												'title' => $title, 'header' => $h, 'table' => $t]);
	}

	public function createContent ()
	{
    $this->personNdx = $this->recData['ndx'];
    $this->tablePersons = new \e10\persons\TablePersons($this->app());
		$this->createContentBody ();
	}
}

