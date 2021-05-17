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
		,actionDelete = 5
		,actionExpandClose = 6
		,actionExpandOpen = 7
		,actionHomePage = 8
		,actionLogout = 9
		,actionMoveDown = 10
		,actionMoveUp = 11
		,actionNotifications = 12
		,actionOpen = 13
		,actionPrint = 14
		,actionRegenerate = 15
		,actionSave = 16
		,detailAccounting = 17
		,detailAnalysis = 18
		,detailDetail = 19
		,detailHistory = 20
		,detailMovement = 21
		,detailNotes = 22
		,detailReport = 23
		,detailRows = 24
		,detailStock = 25
		,detailUsage = 26
		,docStateArchive = 27
		,docStateCancel = 28
		,docStateConcept = 29
		,docStateConfirmed = 30
		,docStateDelete = 31
		,docStateDone = 32
		,docStateEdit = 33
		,docStateHalfDone = 34
		,docStateNew = 35
		,docStateUnknown = 36
		,iconFile = 37
		,iconHistory = 38
		,iconOther = 39
		,iconOwner = 40
		,iconPreview = 41
		,iconReports = 42
		,iconSearch = 43
		,iconSettings = 44
		,iconStart = 45
		,iconToSolve = 46
		,iconUser = 47
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
