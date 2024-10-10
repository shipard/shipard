<?php

namespace mac\lan\dataView;


/**
 * class SWLicenses
 */
class SWLicenses extends \lib\dataView\DataView
{
	protected function init()
	{
		$this->checkRequestParamsList('lan');
	}

	protected function loadData()
	{
		$t = [];

		$q[] = 'SELECT licenses.*';
		array_push($q, ' FROM mac_lan_swLicenses AS licenses');
		array_push($q, ' LEFT JOIN mac_lan_swApplications AS [apps] ON licenses.application = apps.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND licenses.docState = %i', 4000);
		array_push($q, ' ORDER BY licenses.fullName, licenses.id');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'id' => $r['id'],
				'name' => $r['fullName'],
				'maxDevices' => $r['maxDevices'],
				'usedDevices' => $r['maxDevices'],
				'availableDevices' => 0,
				'invoiceNumber' => $r['invoiceNumber'],
				'licenseNumber' => $r['licenseNumber'],
				'validFrom' => $r['validFrom'],
			];

			$item['_options']['cellCss']['licenseNumber'] = 'font-size: 75%;';

			$t[] = $item;
		}

		$this->data['header'] = [
			'#' => '#',
			'id' => 'id',
			'name' => 'Název',
			'maxDevices' => 'Počet',
			'usedDevices' => 'Použito',
			'availableDevices' => 'Volné',
			'licenseNumber' => 'Licenční číslo',
		];
		$this->data['table'] = $t;
	}
}
