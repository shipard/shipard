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

	static function addClassificationParamsToPanel(\Shipard\Table\DbTable $table, \Shipard\Viewer\TableViewPanel $panel, &$qry)
	{
		$clsf = self::classificationParams ($table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
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
			$clsfItem = $clsfItems [$r['group']][$r['clsfItem']];
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
		static $imgFileTypes = array ('pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg');
	
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
				$img['url'] = getAttachmentUrl ($app, $row);
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
}
