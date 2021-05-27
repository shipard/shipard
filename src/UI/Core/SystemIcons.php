<?php

namespace Shipard\UI\Core;
class SystemIcons
{
	var $iconsId;
	private ?array $data = NULL;

	const
		 actionAdd = 0
		,actionAddWizard = 1
		,actionCalendar = 2
		,actionClose = 3
		,actionCopy = 4
		,actionDatabaseName = 5
		,actionDelete = 6
		,actionExpandClose = 7
		,actionExpandOpen = 8
		,actionHomePage = 9
		,actionLogout = 10
		,actionMoveDown = 11
		,actionMoveUp = 12
		,actionNotifications = 13
		,actionOpen = 14
		,actionPrint = 15
		,actionRegenerate = 16
		,actionSave = 17
		,actionSupport = 18
		,dashboardModeRows = 19
		,dashboardModeTilesBig = 20
		,dashboardModeTilesSmall = 21
		,dashboardModeViewer = 22
		,detailAccounting = 23
		,detailAnalysis = 24
		,detailBalance = 25
		,detailDetail = 26
		,detailHistory = 27
		,detailInfo = 28
		,detailLinks = 29
		,detailMovement = 30
		,detailNotes = 31
		,detailOverview = 32
		,detailReport = 33
		,detailRows = 34
		,detailSettings = 35
		,detailStock = 36
		,detailUsage = 37
		,docStateArchive = 38
		,docStateCancel = 39
		,docStateConcept = 40
		,docStateConfirmed = 41
		,docStateDelete = 42
		,docStateDone = 43
		,docStateEdit = 44
		,docStateHalfDone = 45
		,docStateNew = 46
		,docStateUnknown = 47
		,filterActive = 48
		,filterAll = 49
		,filterArchive = 50
		,filterDone = 51
		,filterOverview = 52
		,filterTrash = 53
		,formAccounting = 54
		,formAttachments = 55
		,formFilter = 56
		,formHeader = 57
		,formHistory = 58
		,formNote = 59
		,formRows = 60
		,formSettings = 61
		,formSorting = 62
		,iconAppInfoMenu = 63
		,iconBalance = 64
		,iconFile = 65
		,iconFilePdf = 66
		,iconHelp = 67
		,iconHistory = 68
		,iconImage = 69
		,iconLocked = 70
		,iconOther = 71
		,iconOwner = 72
		,iconPinned = 73
		,iconPreview = 74
		,iconReports = 75
		,iconSearch = 76
		,iconSettings = 77
		,iconStart = 78
		,iconTerminal = 79
		,iconUser = 80
		,iconVideo = 81
		,iconViewerEnd = 82
		,issueAdvertisementSPAM = 83
		,issueAlert = 84
		,issueBoardNote = 85
		,issueCall = 86
		,issueComment = 87
		,issueDiscussion = 88
		,issueMeeting = 89
		,issueNote = 90
		,issueReceivedMail = 91
		,issueSentMail = 92
		,issueSystemControl = 93
		,issueTask = 94
		,issueToDo = 95
		,personCompany = 96
		,personHuman = 97
		,personRobot = 98
		,rightSubmenuAccess = 99
		,rightSubmenuCanteen = 100
		,rightSubmenuCompartments = 101
		,rightSubmenuDocuments = 102
		,rightSubmenuMeters = 103
		,rightSubmenuNews = 104
		,rightSubmenuReceivables = 105
		,rightSubmenuReservations = 106
		,rightSubmenuSupport = 107
		,rightSubmenuToDo = 108
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
