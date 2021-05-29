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
		,actionInputClear = 10
		,actionInputMinus = 11
		,actionInputPlus = 12
		,actionInputSearch = 13
		,actionLogout = 14
		,actionMoveDown = 15
		,actionMoveUp = 16
		,actionNotifications = 17
		,actionOpen = 18
		,actionPrint = 19
		,actionRegenerate = 20
		,actionSave = 21
		,actionSupport = 22
		,dashboardModeRows = 23
		,dashboardModeTilesBig = 24
		,dashboardModeTilesSmall = 25
		,dashboardModeViewer = 26
		,detailAccounting = 27
		,detailAnalysis = 28
		,detailBalance = 29
		,detailDetail = 30
		,detailHistory = 31
		,detailInfo = 32
		,detailLinks = 33
		,detailMovement = 34
		,detailNotes = 35
		,detailOverview = 36
		,detailReport = 37
		,detailRows = 38
		,detailSettings = 39
		,detailStock = 40
		,detailUsage = 41
		,docStateArchive = 42
		,docStateCancel = 43
		,docStateConcept = 44
		,docStateConfirmed = 45
		,docStateDelete = 46
		,docStateDone = 47
		,docStateEdit = 48
		,docStateHalfDone = 49
		,docStateNew = 50
		,docStateUnknown = 51
		,filterActive = 52
		,filterAll = 53
		,filterArchive = 54
		,filterDone = 55
		,filterOverview = 56
		,filterTrash = 57
		,formAccounting = 58
		,formAttachments = 59
		,formFilter = 60
		,formHeader = 61
		,formHistory = 62
		,formNote = 63
		,formNotes = 64
		,formRows = 65
		,formSettings = 66
		,formSorting = 67
		,iconAppInfoMenu = 68
		,iconBalance = 69
		,iconFile = 70
		,iconFilePdf = 71
		,iconHelp = 72
		,iconHistory = 73
		,iconImage = 74
		,iconLaboratory = 75
		,iconLocalServer = 76
		,iconLocked = 77
		,iconOther = 78
		,iconOwner = 79
		,iconPinned = 80
		,iconPreview = 81
		,iconReports = 82
		,iconSearch = 83
		,iconSettings = 84
		,iconSpinner = 85
		,iconStart = 86
		,iconTerminal = 87
		,iconUser = 88
		,iconVideo = 89
		,iconViewerEnd = 90
		,iconWorkplace = 91
		,issueAdvertisementSPAM = 92
		,issueAlert = 93
		,issueBoardNote = 94
		,issueCall = 95
		,issueComment = 96
		,issueDiscussion = 97
		,issueMeeting = 98
		,issueNote = 99
		,issueReceivedMail = 100
		,issueSentMail = 101
		,issueSystemControl = 102
		,issueTask = 103
		,issueToDo = 104
		,leftSubmenuBulkMail = 105
		,personCompany = 106
		,personHuman = 107
		,personRobot = 108
		,rightSubmenuAccess = 109
		,rightSubmenuCanteen = 110
		,rightSubmenuCompartments = 111
		,rightSubmenuDocuments = 112
		,rightSubmenuMeters = 113
		,rightSubmenuNews = 114
		,rightSubmenuReceivables = 115
		,rightSubmenuReservations = 116
		,rightSubmenuSupport = 117
		,rightSubmenuToDo = 118
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
