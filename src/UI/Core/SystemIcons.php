<?php

namespace Shipard\UI\Core;
class SystemIcons
{
	var $iconsId;
	private ?array $data = NULL;

	const
		 actionAdd = 0
		,actionCalendar = 1
		,actionClose = 2
		,actionCopy = 3
		,actionDatabaseName = 4
		,actionHomePage = 5
		,actionLogout = 6
		,actionNotifications = 7
		,actionOpen = 8
		,actionPrint = 9
		,actionSave = 10
		,actionUser = 11
	;


		public function systemIcon(int $i)
		{
			if (!$this->data)
			{
				$this->data = unserialize(file_get_contents(__SHPD_ROOT_DIR__ . 'ui/icons/'.$this->iconsId.'/system-icons-map.data'));
			}
	
			return $this->data[$i];
		}
		}
