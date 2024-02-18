<?php

namespace lib\core\attachments\viewers;

use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;
use E10\uiutils;
use function E10\Base\getAttachmentUrl;


/**
 * Class DocAttachments
 * @package lib\core\attachments\viewers
 */
class DocAttachments extends \E10\TableViewWidget
{
	var $classification;
	var $metadata;

	public function init ()
	{
		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Platné'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function zeroRowCode ()
	{
		$c = '';
		$c .= "<div style='border: 2px solid navy;' class='e10-tvw-item' id='{$this->vid}Footerdddd'>";

		$recId = intval ($this->queryParam ('recid'));
		if ($recId == 0)
		{
			$c .= "<p class='e10-error'>Před přidáním přílohy musí být dokument uložen.</p>";
		}
		else
		{
			$c .= $this->app()->ui()->addAttachmentsInputCode($this->queryParam('tableid'), $this->queryParam('recid'), $this->vid);
		}
		$c .= '</div>';

		return $c;
	}

	public function createToolbar ()
	{
		return array ();
	}

	public function rowHtml ($listItem)
	{
		$attInfo = $this->table->attInfo($listItem);
		$ddfCfg = NULL;
		if ($listItem['ddfId'])
			$ddfCfg = $this->app()->cfgItem('e10.ddf.formats.'.$listItem['ddfId'], NULL);

		$c = '';

		$class = '';
		if ($listItem ['deleted'])
			$class = ' deleted';

		$c .= "<div class='e10-tvw-item{$class}' data-pk='{$listItem['ndx']}'>";
		$c .= "<table style='clear: both; vertical-align: top !important; width: 100%;'><tr>";

		$url = getAttachmentUrl ($this->table->app(), $listItem);
		$thumbUrl = getAttachmentUrl ($this->table->app(), $listItem, 192, 192);
		$c .= "<td style='text-align: center; width: 200px; '><a href='$url' target='new'><img src='$thumbUrl'/></a></td>";

		$c .= "<td class='e10-tvw-item-attachment' style='vertical-align: top;'>";
		$c .= "<div class='h2 padd5'>";
		$c .= strval ($this->lineRowNumber + $this->rowsFirst) . '. ';
		$c .=	utils::es ($listItem ['name']);
		$c .= "<span class='pull-right id'>#{$listItem ['ndx']}</span>";
		$c .=	'</div>';


		$props = [];

		if (isset($attInfo['labels']) && count($attInfo['labels']) && $listItem['fileKind'] !== 0)
			$props = array_merge($props, $attInfo['labels']);

		$refreshDDMBtnCode = '';
		$refreshDDMBtnCode .= "<span class='btn btn-xs btn-primary df2-action-trigger pull-right' data-action='saveform' data-noclose='1'";
		$refreshDDMBtnCode .= " data-save-refresh-ddm-attndx='{$listItem['ndx']}'";
		$refreshDDMBtnCode .= " data-fid='AUTO' data-form='AUTO' data-docstate='99001'>";
		$refreshDDMBtnCode .= $this->app()->ui()->icons()->icon('user/cogs');
		$refreshDDMBtnCode .= ' '.Utils::es('Znovu načíst');
		$refreshDDMBtnCode .= "</span>";
		$props[] = ['code' => $refreshDDMBtnCode];

		if ($ddfCfg)
		{
			$props[] = [
				'text' => $ddfCfg['sn'], 'icon' => $ddfCfg['icon'], 'class' => '', 'type' => 'span',
				'docAction' => 'edit', 'table' => 'e10.base.docDataFiles', 'pk' => $listItem['ddfNdx'], 'actionClass' => 'btn btn-xs btn-success pull-right',
			];
		}

		if (isset($this->metadata[$listItem['ndx']]))
			$props = array_merge($props, $this->metadata[$listItem['ndx']]);


		if (count($props))
		{
			$c .= "<div class='padd5'>";
			$c .= $this->app()->ui()->composeTextLine($props);
			$c .= '</div>';
		}


		$c .= "<div class='padd5'><input type='text' style='width: 100%;' value='$url' readonly='readonly'/></div>";

		if (isset ($this->classification [$listItem ['ndx']]))
		{
			$tags = [];
			forEach ($this->classification [$listItem ['ndx']] as $clsfGroup)
				$tags = array_merge ($tags, $clsfGroup);
			$c .= "<div class='padd5'>".$this->app()->ui()->composeTextLine($tags).'</div>';
		}

		$c .= $this->createItemMenuCode ($listItem, 'e10-tvw-item-menu');
		$c .= '</td>';

		$c .= '</tr></table>';
		$c .= '</div>';
		return $c;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$recId = intval ($this->queryParam ('recid'));
		if ($recId == 0)
		{
			$this->runQuery (NULL);
			return;
		}

		$q[] = 'SELECT * FROM [e10_attachments_files] ';

		array_push ($q, ' WHERE [tableid] = %s', $this->queryParam ('tableid'), ' AND [recid] = %i ', $recId);

		if ($fts !== '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [filename] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		if ($mainQuery === '' || $mainQuery === 'active')
			array_push ($q, ' AND deleted = 0');
		if ($mainQuery === 'trash')
			array_push ($q, ' AND deleted = 1');

		array_push ($q, ' ORDER BY defaultImage DESC, [order], name');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification($this->table->app(), $this->table->tableId(), $this->pks);
		$this->loadMetaData();
	}

	function loadMetaData()
	{
		$mdConfig = $this->app()->cfgItem ('e10.att.metaDataTypes');

		$this->metadata = [];
		$rows = $this->db()->query ('SELECT * FROM [e10_attachments_metaData] WHERE [attachment] IN %in', $this->pks);
		foreach ($rows as $r)
		{
			$mdType = isset($mdConfig[$r['metaDataType']]) ? $mdConfig[$r['metaDataType']] : NULL;
			if (!$mdType)
				continue;

			$label = [
				'text' => $mdType['tabLabel'], 'icon' => $mdType['icon'], 'class' => 'btn btn-xs btn-info pull-right',
				'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10.base.attachmentsMetaData'
			];

			$this->metadata[$r['attachment']][] = $label;
		}
	}
}

