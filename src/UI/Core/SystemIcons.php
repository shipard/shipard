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
		,detailBalance = 19
		,detailDetail = 20
		,detailHistory = 21
		,detailInfo = 22
		,detailMovement = 23
		,detailNotes = 24
		,detailOverview = 25
		,detailReport = 26
		,detailRows = 27
		,detailSettings = 28
		,detailStock = 29
		,detailUsage = 30
		,docStateArchive = 31
		,docStateCancel = 32
		,docStateConcept = 33
		,docStateConfirmed = 34
		,docStateDelete = 35
		,docStateDone = 36
		,docStateEdit = 37
		,docStateHalfDone = 38
		,docStateNew = 39
		,docStateUnknown = 40
		,formAccounting = 41
		,formAttachments = 42
		,formFilter = 43
		,formHeader = 44
		,formNote = 45
		,formRows = 46
		,formSettings = 47
		,formSorting = 48
		,iconBalance = 49
		,iconFile = 50
		,iconHistory = 51
		,iconOther = 52
		,iconOwner = 53
		,iconPreview = 54
		,iconReports = 55
		,iconSearch = 56
		,iconSettings = 57
		,iconStart = 58
		,iconToSolve = 59
		,iconUser = 60
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
