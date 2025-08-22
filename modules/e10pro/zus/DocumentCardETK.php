<?php

namespace e10pro\zus;
use \E10\utils, \E10\Utility, \E10Pro\Zus\zusutils;


/**
 * Class DocumentCardETK
 * @package e10pro\zus
 */
class DocumentCardETK extends \e10\DocumentCard
{
	var $timeTableIsSet = FALSE;

	/** @var  \e10pro\zus\HoursPlanGenerator */
	var $hoursPlanGenerator;

	function createMsgs ()
	{
		$table = [];
		$header = [
			'date' => 'Datum', 'title' => 'Předmět'
		];
		$title = [['icon' => 'tables/e10pro.zus.msgs', 'text' => 'Zprávy pro rodiče', 'class' => 'h2']];

		$q = [];
		array_push($q, 'SELECT msgs.*');
		array_push($q, ' FROM e10pro_zus_msgs AS msgs');
		array_push($q, ' WHERE msgs.vyuka = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY msgDate DESC, ndx DESC');
		array_push($q, ' LIMIT 3');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$itm = [
				'date' => utils::datef($r['msgDate'], '%D'),
				'title' => ['text' => $r['title'], 'docAction' => 'edit', 'table' => 'e10pro.zus.msgs', 'pk' => $r['ndx']]
			];
			$table[] = $itm;
		}

		$btn = [
			'text'=> 'Nová zpráva', 'docAction' => 'new', 'table' => 'e10pro.zus.msgs', 'type' => 'button',
			'actionClass' => 'btn btn-success btn-xs', 'icon' => 'system/actionAdd', 'class' => 'pull-right',
			'addParams' => "__vyuka=".$this->recData['ndx']
		];
		$title[] = $btn;

		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $header, 'table' => $table,
			'title' => $title, 'params' => ['hideHeader' => 1]]);
	}

	function createTimeTable ()
	{
		// -- rozvrh
		$qr [] = 'SELECT rozvrh.*, persons.fullName as personFullName, predmety.nazev as predmet FROM e10pro_zus_vyukyrozvrh AS rozvrh';
		array_push($qr, ' LEFT JOIN e10_persons_persons AS persons ON rozvrh.ucitel = persons.ndx');
		array_push($qr, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');
		array_push($qr, ' WHERE vyuka = %i', $this->recData['ndx']);
		array_push($qr, ' AND rozvrh.[stavHlavni] < %i', 4);
		array_push($qr, ' ORDER BY den, ndx');
		$rows = $this->db()->query ($qr);
		$rozvrh = [];

		$tableRozvrh = $this->app()->table ('e10pro.zus.vyukyrozvrh');
		$dny = $tableRozvrh->columnInfoEnum ('den', 'cfgText');

		foreach ($rows as $r)
		{
			$newItem = ['doba' => $r['zacatek'].' - '.$r['konec'], 'ucitel' => $r['personFullName'], 'den' => $dny[$r['den']]];
			if ($r['predmet'])
				$newItem['predmet'] = $r['predmet'];
			$rozvrh[] = $newItem;
		}
		if (count($rozvrh)) {
			$hr = ['#' => '#', 'den' => 'Den', 'doba' => 'Od - do', 'ucitel' => 'Učitel', 'predmet' => 'Předmět'];
			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $hr, 'table' => $rozvrh,
					'title' => ['icon' => 'system/iconClock', 'text' => 'Rozvrh'], 'params' => ['hideHeader' => 1]]);
			$this->timeTableIsSet = TRUE;
		}
		else
		{
			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table e10-warning2', 'type' => 'line',
					'line' => ['icon' => 'system/iconClock', 'text' => 'Rozvrh není zadán', 'class' => 'h2']]);
		}


		// --- Kontrola docházky v kolektivních ETK
		if ($this->recData['typ'] != 0)
			return;

		$k = new \e10pro\zus\libs\KontrolaKolektivnichETK($this->app());
		$k->vyukaNdx = $this->recData['ndx'];
		$k->init();
		$k->run();

		if (count($k->troubles))
		{
			$hr = ['#' => '#', 'date' => ' Datum', 'msg' => 'Problém', ];
			$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $hr, 'table' => $k->troubles,
					'title' => ['icon' => 'system/iconExclamation', 'text' => 'Problémy', 'class' => 'h1 e10-error'], 'params' => ['__hideHeader' => 1]]);
		}

	}

	public function createContentHeader ()
	{
	}

	public function createContentBody ()
	{
		$this->createTimeTable();
		$this->createMsgs();

		$etkTitle = [];
		if (count($this->hoursPlanGenerator->newHours))
		{
			$nh = $this->hoursPlanGenerator->newHours[0];

			$etkTitle[] = [
					'text' => utils::datef($nh['date']), 'icon' => 'system/actionAdd', 'action' => 'new', 'data-table' => 'e10pro.zus.hodiny',
					'class' => 'pull-right', 'xelement' => 'span', 'xxbtnClass' => 'btn-sm',
					'data-addParams' => '__vyuka='.$this->recData['ndx'].'&__rozvrh='.$nh['rozvrh']['ndx'].'&__ucitel='.$nh['rozvrh']['ucitel'].
							'&__datum='.$nh['date']->format('Y-m-d').'&__zacatek='.$nh['rozvrh']['zacatek'].'&__konec='.$nh['rozvrh']['konec'].
							'&__pobocka='.$nh['rozvrh']['pobocka'].'&__ucebna='.$nh['rozvrh']['ucebna']
			];
		}

		$etkTitle[] = ['text' => 'Hodiny', 'icon' => 'system/iconBook', 'class' => 'h2'];
		if ($this->hoursPlanGenerator->messages())
		{
			foreach ($this->hoursPlanGenerator->messages() as $msg)
				$etkTitle[] = ['text' => $msg['text'], 'class' => 'e10-error block', 'icon' => 'system/iconExclamation'];
		}
		if ($this->hoursPlanGenerator->datumPredcasnehoUkonceni)
		{
			$etkTitle['title'][] = ['text' => 'Student ukončil studium k '.utils::datef($this->hoursPlanGenerator->datumPredcasnehoUkonceni, '%d'), 'class' => 'e10-error block'];
		}


		$list = ['rows' => $this->hoursPlanGenerator->allHours, 'table' => 'e10pro.zus.hodiny', 'title' => [['value' => $etkTitle]]];
		$this->addContent('body', ['pane' => 'e10-pane xxe10-pane-table', 'type' => 'list', 'list' => $list]);
	}

	public function createContentTitle ()
	{
	}

	public function createContent ()
	{
		$this->hoursPlanGenerator = new \e10pro\zus\HoursPlanGenerator($this->app());
		$this->hoursPlanGenerator->setParams(['etkNdx' => $this->recData['ndx'], 'etkRecData' => $this->recData]);
		$this->hoursPlanGenerator->run();

		$this->createContentHeader ();
		$this->createContentBody ();
		$this->createContentTitle ();
	}
}
