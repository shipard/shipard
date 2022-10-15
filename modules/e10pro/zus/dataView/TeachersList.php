<?php

namespace e10pro\zus\dataView;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use e10pro\zus\zusutils;



/**
 * Class TeachersList
 * @package e10pro\zus\dataView
 */
class TeachersList extends \e10\persons\dataView\PersonsList
{
	protected function init()
	{
		parent::init();
		$this->setMainGroup ('e10pro-zus-groups-teachers');

		$this->checkRequestParamsList('officeId', TRUE);
		if (isset($this->requestParams['officeId']))
		{
			if ($this->requestParams['officeId'][0] === 'URL')
				$this->requestParams['officeId'][0] = $this->app()->requestPath(count($this->app()->requestPath) - 1);

			$rows = $this->db()->query('SELECT ndx FROM [e10_base_places] WHERE [shortcutId] IN %in', $this->requestParams['officeId']);
			if ($rows)
			{
				$this->requestParams['offices'] = [];
				foreach ($rows as $r)
					$this->requestParams['offices'][] = $r['ndx'];
			}
		}
	}

	protected function extendQuery (&$q)
	{
		if (isset($this->requestParams['offices']))
		{
			array_push($q, ' AND EXISTS (');
			array_push($q, ' SELECT ucitel FROM e10pro_zus_vyuky AS vyuky WHERE persons.ndx = vyuky.ucitel');
			array_push($q, ' AND vyuky.misto IN %in', $this->requestParams['offices']);
			array_push($q, ' AND vyuky.skolniRok = %s', zusutils::aktualniSkolniRok());
			array_push($q, ' AND vyuky.stavHlavni = %i', 2);
			array_push($q, ')');
		}
	}
}



