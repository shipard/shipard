<?php

namespace hosting\core\libs\dc;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * Class DCPartner
 */
class DCPartner extends \Shipard\Base\DocumentCard
{
	public function createContentRecap ()
	{
		$tablePortals = $this->app()->table ('hosting.core.hostings');

		$i = $this->recData;

		$portalName = '';
		/*
		if ($i['portal'])
		{
			$portal = $tablePortals->loadItem ($i['portal']);
			$portalName = $portal['name'];
		}
		*/

		$info = [];
		$info[] = ['p1' => 'Název', 't1' => $i['name']];
		$info[] = ['p1' => 'Portál', 't1' => $portalName];
		$info[] = ['p1' => 'Web', 't1' => $i['webUrl']];
		$info[] = ['p1' => 'Email na podporu', 't1' => $i['supportEmail']];
		$info[] = ['p1' => 'Telefon na podporu', 't1' => $i['supportPhone']];

		$this->addLogo ('Logo partnera', $i['logoPartner'], $info);
		$this->addLogo ('Logo - ikona', $i['logoIcon'], $info);

		$this->addPersons($info);

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

//		$title = [['icon' => 'icon-umbrella', 'text' => $i['name'], 'class' => 'h2']];

		$dsStatsInfo = $this->table->partnerDSStats($i['ndx']);
		$title = [
			['text' => utils::nf($dsStatsInfo['2']['cnt']), 'icon' => 'system/iconDatabase', 'class' => 'label label-success', 'title' => 'Počet databází v ostrém provozu'],
			['text' => utils::snf($dsStatsInfo['2']['usageTotal']), 'icon' => 'icon-hdd-o', 'class' => 'label label-success', 'title' => 'Celková velikost databází v ostrém provozu'],
			['text' => utils::snf($dsStatsInfo['2']['cntDocuments12m']), 'icon' => 'icon-file-text-o', 'class' => 'label label-success', 'title' => 'Počet dokladů za posledních 12 měsíců u databází v ostrém provozu'],

			['text' => utils::nf($dsStatsInfo['NONPROD']['cnt']), 'icon' => 'system/iconDatabase', 'class' => 'label label-default', 'title' => 'Počet databází v testovacím provozu'],
			['text' => utils::snf($dsStatsInfo['NONPROD']['usageTotal']), 'icon' => 'icon-hdd-o', 'class' => 'label label-default', 'title' => 'Celková velikost databází v testovacím provozu'],
			['text' => utils::snf($dsStatsInfo['NONPROD']['cntDocuments12m']), 'icon' => 'icon-file-text-o', 'class' => 'label label-default', 'title' => 'Počet dokladů za posledních 12 měsíců u databází v testovacím provozu'],
		];

		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'title' => $title,
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}

	public function createContentDataSources ()
	{
		$dse = new \hosting\core\libs\PartnersDSEngine($this->app());
		$dse->setPartner($this->recData['ndx']);
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
		$this->createContentRecap ();
		$this->createContentDataSources ();
	}
}
