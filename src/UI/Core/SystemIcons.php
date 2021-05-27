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
		,actionSupport = 17
		,detailAccounting = 18
		,detailAnalysis = 19
		,detailBalance = 20
		,detailDetail = 21
		,detailHistory = 22
		,detailInfo = 23
		,detailMovement = 24
		,detailNotes = 25
		,detailOverview = 26
		,detailReport = 27
		,detailRows = 28
		,detailSettings = 29
		,detailStock = 30
		,detailUsage = 31
		,docStateArchive = 32
		,docStateCancel = 33
		,docStateConcept = 34
		,docStateConfirmed = 35
		,docStateDelete = 36
		,docStateDone = 37
		,docStateEdit = 38
		,docStateHalfDone = 39
		,docStateNew = 40
		,docStateUnknown = 41
		,filterActive = 42
		,filterAll = 43
		,filterArchive = 44
		,filterDone = 45
		,filterOverview = 46
		,filterTrash = 47
		,formAccounting = 48
		,formAttachments = 49
		,formFilter = 50
		,formHeader = 51
		,formHistory = 52
		,formNote = 53
		,formRows = 54
		,formSettings = 55
		,formSorting = 56
		,iconBalance = 57
		,iconFile = 58
		,iconFilePdf = 59
		,iconHistory = 60
		,iconLocked = 61
		,iconOther = 62
		,iconOwner = 63
		,iconPreview = 64
		,iconReports = 65
		,iconSearch = 66
		,iconSettings = 67
		,iconStart = 68
		,iconUser = 69
		,personCompany = 70
		,personHuman = 71
		,personRobot = 72
		,rightSubmenuAccess = 73
		,rightSubmenuCanteen = 74
		,rightSubmenuCompartments = 75
		,rightSubmenuDocuments = 76
		,rightSubmenuMeters = 77
		,rightSubmenuNews = 78
		,rightSubmenuReceivables = 79
		,rightSubmenuReservations = 80
		,rightSubmenuSupport = 81
		,rightSubmenuToDo = 82
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
