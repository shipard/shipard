<?php

namespace e10doc\core\libs;
use \e10\utils;
use \e10doc\core\libs\E10Utils;


class GlobalParams extends \e10\Params
{
	public function setParamContent ($paramType, $paramId = FALSE, $options = NULL)
	{
		$standardParams = [
			'vatPeriod' => ['title' => isset($options['title']) ? $options['title'] : 'Období DPH'],
			'fiscalPeriod' => ['title' => isset($options['title']) ? $options['title'] : 'Období'],
			'fiscalYear' => ['title' => isset($options['title']) ? $options['title'] : 'Období'],
			'cashBox' => ['title' => isset($options['title']) ? $options['title'] : 'Pokladna'],
			'warehouse' => ['title' => isset($options['title']) ? $options['title'] : 'Sklad'],
			'centre' => ['title' => isset($options['title']) ? $options['title'] : 'Středisko']
		];

		if (!isset ($standardParams[$paramType]))
		{
			parent::setParamContent ($paramType, $paramId, $options);
			return;
		}

		$pc = $standardParams[$paramType];
		$pc['type'] = $paramType;
		$pc['id'] = ($paramId === FALSE) ? $paramType : $paramId;
		$pc['options'] = ($options === NULL) ? [] : $options;
		$pc['values'] = array ();
		$pc['value'] = 0;
		$pc['defaultValue'] = 1;

		switch ($paramType)
		{
			case 'cashBox':
				$cashboxes = $this->app->cfgItem ('e10doc.cashBoxes', array());
				forEach ($cashboxes as $cbndx => $cb)
					$pc['values'][$cbndx] = array ('title' => $cb['fullName']);
				if (isset ($this->app->workplace['cashBox']))
					$pc['defaultValue'] = $this->app->workplace['cashBox'];
				if ($options && isset ($options['flags']) && in_array('enableAll', $options['flags']))
					$pc['values'][0]['title'] = 'Vše';
				break;
			case 'warehouse':
				$pc['values'][0]['title'] = 'Všechny';
				$warehouses = $this->app->cfgItem ('e10doc.warehouses', array());
				forEach ($warehouses as $whndx => $wh)
					$pc['values'][$whndx] = array ('title' => $wh['fullName']);
				$pc['defaultValue'] = '0';
				break;
			case 'centre':
				$centres = $this->app->cfgItem ('e10doc.centres', array());
				forEach ($centres as $cntrndx => $centre)
					$pc['values'][$cntrndx] = ['title' => $this->hasFlag ($pc, 'id') ? $centre['id'] : $centre['shortName']];
				$pc['values'][0]['title'] = '-';
				$pc['defaultValue'] = key($pc['values']);
				break;
			case 'fiscalPeriod':
				$this->createFiscalPeriodParam ($pc);
				break;
			case 'fiscalYear':
				$this->createFiscalYearParam ($pc);
				break;
			case 'calendarYear':
				$this->createCalendarYearParam ($pc);
				break;
			case 'vatPeriod':
				if ($this->createVATPeriodParam ($pc) === FALSE)
					return;
				break;
		}

		$this->params [$pc['id']] = $pc;
	}

	function createParamCode ($paramId)
	{
		switch ($this->params[$paramId]['type'])
		{
			case 'fiscalPeriod': return $this->createFiscalPeriodParamCode($paramId);
			case 'vatPeriod': return $this->createVATPeriodParamCode($paramId);
		}
		return parent::createParamCode ($paramId);
	}

	function createVATPeriodParam (&$p)
	{
		$periods = $this->app->db()->query("SELECT * FROM [e10doc_base_taxperiods] WHERE docState != 9800 ORDER BY [id] DESC")->fetchAll ();
		if (count($periods) === 0)
			return FALSE;

		if ($this->hasFlag ($p, 'enableAll'))
			$p['values'][0] = array ('title' => 'Vše', 'fiscalYear' => 0, 'calendarYear' => 0, 'calendarMonth' => 0, 'dateBegin' => '', 'dateEnd' => '');

		forEach ($periods as $r)
		{
			$p['values'][$r['ndx']] = array ('title' => $r['fullName'], 'dateBegin' => $r['start'], 'dateEnd' => $r['end']);
			$year = $r['start']->format ('Y');

			if (!isset ($p['calendarYears'][$year]))
				$p['calendarYears'][$year] = array ('title' => $year, 'months' => array ());

			$titleParts = explode('/', $r['fullName']);
			$shortTitle = isset ($titleParts[1]) ? $titleParts[1] : $r['fullName'];
			$p['calendarYears'][$year]['months'][] = array ('title' => $shortTitle, 'fullTitle' => $r['fullName'], 'value' => $r['ndx']);
		}

		if ($this->hasFlag ($p, 'enableAll'))
			$p['defaultValue'] = 0;
		else
		{
			$today = new \DateTime();
			$period = $this->app->db()->query("SELECT * FROM [e10doc_base_taxperiods] WHERE start <= %d AND end >= %d ORDER BY [id] DESC", $today, $today)->fetch ();
			if ($period)
				$p['defaultValue'] = $period ['ndx'];
		}

		return TRUE;
	}

