<?php

namespace hosting\core\libs\dc;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * Class DCInvoicingGroup
 */
class DCInvoicingGroup extends \Shipard\Base\DocumentCard
{
	public function createContentDataSources ()
	{
		$dse = new \hosting\core\libs\InvoicingGroupDSEngine($this->app());
		$dse->setInvoicingGroup($this->recData['ndx']);
		$dse->run();

		$content = $dse->createContentDataSources();
		$this->addContent ('body', $content);

		$priceLegend = $dse->createContentPlansLegend();
		$this->addContent ('body', $priceLegend);
	}

	function addPersons(&$dstTable)
	{
		$q[] = 'SELECT pp.*, persons.fullName AS personName, persons.id AS personId';
		array_push ($q, ' FROM [hosting_core_partnersPersons] AS pp');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON pp.person = persons.ndx');

		array_push($q, ' WHERE pp.partner = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY persons.lastName');

		$rows = $this->db()->query ($q);
		$label = 1;
		foreach ($rows as $r)
		{
			$item = [];
			if ($label)
				$item['p1'] = 'Osoby';
			$item['t1'] = [['text' => $r['personName']]];
			$item['t1'][] = ['text' => '#'.$r['personId'], 'class' => 'pull-right id'];

			if ($r['isSupport'])
				$item['t1'][] = ['text' => '', 'title' => 'Technická podpora zákazníků', 'class' => 'pull-right', 'icon' => 'system/actionSupport'];
			if ($r['isAdmin'])
				$item['t1'][] = ['text' => '', 'title' => 'Správce partnera', 'class' => 'pull-right', 'icon' => 'system/actionSettings'];

			$dstTable[] = $item;
			$label = 0;
		}
	}

	function addLogo ($title, $ndx, &$dstTable)
	{
		if (!$ndx)
		{
			$dstTable[] = [
				'p1' => $title,
				];
			return;
		}

		$att = $this->db()->query ('SELECT * FROM [e10_attachments_files] WHERE [ndx] = %i', $ndx)->fetch();
		$fn = $this->app()->dsRoot.'/att/'.$att['path'].$att['filename'];

		$dstTable[] = [
			'p1' => $title,
			't1' => [
				['text' => '#'.$ndx], ['code' => "<img src='$fn' class='pull-right' style='max-height: 3em; padding: .5ex; '>"]
			]
		];
	}

	public function createContentBody ()
	{
	}

	public function createContent ()
	{
		//$this->createContentHeader ();
		$this->createContentDataSources ();
	}
}
