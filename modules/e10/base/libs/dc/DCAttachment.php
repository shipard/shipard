<?php

namespace e10\base\libs\dc;
use \Shipard\Utils\Utils;


/**
 * class DCAttachment
 */
class DCAttachment extends \e10\DocumentCard
{
	public function createContentRecap ()
	{
		$ffn = $this->recData['path'].$this->recData['filename'];

		$info = [];
		$info[] = ['p1' => 'Název', 't1' => $this->recData['name']];
		$info[] = ['p1' => 'Soubor', 't1' => $this->recData['filename']];
		$info[] = ['p1' => 'Cesta', 't1' => $ffn];
		$info[] = ['p1' => 'Velikost', 't1' => Utils::memf($this->recData ['fileSize'])];

		/** @var \Shipard\Table\DbTable */
		$table = $this->table->app()->table ($this->recData['tableid']);
		if ($table)
		{
			$info[] = ['p1' => 'Tabulka', 't1' => $table->tableName()];
			$srcDocRecData = $table->loadItem($this->recData['recid']);
			if ($srcDocRecData)
			{
				$ri = $table->getRecordInfo($srcDocRecData);
				$docTitle = $ri['title'] ?? '--- bez názvu ---';
				if ($docTitle === '')
					$docTitle = '--- bez názvu ---';
				$docLabel = [
					[
						'text' => $docTitle,
						'docAction' => 'edit', 'pk' => $this->recData['recid'], 'table' => $this->recData['tableid'], 'class' => 'block'
					]
				];
				if (isset($ri['docID']))
					$docLabel[] = ['text' => $ri['docID'], 'class' => 'label label-info'];
				if (isset($ri['docTypeName']))
					$docLabel[] = ['text' => $ri['docTypeName'], 'class' => 'label label-default'];
				if (isset($ri['icon']))
					$docLabel[0]['icon'] = $ri['icon'];

				$docStates = $table->documentStates ($srcDocRecData);
				if ($docStates)
				{
					$docStateName = $table->getDocumentStateInfo ($docStates, $srcDocRecData, 'name');
					$docStateIcon = $table->getDocumentStateInfo ($docStates, $srcDocRecData, 'styleIcon');
					$docStateClass = $table->getDocumentStateInfo ($docStates, $srcDocRecData, 'styleClass');

					$docLabel[] = ['text' => $docStateName, 'class' => 'label label-default', 'icon' => $docStateIcon ];
				}

				$docInfo = ['p1' => 'Dokument', 't1' => $docLabel];

				if ($docStates)
					$docInfo['_options']['cellClasses']['t1'] = 'e10-ds '.$docStateClass;

				$info[] = $docInfo;
			}
		}

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}

	function createContentPreview()
	{
		$this->addContentAttachments ($this->recData ['recid'], $this->recData['tableid'], 'Náhled', 'Stáhnout', $this->recData['ndx']);
	}

	public function createContent ()
	{
		$this->createContentRecap();
		$this->createContentPreview();
	}
}
