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
		,filterActive = 41
		,filterAll = 42
		,filterArchive = 43
		,filterDone = 44
		,filterOverview = 45
		,filterTrash = 46
		,formAccounting = 47
		,formAttachments = 48
		,formFilter = 49
		,formHeader = 50
		,formNote = 51
		,formRows = 52
		,formSettings = 53
		,formSorting = 54
		,iconBalance = 55
		,iconFile = 56
		,iconFilePdf = 57
		,iconHistory = 58
		,iconLocked = 59
		,iconOther = 60
		,iconOwner = 61
		,iconPreview = 62
		,iconReports = 63
		,iconSearch = 64
		,iconSettings = 65
		,iconStart = 66
		,iconToSolve = 67
		,iconUser = 68
		,personCompany = 69
		,personHuman = 70
		,personRobot = 71
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