	function createVATPeriodParamCode ($paramId)
	{
		$p = $this->params [$paramId];
		$activeValue = $p['value'];
		$activeTitle = $p['values'][$activeValue]['title'];

		$cntCols = 13;
		if ($this->hasFlag ($p, 'quarters'))
			$cntCols += 4;
		if ($this->hasFlag ($p, 'halfs'))
			$cntCols += 2;

		$inputClass = ($this->inputClass === '') ? '' : " class='$this->inputClass'";
		$c = '';
		$c .= "<div class='btn-group e10-param' data-paramid='$paramId'>";
		$c .= "<button type='button' class='btn btn-default dropdown-toggle e10-report-param' data-toggle='dropdown'>".
			'<b>'.utils::es ($p['title']).":</b> <span class='v'>".utils::es($activeTitle).'</span>'.
			" <span class='caret'></span>".
			'</button>';
		$c .= "<input name='$paramId' type='hidden'$inputClass value='$activeValue'>";
		$c .= "<div class='dropdown-menu' role='menu'>";

		$c .= "<table class='e10-param-calper'>";

		if ($this->hasFlag ($p, 'enableAll'))
		{
			$class = 'all';
			if ($activeValue === 0) $class .= ' active';
			$c .= "<tr class='all'><td colspan='$cntCols' data-value='0' data-title='Vše' class='$class'><a href='#'>Vše</a></td></tr>";
		}

		forEach ($p['calendarYears'] as $year)
		{
			$c .= "<tr>";
			$c .= "<td class='x y'>".utils::es($year['title']).'</td>';

			forEach (array_reverse($year['months']) as $month)
			{
				$mid = $month['value'];
				$class = ($mid == $activeValue) ? " class='active m'": "class='m'";
				$t = utils::es($month['fullTitle']);
				$c .= "<td data-value='$mid' data-title='$t'$class><a href='#'>" . utils::es($month['title']) . '</a></td>';
			}

			$c .= '</tr>';
		}
		$c .= '</table>';
		$c .= '</div></div> ';

		return $c;
	}

