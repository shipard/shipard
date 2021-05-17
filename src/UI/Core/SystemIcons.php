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
		,actionRegenerate = 10
		,actionSave = 11
		,detailAccounting = 12
		,detailAnalysis = 13
		,detailDetail = 14
		,detailHistory = 15
		,detailMovement = 16
		,detailNotes = 17
		,detailReport = 18
		,detailRows = 19
		,detailStock = 20
		,detailUsage = 21
		,iconFile = 22
		,iconHistory = 23
		,iconOther = 24
		,iconOwner = 25
		,iconReports = 26
		,iconSettings = 27
		,iconStart = 28
		,iconToSolve = 29
		,iconUser = 30
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
