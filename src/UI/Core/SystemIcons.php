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
		,formAttachments = 41
		,formFilter = 42
		,formHeader = 43
		,formNote = 44
		,formRows = 45
		,formSettings = 46
		,formSorting = 47
		,iconBalance = 48
		,iconFile = 49
		,iconHistory = 50
		,iconOther = 51
		,iconOwner = 52
		,iconPreview = 53
		,iconReports = 54
		,iconSearch = 55
		,iconSettings = 56
		,iconStart = 57
		,iconToSolve = 58
		,iconUser = 59
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