	function createFiscalPeriodParam (&$p)
	{
		if ($this->hasFlag ($p, 'enableAll'))
			$p['values']['0'] = array ('title' => 'Vše', 'fiscalYear' => 0, 'calendarYear' => 0, 'calendarMonth' => 0, 'dateBegin' => '', 'dateEnd' => '');

		$q[] = 'SELECT months.* FROM [e10doc_base_fiscalmonths] AS months';
		array_push($q, ' LEFT JOIN [e10doc_base_fiscalyears] AS years ON months.fiscalYear = years.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND years.docStateMain != %i', 4);
		if (!$this->hasFlag ($p, 'openclose'))
			array_push($q, ' AND months.fiscalType = %i', 0);
		array_push($q, ' ORDER BY [globalOrder] DESC');

		$periods = $this->app->db()->query($q)->fetchAll ();
		forEach ($periods as $r)
		{
			$p['values'][strval($r['ndx'])] = array ('title' => $r['calendarYear'].' / ' . $r['calendarMonth'], 'fiscalYear' => $r['fiscalYear'],
				'calendarYear' => $r['calendarYear'], 'calendarMonth' => $r['calendarMonth'],
				'dateBegin' => $r['start'], 'dateEnd' => $r['end']);

			if (!isset ($p['calendarYears'][$r['fiscalYear']]))
			{
				$fy = $this->app->cfgItem ('e10doc.acc.periods.'.$r['fiscalYear']);
				if ($fy === '')
				{
					// TODO: error_log ("___FY_!{$r['fiscalYear']}!__".json_encode($fy));
					continue;
				}
				$p['calendarYears'][$r['fiscalYear']] = [
						'title' => $fy['fullName'], 'value' => 'Y' . $r['fiscalYear'],
						'fiscalYear' => $r['fiscalYear'], 'dateBegin' => $r['start'], 'months' => []
				];
			}
			$p['calendarYears'][$r['fiscalYear']]['dateBegin'] = $r['start'];

			if (!isset($p['calendarYears'][$r['fiscalYear']]['dateEnd']))
				$p['calendarYears'][$r['fiscalYear']]['dateEnd'] = $r['end'];

			$p['calendarYears'][$r['fiscalYear']]['months'][] = array ('title' => $r['calendarMonth'], 'value' => $r['ndx'],
																																	 'type' => $r['fiscalType'], 'fiscalYear' => $r['fiscalYear'],
																																	 'dateBegin' => $r['start'], 'order' => $r['localOrder']);
		}

		// -- quarters & halfs
		forEach ($p['calendarYears'] as $calYear => $year)
		{
			$qmn = 3;
			$hmn = 6;
			$quarters = array ();
			forEach (array_reverse($year['months']) as $month)
			{
				if ($month['type'] !== 0)
					continue;
				$qn = intval ($qmn / 3);
				$hn = intval ($hmn / 6);
				$mid = $month['value'];

				if (!isset($p['calendarYears'][$calYear]['querters'][$qn]))
					$p['calendarYears'][$calYear]['querters'][$qn] = array ('title' => $qn.'Q', 'value' => strval($mid), 'dateBegin' => $month['dateBegin'], 'fiscalYear' => $month['fiscalYear']);
				else
					$p['calendarYears'][$calYear]['querters'][$qn]['value'] .= ','.$mid;

				if (!isset($p['calendarYears'][$calYear]['halfs'][$hn]))
					$p['calendarYears'][$calYear]['halfs'][$hn] = array ('title' => $hn.'|2', 'value' => strval($mid), 'dateBegin' => $month['dateBegin'], 'fiscalYear' => $month['fiscalYear']);
				else
					$p['calendarYears'][$calYear]['halfs'][$hn]['value'] .= ','.$mid;

				$qmn++;
				$hmn++;
			}
		}

		forEach ($p['calendarYears'] as $year)
		{
			if ($this->hasFlag ($p, 'years'))
				$p['values'][$year['value']] = array ('title' => $year['title'], 'dateBegin' => $year['dateBegin'], 'dateEnd' => $year['dateEnd'], 'fiscalYear' => $year['fiscalYear']);

			if ($this->hasFlag ($p, 'quarters'))
			{
				forEach ($year['querters'] as $quart)
					$p['values'][$quart['value']] = array ('title' => $year['title'].' / '.$quart['title'], 'dateBegin' => $quart['dateBegin'], 'fiscalYear' => $quart['fiscalYear']);
			}
			if ($this->hasFlag ($p, 'halfs'))
			{
				forEach ($year['halfs'] as $half)
					$p['values'][$half['value']] = array ('title' => $year['title'].' / '.$half['title'], 'dateBegin' => $half['dateBegin'], 'fiscalYear' => $half['fiscalYear']);
			}
		}

		if (isset($p['options']['defaultValue']))
			$p['defaultValue'] = $p['options']['defaultValue'];
		else
		if ($this->hasFlag ($p, 'enableAll'))
			$p['defaultValue'] = 0;
		else
			$p['defaultValue'] = E10Utils::todayFiscalMonth($this->app);
	}

