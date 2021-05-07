<?php

namespace e10pro\kb\libs;
use \e10\Utility, \e10\utils, \e10\world;


/**
 * Class AnnotationsList
 * @package e10pro\kb\libs
 */
class AnnotationsList extends Utility
{
	var $recs = [];
	var $data = [];

	public function addRecord($docTableNdx, $docRecNdx)
	{
		$this->recs[] = ['t' => $docTableNdx, 'r' => $docRecNdx];
	}

	function load()
	{
		if (!count($this->recs))
			return;

		$q = [];
		array_push($q,'SELECT annots.*, kinds.shortName AS kindFullName');
		array_push($q,' FROM [e10pro_kb_annots] AS [annots]');
		array_push($q,' LEFT JOIN [e10pro_kb_annotsKinds] AS [kinds] ON annots.annotKind = kinds.ndx');
		array_push($q,' WHERE 1');

		array_push($q,' AND (');

		$cnt = 0;
		foreach ($this->recs as $rec)
		{
			if ($cnt)
				array_push($q, ' OR ');
			array_push($q,' (annots.docTableNdx = %i', $rec['t'], ' AND annots.docRecNdx = %i', $rec['r'], ')');
			$cnt++;
		}
		array_push($q,')');

		array_push($q, ' ORDER BY annots.[order], annots.title');

		$rows = $this->db()->query($q);
		//error_log("!!!".\dibi::$sql);
		foreach ($rows as $r)
		{
			$item = ['title' => $r['title'], 'url' => $r['url']];

			if ($item['title'] === '' && $r['kindFullName'] && $r['kindFullName'] !== '')
				$item['title'] = $r['kindFullName'];

			if ($r['perex'] != '')
				$item['perex'] = $r['perex'];

			$item['linkCountry'] = world::country($this->app(), $r['linkCountry']);
			$item['linkLanguage'] = world::language($this->app(), $r['linkLanguage']);

			if ($item['linkLanguage'])
			{
				$countriesFlags = [102 => 'cz', 124 => 'us', 151 => 'de'];
				if (isset($countriesFlags[$r['linkLanguage']]))
				{
					$wcNdx = world::countryNdx($this->app(), $countriesFlags[$r['linkLanguage']]);
					if ($wcNdx)
					{
						$wc = world::country($this->app(), $wcNdx);
						if ($wc)
							$item['linkLanguageCountry'] = $wc;
					}
				}
			}

			$this->data[] = $item;
		}
	}

	function code()
	{
		$c = '';

		if (!count($this->data))
			return $c;

		$c .= "<ol class='e10-kb-annotationsList'>";
		foreach ($this->data as $a)
		{
			$c .= "<li>";
			$c .= "<a href='{$a['url']}' target='_blank' rel='noopener'>".utils::es($a['title']).'</a>';
			if (isset($a['perex']))
			{
				$c .= "<div>";
				$c .= utils::es($a['perex']);
				$c .= "</div>";
			}
			$c .= "</li>";
		}

		$c .= "</ol>";

		return $c;
	}

	function content()
	{
		$content = [];
	}
}
