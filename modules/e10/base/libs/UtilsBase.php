<?php


namespace e10\base\libs;
use \e10\base\TableAttachments;


class UtilsBase
{
	static function classificationParams (\Shipard\Table\DbTable $table)
	{
		$clsfItems = $table->app()->cfgItem ('e10.base.clsf');

		$params = [];
		$groups = $table->app()->cfgItem ('e10.base.clsfGroups', []);
		forEach ($groups as $key => $group)
		{
			if (isset ($group ['tables']) && !in_array ($table->tableId(), $group ['tables']))
				continue;

			$p = ['id' => $key, 'name' => isset($group['label']) ? $group['label'] : $group['name'], 'items' => []];
			$grpItems = $table->app()->cfgItem ('e10.base.clsf.'.$key, []);
			foreach ($grpItems as $itmNdx => $itm)
			{
				$clsfItem = $clsfItems [$key][$itmNdx];

				$p['items'][$itmNdx] = ['id' => $itmNdx, 'title' => $itm ['name']];
				if (isset($clsfItem['css']))
					$p['items'][$itmNdx]['css'] = $clsfItem['css'];
			}
			if (count($p['items']))
				$params[] = $p;
		}
		return $params;
	}

	static function addClassificationParamsToPanel(\Shipard\Table\DbTable $table, ?\Shipard\Viewer\TableViewPanel $panel, &$qry)
	{
		$clsf = self::classificationParams ($table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $params];
		}
	}

	static function loadClassification ($app, $tableId, $pkeys, $class = 'label label-info', $withIcons = FALSE, $withIds = FALSE)
	{
		$c = array();
		if (is_int($pkeys))
			$recIds = strval($pkeys);
		else
		{
			if (!count($pkeys))
				return [];
			$recIds = implode(', ', $pkeys);
		}
		$clsfGroups = $app->cfgItem ('e10.base.clsfGroups');
		$clsfItems = $app->cfgItem ('e10.base.clsf');

		$query = $app->db->query ("SELECT * from [e10_base_clsf] where [tableid] = %s AND [recid] IN ($recIds)", $tableId);
		forEach ($query as $r)
		{
			$clsfItem = $clsfItems [$r['group']][$r['clsfItem']] ?? NULL;
			if (!$clsfItem)
				continue;
			$i = ['text' => $clsfItem['name'], 'class' => $class, 'clsfItem' => $r['clsfItem']];
			if ($withIcons)
				$i ['icon'] = $clsfGroups [$r['group']]['icon'];
			if ($withIds === TRUE)
				$i ['id'] = $clsfItem['id'];
			if ($withIds === 'text')
				$i ['text'] = $clsfItem['id'];

			if (isset($clsfItem['css']))
				$i['css'] = $clsfItem['css'];

			$c [$r['recid']][$r['group']][] = $i;
		}

		return $c;
	}

	static function loadAttachments ($app, $ids, $tableId = FALSE)
	{
		static $imgFileTypes = array ('pdf', 'jpg', 'jpeg', 'webp', 'png', 'gif', 'svg');

		$files = array ();
		if (count($ids) == 0)
			return $files;

		if ($tableId)
		{
			$sql = "SELECT * FROM [e10_attachments_files] where [recid] IN %in AND tableid = %s AND [deleted] = 0 ORDER BY defaultImage DESC, [order], name";
			$query = $app->db->query ($sql, $ids, $tableId);
			foreach ($query as $row)
			{
				$img = $row->toArray ();
				$img['folder'] = 'att/';
				$img['url'] = self::getAttachmentUrl ($app, $row);
				if (strtolower($row['filetype']) === 'pdf' || strtolower($row['filetype']) === 'svg')
					$img['original'] = 1;
				if (strtolower($row['filetype']) === 'svg')
					$img['svg'] = 1;
				if (in_array(strtolower($row['filetype']), $imgFileTypes))
					$files [$row['recid']]['images'][] = $img;
				else
					$files [$row['recid']]['files'][] = $img;
			}
			forEach ($files as &$f)
			{
				$f['count'] = 0;
				if (isset ($f['files']))
				{
					$f['hasDownload'] = 1;
					$f['count'] += count($f['files']);
				}
				if (isset ($f['images']))
				{
					if (count ($f['images']) > 2)
						$f['hasImagesSmall'] = 1;
					else
						$f['hasImagesBig'] = 1;
					$f['count'] += count($f['images']);
				}
			}
		}
		else
		{
			$sql = "SELECT * FROM [e10_attachments_files] where [ndx] IN %in AND [deleted] = 0 ORDER BY defaultImage DESC, [order], name";
			$query = $app->db->query ($sql, $ids);
			foreach ($query as $row)
			{
				$img = $row->toArray ();
				$img['folder'] = 'att/';
				$img['url'] = self::getAttachmentUrl ($app, $row);
				if (strtolower($row['filetype']) === 'pdf' || strtolower($row['filetype']) === 'svg')
					$img['original'] = 1;
				if (strtolower($row['filetype']) === 'svg')
					$img['svg'] = 1;
				if (strtolower($row['filetype']) === 'pdf')
					$img['original'] = 1;
				if (in_array(strtolower($row['filetype']), $imgFileTypes))
					$files ['images'][] = $img;
				else
					$files ['files'][] = $img;
			}
		}

		return $files;
	}

	static function getAttachmentDefaultImage ($app, $toTableId, $toRecId, $enableAnyImage = FALSE)
	{
		$img = array ();

		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE [tableid] = %s AND [recid] = %i AND [deleted] = 0', $toTableId, $toRecId);
		if (!$enableAnyImage)
			array_push($q, ' AND [defaultImage] = 1');
		array_push($q, ' ORDER BY defaultImage DESC, [order], name LIMIT 0, 1');

		$mainImage = $app->db->query ($q)->fetch ();
		if ($mainImage)
		{
			$img ['originalImage'] = self::getAttachmentUrl ($app, $mainImage);
			$img ['smallImage'] = self::getAttachmentUrl ($app, $mainImage, 384, 384);
			$img ['fileName'] = $mainImage['path'] . $mainImage['filename'];
		}

		return $img;
	}

	static function getAttachmentUrl ($app, $attachment, $thumbWidth = 0, $thumbHeight = 0, $fullUrl = FALSE, $params = FALSE)
	{
		$absUrl = '';
		if ($fullUrl || (isset($app->clientType [1]) && $app->clientType [1] === 'cordova'))
			$absUrl = $app->urlProtocol . $_SERVER['HTTP_HOST'];

		$url = '';

		if ($thumbWidth || $thumbHeight)
		{
			if ($attachment ['attplace'] === TableAttachments::apLocal)
			{
				$url = $absUrl.$app->dsRoot . '/imgs';
				if ($thumbWidth)
					$url .= '/-w' . intval($thumbWidth);
				if ($thumbHeight)
					$url .= '/-h' . intval($thumbHeight);
				if ($params !== FALSE)
					$url .= '/'.implode('/', $params);
				$url .= '/att/' . $attachment ['path'] . urlencode($attachment ['filename']);
			}
			else
			if ($attachment ['attplace'] === TableAttachments::apE10Remote)
			{
				$url = $attachment ['path'] . 'imgs';
				if ($thumbWidth)
					$url .= '/-w' . intval($thumbWidth);
				if ($thumbHeight)
					$url .= '/-h' . intval($thumbHeight);
				$url .= '/' . urlencode($attachment ['filename']);
			}
			else
			if ($attachment ['attplace'] === TableAttachments::apRemote)
				$url = $attachment ['path'];
		}
		else
		{
			if ($attachment ['attplace'] === TableAttachments::apLocal)
				$url = $absUrl.$app->dsRoot . '/att/' . $attachment ['path'] . $attachment ['filename'];
			else
			if ($attachment ['attplace'] === TableAttachments::apE10Remote)
				$url = $attachment ['path'] . '/' . $attachment ['filename'];
			if ($attachment ['attplace'] === TableAttachments::apRemote)
				$url = $attachment ['path'];
		}
		return $url;
	}

	static function getDocAttachments ($app, $toTableId, $toRecId)
	{
		$files = [];

		$q = [];
		array_push ($q, 'SELECT * FROM [e10_attachments_files]');
		array_push ($q, ' WHERE [tableid] = %s', $toTableId, '  AND [recid] = %i', $toRecId);
		array_push ($q, ' AND [deleted] = %i', 0);
		array_push ($q, ' ORDER BY defaultImage DESC, [order], name');


		$query = $app->db->query ($q);
		foreach ($query as $row)
		{
			$f = $row->toArray();
			$files [] = $f;
		}
		return $files;
	}

	static function linkedPersons ($app, $table, $toRecId, $elementClass = '')
	{
		if (is_string($table))
			$tableId = $table;
		else
			$tableId = $table->tableId ();

		$links = $app->cfgItem ('e10.base.doclinks', NULL);

		if (!$links)
			return array();
		if (!isset($links [$tableId]))
			return array();
		$allLinks = $links [$tableId];

		$lp = array ();

		if (is_array($toRecId))
		{
			if (count($toRecId) === 0)
				return $lp;
			$recs = implode (', ', $toRecId);
			$sql = "(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, links.dstRecId as dstRecId, persons.fullName as fullName, persons.company as company from e10_base_doclinks as links " .
							"LEFT JOIN e10_persons_persons as persons ON links.dstRecId = persons.ndx " .
							"where srcTableId = %s AND dstTableId = 'e10.persons.persons' AND links.srcRecId IN ($recs))" .
							" UNION ".
							"(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, 0, groups.name as fullName, 3 from e10_base_doclinks as links " .
							"LEFT JOIN e10_persons_groups as groups ON links.dstRecId = groups.ndx " .
							"where srcTableId = %s AND dstTableId = 'e10.persons.groups' AND links.srcRecId IN ($recs))";
		}
		else
		{
			$recId = intval($toRecId);
			$sql = "(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, links.dstRecId as dstRecId, persons.fullName as fullName, persons.company as company from e10_base_doclinks as links " .
							"LEFT JOIN e10_persons_persons as persons ON links.dstRecId = persons.ndx " .
							"where srcTableId = %s AND dstTableId = 'e10.persons.persons' AND links.srcRecId = $recId)" .
							" UNION ".
							"(SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, 0, groups.name as fullName, 3 from e10_base_doclinks as links " .
							"LEFT JOIN e10_persons_groups as groups ON links.dstRecId = groups.ndx " .
							"where srcTableId = %s AND dstTableId = 'e10.persons.groups' AND links.srcRecId = $recId)";
		}

		$query = $app->db->query ($sql, $tableId, $tableId);

		foreach ($query as $r)
		{
			$icon = 'icon-sign-blank';
			if (isset ($allLinks [$r['linkId']]['icon']))
				$icon = $allLinks [$r['linkId']]['icon'];
			if (isset ($lp [$r['srcRecId']][$r['linkId']]))
				$lp [$r['srcRecId']][$r['linkId']][0]['text'] .= ', '.$r ['fullName'];
			else
				$lp [$r['srcRecId']][$r['linkId']][0] = ['icon' => $icon, 'text' => $r ['fullName'], 'class' => $elementClass];
			if ($r['dstRecId'])
				$lp [$r['srcRecId']][$r['linkId']][0]['pndx'][] = $r['dstRecId'];
		}

		return $lp;
	}

	static function linkedPersons2 ($app, $table, $toRecId, $elementClass = '')
	{
		if (is_string($table))
			$tableId = $table;
		else
			$tableId = $table->tableId ();

		$links = $app->cfgItem ('e10.base.doclinks', NULL);
		$tablePersons = $app->table ('e10.persons.persons');

		if (!$links)
			return array();
		if (!isset($links [$tableId]))
			return array();
		$allLinks = $links [$tableId];

		$lp = [];

		if (is_array($toRecId))
		{
			if (count($toRecId) === 0)
				return $lp;
			$recs = implode (', ', $toRecId);
			$sql = "(SELECT links.ndx, links.linkId as linkId, links.dstTableId, links.srcRecId as srcRecId, links.dstRecId as dstRecId, persons.fullName as fullName, persons.company as company from e10_base_doclinks as links " .
				"LEFT JOIN e10_persons_persons as persons ON links.dstRecId = persons.ndx " .
				"where srcTableId = %s AND dstTableId = 'e10.persons.persons' AND links.srcRecId IN ($recs))" .
				" UNION ".
				"(SELECT links.ndx, links.linkId as linkId, links.dstTableId, links.srcRecId as srcRecId, 0, groups.name as fullName, 3 from e10_base_doclinks as links " .
				"LEFT JOIN e10_persons_groups as groups ON links.dstRecId = groups.ndx " .
				"where srcTableId = %s AND dstTableId = 'e10.persons.groups' AND links.srcRecId IN ($recs))";
		}
		else
		{
			$recId = intval($toRecId);
			$sql = "(SELECT links.ndx, links.linkId as linkId, links.dstTableId, links.srcRecId as srcRecId, links.dstRecId as dstRecId, persons.fullName as fullName, persons.company as company from e10_base_doclinks as links " .
				"LEFT JOIN e10_persons_persons as persons ON links.dstRecId = persons.ndx " .
				"where srcTableId = %s AND dstTableId = 'e10.persons.persons' AND links.srcRecId = $recId)" .
				" UNION ".
				"(SELECT links.ndx, links.linkId as linkId, links.dstTableId, links.srcRecId as srcRecId, 0, groups.name as fullName, 3 from e10_base_doclinks as links " .
				"LEFT JOIN e10_persons_groups as groups ON links.dstRecId = groups.ndx " .
				"where srcTableId = %s AND dstTableId = 'e10.persons.groups' AND links.srcRecId = $recId)";
		}

		$query = $app->db->query ($sql, $tableId, $tableId);

		foreach ($query as $r)
		{
			$linkId = $r['linkId'];
			if (!isset($lp [$r['srcRecId']][$linkId]))
			{
				$lp [$r['srcRecId']][$linkId] = ['icon' => $allLinks[$linkId]['icon'], 'name' => $allLinks[$linkId]['name'], 'labels' => []];
			}

			$icon = 'system/iconCheck';
			if ($r['dstTableId'] === 'e10.persons.persons')
				$icon = $tablePersons->tableIcon ($r);
			elseif ($r['dstTableId'] === 'e10.persons.groups')
				$icon = 'icon-users';

			$personLabel = [
				'text' => $r ['fullName'],
				'icon' => $icon,
				'class' => $elementClass,
				'ndx' => $r['dstRecId'],
			];

			$lp [$r['srcRecId']][$linkId]['labels'][] = $personLabel;
		}

		return $lp;
	}

	static function linkedSendReports ($app, $table, $toRecId, $elementClass = '')
	{
		$list = [];
		if (is_array($toRecId) && !count($toRecId))
			return $list;

		if (is_string($table))
			$tableId = $table;
		else
			$tableId = $table->tableId ();

		$q = [];
		array_push($q, 'SELECT links.ndx, links.linkId as linkId, links.dstTableId, links.srcRecId, links.dstRecId,');
		array_push($q, ' [reports].fullName AS fullName');
		array_push($q, ' FROM [e10_base_doclinks] AS [links] ');
		array_push($q, ' LEFT JOIN [e10_reports_reports] AS [reports] ON [links].[dstRecId] = [reports].[ndx]');
		array_push($q, ' WHERE [links].[srcTableId] = %s', $tableId);
		array_push($q, ' AND [dstTableId] = %s', 'e10.reports.reports');

		if (is_array($toRecId))
			array_push($q, ' AND links.srcRecId IN %in', $toRecId);
		else
			array_push($q, ' AND links.srcRecId = %i', $toRecId);

		$rows = $app->db->query ($q);
		foreach ($rows as $r)
		{
			$label = ['text' => $r['fullName'], 'class' => 'label label-default', 'srNdx' => $r['dstRecId']];
			if (is_array($toRecId))
			{
				$list[$r['srcRecId']][] = $label;
			}
			else
			{
				$list[$r['dstRecId']] = $label;
			}
		}

		return $list;
	}

	static function sendEmail ($app, $subject, $message, $fromAdress, $toAdress, $fromName = '', $toName = '', $html = FALSE)
	{
		if ($app->cfgItem ('develMode', 0) !== 0)
			return;

		if ($fromName == '')
			$fromName = $fromAdress;
		if ($toName == '')
			$toName = $toAdress;
		$subjectEncoded = "=?utf-8?B?".base64_encode ($subject)."?=";
		$header = "MIME-Version: 1.0\n";
		if ($html)
			$header .= "Content-Type: text/html; charset=utf-8\n";
		else
			$header .= "Content-Type: text/plain; charset=utf-8\n";
		$header .= "From: =?UTF-8?B?".base64_encode($fromName)."?=<".$fromAdress.">\n";
		//$header .= "To: =?UTF-8?B?".base64_encode($toName)."?=<".$toAdress.">\n";
		return mail ($toAdress, $subjectEncoded, $message, $header);
	}

	static function getPropertiesTable ($app, $toTableId, $toRecId)
	{
		$multiple = FALSE;
		$texy = new \lib\core\texts\Texy ($app);

		if (is_array($toRecId))
		{
			if (!count($toRecId))
				return [];
			$multiple = TRUE;
			$recs = implode (', ', $toRecId);
			$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] IN ($recs) ORDER BY ndx";
		}
		else
		{
			$recId = intval($toRecId);
			$sql = "SELECT * FROM [e10_base_properties] where [tableid] = %s AND [recid] = $recId ORDER BY ndx";
		}

		$allProperties = $app->cfgItem ('e10.base.properties', array());
		$properties = array ();

		// --load from table
		$query = $app->db->query ($sql, $toTableId);
		foreach ($query as $row)
		{
			$loaded = false;
			$p = $allProperties [$row['property']];
			if (isset ($p ['type']))
			{
				if ($p ['type'] == 'memo')
				{
					if ($row ['valueMemo'] === NULL)
						continue;
					$texy->setOwner ($row);
					$txt = $texy->process ($row ['valueMemo']);
					$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $txt, 'type' => 'memo', 'name' => $p ['name']);
					$loaded = true;
				}
				else
				if ($p ['type'] == 'date')
				{
					$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $row ['valueDate'], 'type' => 'memo', 'name' => $p ['name']);
					$loaded = true;
				}
				else
				if ($p ['type'] == 'text')
				{
					$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $row ['valueString'], 'type' => 'text', 'name' => $p ['name']);
					$loaded = true;
				}
				else
				if ($p ['type'] == 'enum')
				{
					$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $p ['enum'][$row ['valueString']]['fullName'],
							'type' => 'enum', 'name' => $p ['name']);
					$loaded = true;
				}

				if ($loaded && $row ['note'] !== '')
					$oneProp ['note'] = $row ['note'];
			}
			if (!$loaded)
				$oneProp = array ('ndx' => $row ['ndx'], 'property' => $row ['property'], 'group' => $row ['group'], 'value' => $p [$row ['valueString']], 'type' => 'string', 'name' => $p ['name']);

			if ($multiple)
				$properties [$row ['recid']][$row['group']][$row['property']][] = $oneProp;
			else
				$properties [$row['group']][$row['property']][] = $oneProp;
		}

		return $properties;
	}
}