	function createFiscalPeriodParamCode ($paramId)
	{
		$p = $this->params [$paramId];
		$activeValue = strval($p['value']);
		$activeTitle = $p['values'][$activeValue]['title'];

		$cntCols = 13;
		if ($this->hasFlag ($p, 'quarters'))
			$cntCols += 4;
		if ($this->hasFlag ($p, 'halfs'))
			$cntCols += 2;
		if ($this->hasFlag ($p, 'openclose'))
			$cntCols += 2;

		$inputClass = ($this->inputClass === '') ? '' : " class='$this->inputClass'";
		$c = '';
		$c .= "<div class='btn-group e10-param' data-paramid='$paramId'>";
		$c .= "<button type='button' class='btn btn-default dropdown-toggle e10-report-param' data-toggle='dropdown'>".
			'<b>'.utils::es ($p['title']).":</b> <span class='v'>".utils::es($activeTitle).'</span>'.
			" <span class='caret'></span>".
			'</button>';
		$c .= "<input name='$paramId' type='hidden'$inputClass value='$activeValue'>";
		$c .= "<div class='dropdown-menu' role='menu'>";

		$c .= "<table class='e10-param-calper'>";

		if ($this->hasFlag ($p, 'enableAll'))
		{
			$class = 'all';
			if ($activeValue === 0) $class .= ' active';
			$c .= "<tr class='all'><td colspan='$cntCols' data-value='0' data-title='Vše' class='$class'><a href='#'>Vše</a></td></tr>";
		}

		forEach ($p['calendarYears'] as $year)
		{
			$c .= "<tr>";
			if ($this->hasFlag ($p, 'years'))
			{
				$mid = $year['value'];
				$class = ($mid === $activeValue) ? " class='active y'": "class='y'";
				$t = utils::es($year['title']);
				$c .= "<td data-value='$mid' data-title='$t'$class><a href='#'>" . utils::es($year['title']) . '</a></td>';

				//$c .= "<td class='y'>".utils::es($year['title']).'</td>';
			}
			else
				$c .= "<td class='x y'>".utils::es($year['title']).'</td>';

			$firstMonth = 1;
			forEach (array_reverse($year['months']) as $month)
			{
				if ($firstMonth)
				{
					if ($month['order'] != 1)
					{
						$colSpan = 12 - $month['order'] + 1;
						$c .= "<td colspan='$colSpan'></td>";
					}
				}
				$mid = $month['value'];
				$class = (strval($mid) == $activeValue) ? " class='active m'": "class='m'";
				$t = utils::es($p['values'][$mid]['title']);
				$c .= "<td data-value='$mid' data-title='$t'$class><a href='#'>" . utils::es($month['title']) . '</a></td>';
				$firstMonth = 0;
			}

			if ($month['order'] != 12)
			{
				$colSpan = 12 - $month['order'];
				$c .= "<td colspan='$colSpan'></td>";
			}


			if ($this->hasFlag ($p, 'quarters'))
			{
				forEach ($year['querters'] as $quart)
				{
					$mid = strval($quart['value']);
					$class = ($mid === strval($activeValue)) ? " class='active q'": " class='q'";
					$t = utils::es ($year['title'].' / '.$quart['title']);
					$c .= "<td data-value='$mid' data-title='$t'$class><a href='#'>" . utils::es($quart['title']) . '</a></td>';
				}
			}
			if ($this->hasFlag ($p, 'halfs'))
			{
				forEach ($year['halfs'] as $half)
				{
					$mid = strval($half['value']);
					$class = ($mid === $activeValue) ? " class='active h'": "class='h'";
					$t = utils::es ($year['title'].' / '.$half['title']);
					$c .= "<td data-value='$mid' data-title='$t'$class><a href='#'>" . utils::es($half['title']) . '</a></td>';
				}
			}

			$c .= '</tr>';
		}
		$c .= '</table>';
		$c .= '</div></div> ';

		return $c;
	}

	function createFiscalYearParam (&$p)
	{
		if ($this->hasFlag ($p, 'enableAll'))
			$p['values'][0] = array ('title' => 'Vše', 'fiscalYear' => 0, 'calendarYear' => 0, 'dateBegin' => '', 'dateEnd' => '');

		$periods = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalyears] WHERE docState != 9800 ORDER BY [start] DESC")->fetchAll ();
		forEach ($periods as $r)
		{
			$p['values'][$r['ndx']] = array ('title' => $r['fullName'], 'calendarYear' => $r['start']->format('Y'),
				'dateBegin' => $r['start'], 'dateEnd' => $r['end']);
		}

		$today = utils::today('', $this->app);
		$period = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalyears] WHERE start <= %d AND end >= %d ORDER BY [start] DESC", $today, $today)->fetch ();
		if ($period)
			$p['defaultValue'] = $period ['ndx'];
	}

	function createCalendarYearParam (&$p)
	{
		$years = array();
		$periods = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalyears] ORDER BY [start] DESC")->fetchAll ();
		forEach ($periods as $r)
		{
			$year = intval ($r['start']->format('Y'));
			if (in_array($year, $years))
				continue;
			$years[] = $year;
			$p['values'][$year] = array ('title' => strval($year), 'calendarYear' => $year);
		}

		$today = utils::today('', $this->app);
		$p['defaultValue'] = intval($today->format('Y'));
	}
}
