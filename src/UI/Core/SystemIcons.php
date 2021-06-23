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
		,actionLogIn = 16
		,actionLogout = 17
		,actionMove = 18
		,actionMoveDown = 19
		,actionMoveUp = 20
		,actionNotifications = 21
		,actionOpen = 22
		,actionPlay = 23
		,actionPrint = 24
		,actionRecycle = 25
		,actionRegenerate = 26
		,actionSave = 27
		,actionSettings = 28
		,actionStop = 29
		,actionSupport = 30
		,actionUpload = 31
		,actionUserSettings = 32
		,actionWizardDone = 33
		,actionWizardNext = 34
		,brandsGoogle = 35
		,dashboardDashboard = 36
		,dashboardModeRows = 37
		,dashboardModeTilesBig = 38
		,dashboardModeTilesSmall = 39
		,dashboardModeViewer = 40
		,detailAccounting = 41
		,detailAnalysis = 42
		,detailBalance = 43
		,detailCalculate = 44
		,detailDetail = 45
		,detailHistory = 46
		,detailInfo = 47
		,detailLinks = 48
		,detailMovement = 49
		,detailNotes = 50
		,detailOverview = 51
		,detailRecipients = 52
		,detailReport = 53
		,detailReservations = 54
		,detailRows = 55
		,detailSettings = 56
		,detailSources = 57
		,detailStock = 58
		,detailSubjects = 59
		,detailUsage = 60
		,docStateArchive = 61
		,docStateCancel = 62
		,docStateConcept = 63
		,docStateConfirmed = 64
		,docStateDelete = 65
		,docStateDone = 66
		,docStateEdit = 67
		,docStateHalfDone = 68
		,docStateNew = 69
		,docStateUnknown = 70
		,filterActive = 71
		,filterAll = 72
		,filterArchive = 73
		,filterDone = 74
		,filterOverview = 75
		,filterTrash = 76
		,formAccounting = 77
		,formAttachments = 78
		,formFilter = 79
		,formHeader = 80
		,formHistory = 81
		,formNote = 82
		,formNotes = 83
		,formRows = 84
		,formSettings = 85
		,formSorting = 86
		,iconAdmin = 87
		,iconAngleRight = 88
		,iconAppInfoMenu = 89
		,iconBalance = 90
		,iconBook = 91
		,iconBug = 92
		,iconCalendar = 93
		,iconCamera = 94
		,iconCheck = 95
		,iconCheckSquare = 96
		,iconCogs = 97
		,iconCutlery = 98
		,iconDatabase = 99
		,iconDelivery = 100
		,iconEmail = 101
		,iconFile = 102
		,iconFilePdf = 103
		,iconHamburgerMenu = 104
		,iconHelp = 105
		,iconHistory = 106
		,iconHome = 107
		,iconIdBadge = 108
		,iconImage = 109
		,iconImport = 110
		,iconInbox = 111
		,iconInfo = 112
		,iconKeyboard = 113
		,iconLaboratory = 114
		,iconLink = 115
		,iconList = 116
		,iconLocalServer = 117
		,iconLocked = 118
		,iconMapMarker = 119
		,iconOrder = 120
		,iconOther = 121
		,iconOwner = 122
		,iconPaperPlane = 123
		,iconPencil = 124
		,iconPhone = 125
		,iconPhoto = 126
		,iconPinned = 127
		,iconPreview = 128
		,iconReaders = 129
		,iconReports = 130
		,iconSearch = 131
		,iconSettings = 132
		,iconSitemap = 133
		,iconSpinner = 134
		,iconStar = 135
		,iconStart = 136
		,iconTerminal = 137
		,iconUser = 138
		,iconVideo = 139
		,iconViewerEnd = 140
		,iconWarning = 141
		,iconWorkplace = 142
		,issueAdvertisementSPAM = 143
		,issueAlert = 144
		,issueBoardNote = 145
		,issueCall = 146
		,issueComment = 147
		,issueConcept = 148
		,issueDiscussion = 149
		,issueImportant = 150
		,issueMeeting = 151
		,issueNotImportant = 152
		,issueNote = 153
		,issueReceivedMail = 154
		,issueSelected = 155
		,issueSentMail = 156
		,issueStandart = 157
		,issueSystemControl = 158
		,issueTask = 159
		,issueToDo = 160
		,issueUnread = 161
		,leftSubmenuBulkMail = 162
		,personCompany = 163
		,personHuman = 164
		,personRobot = 165
		,rightSubmenuAccess = 166
		,rightSubmenuCalendar = 167
		,rightSubmenuCanteen = 168
		,rightSubmenuCompartments = 169
		,rightSubmenuDocuments = 170
		,rightSubmenuMeters = 171
		,rightSubmenuNews = 172
		,rightSubmenuNoticeBoard = 173
		,rightSubmenuReceivables = 174
		,rightSubmenuReservations = 175
		,rightSubmenuSupport = 176
		,rightSubmenuToDo = 177
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
