<?php

namespace e10doc\taxes;

use \e10\utils, \e10\Utility, \e10\TableView;


/**
 * Class TaxReportDocumentCard
 * @package e10doc\taxes
 */
class TaxReportDocumentCard extends \e10\DocumentCard
{
	var $reportTypeDef;
	var $tableFilings;

	protected function init()
	{
		$this->tableFilings = $this->app()->table('e10doc.taxes.filings');
		$this->reportTypeDef = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->recData['reportType'], NULL);
	}

	protected function createAccInfo($filingRecData) : ?array
	{
		return NULL;
	}

	public function createContentFilings ()
	{
		$filings = [];
		$propertiesEngine = $this->app->createObject($this->reportTypeDef['propertiesEngine']);

		// -- title
		$tile = ['info' => [], 'class' => 'padd5 subtitle e10-bg-t9'];
		$title = [];
		$title[] = ['class' => 'h1', 'text' => 'Přehled podání', 'icon' => 'system/iconPaperPlane'];
		$title[] = [
				'class' => 'pull-right btn-xs', 'text' => 'Nové podání', 'type' => 'document', 'icon' => 'system/actionAdd',
				'action' => 'new', 'data-table' => 'e10doc.taxes.filings', 'data-addparams' => '__report='.$this->recData['ndx'].'&__reportType='.$this->recData['reportType']
		];

		$tile['info'][] = ['class' => 'info', 'value' => $title];
		$filings[] = $tile;

		$lastActiveFillingNdx = 0;
		$lastActiveFillingRecData = NULL;

		// -- filings
		$q[] = 'SELECT * FROM [e10doc_taxes_filings]';
		array_push($q, ' WHERE [report] = %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY dateIssue DESC, ndx DESC');

		$filingPks = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$docState = $this->tableFilings->getDocumentState ($r);
			$docStateClass = $this->tableFilings->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
			$propertiesEngine->load ($this->recData['ndx'], $r['ndx']);

			if (!$lastActiveFillingNdx)
			{
				$lastActiveFillingNdx = $r['ndx'];
				$lastActiveFillingRecData = $r->toArray();
			}
			$tile = ['info' => [], 'class' => 'row e10-ds '.$docStateClass];
			$title = [];
			$title[] = ['class' => 'h1 pull-left', 'text' => $propertiesEngine->name(), 'icon' => 'icon-file-o'];
			$title[] = [
					'class' => 'pull-right', 'actionClass' => 'btn btn-primary btn-xs', 'text' => 'Otevřít', 'icon' => 'system/actionOpen', 'type' => 'button',
					'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10doc.taxes.filings'
			];
			$tile['info'][] = ['class' => 'info', 'value' => $title];

			$info = [];
			$propertiesEngine->details ($info);
			$tile['info'][] = ['class' => 'clear', 'value' => $info];

			$filings[$r['ndx']] = $tile;
			$filingPks[] = $r['ndx'];
		}

		// -- files
		if (count($filings) > 1)
		{
			// filing files
			$q = [];
			$q[] = 'SELECT files.*, atts.path, atts.filename, atts.attplace ';
			array_push($q, ' FROM [e10doc_taxes_filingFiles] AS files');
			array_push($q, ' LEFT JOIN [e10_attachments_files] AS atts ON files.attachment = atts.ndx');
			array_push($q, ' WHERE [filing] IN %in', $filingPks);
			array_push($q, ' ORDER BY ndx');

			$filesPdf = [];
			$filesOther = [];
			$rows = $this->db()->query ($q);
			foreach ($rows as $r)
			{
				$fullUrl = \e10\base\getAttachmentUrl($this->app(), $r);
				if (substr($r['filename'], -3) === 'pdf')
				{
					$thumbUrl = \e10\base\getAttachmentUrl($this->app(), $r, 0, 444);
					$newFile = ['text' => '', 'title' => $r['title'], 'img' => $thumbUrl, 'class' => 'e10-att-thumb', 'url' => $fullUrl];
					$filesPdf[$r['filing']][] = $newFile;
				}
				else
				{
					$c = "<a href='$fullUrl' class='btn btn-default clear' download='{$r['filename']}'><i class='fa fa-download'></i>&nbsp;".utils::es($r['title']).'</a>';
					$newFile = ['code' => $c];
					$filesOther[$r['filing']][] = $newFile;
				}
			}

			foreach ($filesPdf as $filingNdx => $filingFiles)
				$filings[$filingNdx]['info'][] = ['value' => $filingFiles, 'class' => 'padd5'];
			foreach ($filesOther as $filingNdx => $filingFiles)
				$filings[$filingNdx]['info'][] = ['value' => $filingFiles, 'class' => 'padd5'];

			// attachments files
			$attachments = [];
			$sql = "SELECT * FROM [e10_attachments_files] WHERE [tableid] = %s AND [recid]  IN %in";
			$sql .= " AND [ndx] NOT IN (SELECT attachment FROM [e10doc_taxes_filingFiles] WHERE [filing] IN %in)";
			$sql .= " AND [deleted] = 0 ORDER BY defaultImage DESC, [order], [name]";
			$query = $this->app()->db->query ($sql, 'e10doc.taxes.filings', $filingPks, $filingPks);
			foreach ($query as $row)
				$attachments [] = $row->toArray();

			$filings[$filingNdx]['info'][] = ['class' => 'clear', 'value' => []];
			if (count($attachments))
			{
				$filings[$filingNdx]['info'][] = ['class' => 'info', 'value' => ['class' => 'h3 pull-left', 'text' => 'Přílohy', 'icon' => 'system/iconPaperclip']];
				$filings[$filingNdx]['info'][] = ['class' => 'clear', 'value' => []];
			}

			$filesImgTypes = [];
			$filesOther = [];
			forEach ($attachments as $att)
			{
				$fullUrl = \e10\base\getAttachmentUrl($this->app(), $att);
				$thumbUrl = \e10\base\getAttachmentUrl($this->app(), $att, 0, 444);
				$label = \E10\es ($att ['filename']);
				if (in_array (strtolower(substr($fullUrl, -3)), ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg']))
				{
					$label = \E10\es ($att ['filename']);
					$newFile = ['text' => '', 'title' => $label, 'img' => $thumbUrl, 'class' => 'e10-att-thumb', 'url' => $fullUrl];
					$filesImgTypes[$att['recid']][] = $newFile;
				}
				else
				{
					$c = "<a href='$fullUrl' class='btn btn-default clear' download='{$label}'><i class='fa fa-download'></i>&nbsp;".utils::es($label).'</a>';
					$newFile = ['code' => $c];
					$filesOther[$att['recid']][] = $newFile;
				}
			}

			foreach ($filesImgTypes as $filingNdx => $filingFiles)
				$filings[$filingNdx]['info'][] = ['value' => $filingFiles, 'class' => 'padd5'];
			foreach ($filesOther as $filingNdx => $filingFiles)
				$filings[$filingNdx]['info'][] = ['value' => $filingFiles, 'class' => 'padd5'];
		}

		if (count($filings) === 1)
		{ // no rows
			$info[] = ['text' => 'Zatím nebylo vytvořeno žádné podání.'];
			$filings[0]['info'][] = ['value' => $info];
		}

		$this->addContent('body', $this->createAccInfo($lastActiveFillingRecData));

		$this->addContent('body', ['type' => 'tiles', 'pane' => 'e10-pane', 'tiles' => $filings, 'class' => 'panes']);
	}

	public function createContentParts ()
	{
		$vid = 'mainListView' . mt_rand () . '_' . TableView::$vidCounter++;
		$this->addContent ('body', [
			'type' => 'viewer', 'table' => 'e10doc.taxes.reportsParts', 'viewer' => 'e10doc.taxes.TaxReportViewerParts',
			'params' => ['forceInitViewer' => 1, 'reportNdx' => $this->recData ['ndx'], 'vid' => $vid]
			]
		);
	}
}
