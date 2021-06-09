<?php

namespace mac\access\dc;

use e10\utils, e10\json;


/**
 * Class AccessTag
 * @package mac\access\dc
 */
class AccessTag extends \e10\DocumentCard
{
	var $tagTypeCfg = NULL;

	/** @var \mac\access\libs\TagInfo */
	var $tagInfo;

	public function createContentBody ()
	{
		$this->tagTypeCfg = $this->app()->cfgItem('mac.access.tagTypes.'.$this->recData['tagType'], NULL);

		// -- core info
		$info = [];
		if ($this->tagTypeCfg)
			$info[] = ['p1' => 'Typ', 't1' => ['text' => $this->tagTypeCfg['name'], 'icon' => $this->tagTypeCfg['icon']]];
		$info[] = ['p1' => 'Hodnota', 't1' => $this->recData['keyValue']];

		if ($this->tagInfo->tagInfo['currentAssignment'])
		{
			if (isset($this->tagInfo->tagInfo['currentAssignment']['personNdx']))
			{
				$info[] = ['p1' => 'Přiřazeno',
					't1' => [
						['text' => $this->tagInfo->tagInfo['currentAssignment']['personFullName'], 'class' => ''],
						['text' => '#'.$this->tagInfo->tagInfo['currentAssignment']['personId'], 'class' => 'pull-right id']
					]
				];
			}
			elseif (isset($this->tagInfo->tagInfo['currentAssignment']['placeNdx']))
			{
				$info[] = ['p1' => 'Přiřazeno',
					't1' => [
						['text' => $this->tagInfo->tagInfo['currentAssignment']['placeFullName'], 'class' => ''],
						['text' => '#'.$this->tagInfo->tagInfo['currentAssignment']['placeId'], 'class' => 'pull-right id']
					]
				];
			}
		}
		else
		{
			$tt = [];
			$tt[] = [
				'action' => 'new', 'data-table' => 'mac.access.tagsAssignments', 'icon' => 'system/actionAdd',
				'text' => 'Přiřadit',
				'type' => 'button', 'actionClass' => 'btn',
				'class' => '', 'btnClass' => 'btn-primary btn-xs',
				'data-addParams' => '__tag='.$this->recData['ndx'].'&__validFrom='.utils::today('Y-m-d'),
			];

			$info[] = ['p1' => 'Nepřiřazeno', 't1' => $tt];
		}

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);



		// -- assignment history
		if (count($this->tagInfo->tagInfo['assignmentHistory']))
		{
			$ah = ['#' => '#', 'validFrom' => 'Od', 'validTo' => 'Do', 'assigned' => 'Přiřazeno'];

			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'paneTitle' => ['text' => 'Historie klíče', 'class' => 'h2'],
				'header' => $ah, 'table' => $this->tagInfo->tagInfo['assignmentHistory'], 'params' => []
			]);

		}
	}

	public function createContent ()
	{
		$this->tagInfo = new \mac\access\libs\TagInfo($this->app());
		$this->tagInfo->init();
		$this->tagInfo->setTag($this->recData['ndx']);
		$this->tagInfo->load();

		$this->createContentBody ();
	}
}
