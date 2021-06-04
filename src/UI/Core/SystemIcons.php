<?php

namespace Shipard\UI\Core;
class SystemIcons
{
	var $iconsId;
	private ?array $data = NULL;

	const
		 actionAdd = 0
		,actionAddWizard = 1
		,actionBack = 2
		,actionCalendar = 3
		,actionClose = 4
		,actionCopy = 5
		,actionDatabaseName = 6
		,actionDelete = 7
		,actionDownload = 8
		,actionExpandClose = 9
		,actionExpandOpen = 10
		,actionHomePage = 11
		,actionInputClear = 12
		,actionInputMinus = 13
		,actionInputPlus = 14
		,actionInputSearch = 15
		,actionLogout = 16
		,actionMoveDown = 17
		,actionMoveUp = 18
		,actionNotifications = 19
		,actionOpen = 20
		,actionPrint = 21
		,actionRegenerate = 22
		,actionSave = 23
		,actionSupport = 24
		,actionWizardDone = 25
		,actionWizardNext = 26
		,brandsGoogle = 27
		,dashboardDashboard = 28
		,dashboardModeRows = 29
		,dashboardModeTilesBig = 30
		,dashboardModeTilesSmall = 31
		,dashboardModeViewer = 32
		,detailAccounting = 33
		,detailAnalysis = 34
		,detailBalance = 35
		,detailCalculate = 36
		,detailDetail = 37
		,detailHistory = 38
		,detailInfo = 39
		,detailLinks = 40
		,detailMovement = 41
		,detailNotes = 42
		,detailOverview = 43
		,detailRecipients = 44
		,detailReport = 45
		,detailReservations = 46
		,detailRows = 47
		,detailSettings = 48
		,detailSources = 49
		,detailStock = 50
		,detailSubjects = 51
		,detailUsage = 52
		,docStateArchive = 53
		,docStateCancel = 54
		,docStateConcept = 55
		,docStateConfirmed = 56
		,docStateDelete = 57
		,docStateDone = 58
		,docStateEdit = 59
		,docStateHalfDone = 60
		,docStateNew = 61
		,docStateUnknown = 62
		,filterActive = 63
		,filterAll = 64
		,filterArchive = 65
		,filterDone = 66
		,filterOverview = 67
		,filterTrash = 68
		,formAccounting = 69
		,formAttachments = 70
		,formFilter = 71
		,formHeader = 72
		,formHistory = 73
		,formNote = 74
		,formNotes = 75
		,formRows = 76
		,formSettings = 77
		,formSorting = 78
		,iconAdmin = 79
		,iconAppInfoMenu = 80
		,iconBalance = 81
		,iconBook = 82
		,iconBug = 83
		,iconCamera = 84
		,iconDatabase = 85
		,iconEmail = 86
		,iconFile = 87
		,iconFilePdf = 88
		,iconHamburgerMenu = 89
		,iconHelp = 90
		,iconHistory = 91
		,iconImage = 92
		,iconImport = 93
		,iconInbox = 94
		,iconKeyboard = 95
		,iconLaboratory = 96
		,iconLocalServer = 97
		,iconLocked = 98
		,iconOther = 99
		,iconOwner = 100
		,iconPhone = 101
		,iconPhoto = 102
		,iconPinned = 103
		,iconPreview = 104
		,iconReaders = 105
		,iconReports = 106
		,iconSearch = 107
		,iconSettings = 108
		,iconSpinner = 109
		,iconStart = 110
		,iconTerminal = 111
		,iconUser = 112
		,iconVideo = 113
		,iconViewerEnd = 114
		,iconWarning = 115
		,iconWorkplace = 116
		,issueAdvertisementSPAM = 117
		,issueAlert = 118
		,issueBoardNote = 119
		,issueCall = 120
		,issueComment = 121
		,issueDiscussion = 122
		,issueMeeting = 123
		,issueNote = 124
		,issueReceivedMail = 125
		,issueSentMail = 126
		,issueStandart = 127
		,issueSystemControl = 128
		,issueTask = 129
		,issueToDo = 130
		,leftSubmenuBulkMail = 131
		,personCompany = 132
		,personHuman = 133
		,personRobot = 134
		,rightSubmenuAccess = 135
		,rightSubmenuCalendar = 136
		,rightSubmenuCanteen = 137
		,rightSubmenuCompartments = 138
		,rightSubmenuDocuments = 139
		,rightSubmenuMeters = 140
		,rightSubmenuNews = 141
		,rightSubmenuNoticeBoard = 142
		,rightSubmenuReceivables = 143
		,rightSubmenuReservations = 144
		,rightSubmenuSupport = 145
		,rightSubmenuToDo = 146
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
