
//const g_isMobile = window.matchMedia("(any-pointer:coarse)").matches;


class ShipardClient {
	server = new ShipardServer();
	mqtt = new ShipardMqtt();
	iot = new ShipardClientIoT();

	appVersion = '2.0.1';
	CLICK_EVENT = 'click';
	g_formId = 1;
	openModals = [];
	progressCount = 0;
	viewerScroll = 0;
	disableKeyDown = 0;

	userInfo = null;

	numPad = null;

	mainAppContent = null;

	on(eventType, selector, callback) {
		document.addEventListener(eventType, function (event) {
			if (event.target.matches(selector)) {
				callback.call(event.target, event.target);
			}
		});
	}

	onClick(selector, callback) {this.on('click', selector, callback)};



	/**
	 * simple tabs
	 */
	simpleTabsEvent(e)
	{
		let tabsId = e.getAttribute('data-tabs');
		let tabsElement = document.getElementById(tabsId+'-tabs');
		let oldActiveTabElement = tabsElement.querySelector('a.active');
		oldActiveTabElement.classList.remove('active');

		let oldActiveContentId = oldActiveTabElement.getAttribute('data-tab-id');
		let oldActiveContentElement = document.getElementById(oldActiveContentId);
		oldActiveContentElement.classList.add('d-none');

		e.classList.add('active');
		let newActiveContentId = e.getAttribute('data-tab-id');
		let newActiveContentElement = document.getElementById(newActiveContentId);
		newActiveContentElement.classList.remove('d-none');
	}


	/**
	 * upload files & camera support
	 */
	searchParentAttr (e, attr) {
		var p = e;
		while (p.length) {
			var attrValue = p.attr(attr);
			if (p.attr(attr))
				return p.attr(attr);

			p = p.parent();
			if (!p.length)
				break;
		}
		return null;
	};

	searchObjectAttr (e, attr) {
		var p = e;
		while (p.length) {
			if (p.attr(attr))
				return p;

			p = p.parent();
			if (!p.length)
				break;
		}

		return null;
	};

  widgetAction(e)
  {
    let actionId = e.getAttribute('data-action');
    this.doAction(actionId, e);
  }

  doAction (actionId, e)
  {
		switch (actionId)
    {
      case 'setColorMode': return this.setColorMode(e);
			case 'setUserContext': return this.setUserContext(e);
			case 'workplaceLogin': return this.workplaceLogin(e);
			case 'inline-action': return this.inlineAction(e);
    }

    return 0;
  }

	inlineAction (e)
  {
    if (e.getAttribute('data-object-class-id') === null)
		  return;

	  var requestParams = {};
	  requestParams['object-class-id'] = e.getAttribute('data-object-class-id');
	  requestParams['action-type'] = e.getAttribute('data-action-type');
	  this.elementPrefixedAttributes (e, 'data-action-param-', requestParams);
	  if (e.getAttribute('data-pk') !== null)
		  requestParams['pk'] = e.getAttribute('data-pk');

		shc.server.api(requestParams, function(data) {
			/*
			if (data.reloadNotifications === 1)
				e10NCReset();

			if (e.parent().hasClass('btn-group'))
			{
				e.parent().find('>button.active').removeClass('active');
				e.addClass('active');
			}
			*/
	  }.bind(this));
  }

	workplaceLogin(e)
	{
		console.log('workplaceLogin', e.getAttribute('data-login'));

		this.getNumber (
			{
				title: 'Zadejte přístupový kód',
				srcElement: e,
				userLogin: e.getAttribute('data-login'),
				success: function() {this.workplaceLoginDoIt()}.bind(this)
			}
		);

		return 0;
	}

	workplaceLoginDoIt(e)
	{
		console.log(this.numPad.options.userLogin);

		console.log("__DO_IT__", this.numPad.options.srcElement.getAttribute('data-login'), this.numPad.gnValue);

		document.getElementById('e10-login-user').value =  this.numPad.options.userLogin;//this.numPad.options.srcElement.getAttribute('data-login');
		document.getElementById('e10-login-pin').value = this.numPad.gnValue;
		document.forms['e10-mui-login-form'].submit();
	}

  getNumber (options) {
    const template = document.createElement('div');
    template.id = 'widget_123';
    template.classList.add('fullScreenModal');
    document.body.appendChild(template);

    var abc = new ShipardTouchNumPad();
    abc.options = options;
    abc.init(template);

    this.numPad = abc;
  }

	setColorMode(e)
	{
		let colorMode = e.getAttribute('data-app-color-mode');
		localStorage.setItem('shpAppColorMode', colorMode);
		this.doColorMode(colorMode);
		return 0;
	}

	setUserContext(e)
	{

		let userContextId = e.getAttribute('data-user-context');
		console.log("User context: ", userContextId);

		let apiParams = {
			'userContextId': userContextId,

		};
		this.apiCall('setUserContext', apiParams);

		return 0;
	}

	initColorMode(firstCall)
	{
		if (firstCall)
		{
			window.matchMedia('(prefers-color-scheme: dark)')
				.addEventListener('change', function() {this.initColorMode()}.bind(this));
		}

		let colorMode = localStorage.getItem('shpAppColorMode');
		if (!colorMode || colorMode === 'auto')
		{
			const isSystemDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
			if (isSystemDarkMode)
				colorMode = 'dark';
			else
				colorMode = 'light';
		}

		this.doColorMode(colorMode);
	}

	doColorMode(colorMode)
	{
		if (colorMode === 'light')
		{
			document.body.removeAttribute('data-bs-theme');
		}
		else if (colorMode === 'dark')
		{
			document.body.setAttribute('data-bs-theme', 'dark');
		}
		else if (colorMode === 'auto')
		{
			this.initColorMode();
		}

		var uiColorMode = colorMode;
		let savedColorMode = localStorage.getItem('shpAppColorMode');
		if (!savedColorMode || savedColorMode === 'auto')
			uiColorMode = 'auto';

		let colorModeElements = document.querySelectorAll('[data-action="setColorMode"]');
		for (let idx = 0; idx < colorModeElements.length; idx++)
		{
			if (colorModeElements[idx].getAttribute('data-app-color-mode') === uiColorMode)
				colorModeElements[idx].classList.add('active');
			else
				colorModeElements[idx].classList.remove('active');
		}
	}

  elementPrefixedAttributes (iel, prefix, data)
  {
    for (var i = 0, attrs = iel.attributes, l = attrs.length; i < l; i++)
    {
      var attrName = attrs.item(i).nodeName;
      if (attrName.substring(0, prefix.length) !== prefix)
        continue;
      var attrNameShort = attrName.substring(prefix.length);
      var val = attrs.item(i).nodeValue;
      data[attrNameShort] = val;
    }
  }

	initUI()
	{
		if (!this.mainAppContent)
			return 0;

		if ('mainUiObjectId' in this.mainAppContent.dataset)
			return this.initUIObject(this.mainAppContent.dataset.mainUiObjectId);
	}

	initUIObject(id)
	{
		let objectElement = document.getElementById(id);
		if (!objectElement)
		{
			console.error('element not exist: #', id);
			return 0;
		}

		const objectElementType = objectElement.getAttribute('data-object-type');
		if (!objectElementType)
		{
			console.error('`data-object-type` attr not found in #', id);
			return 0;
		}

		if (objectElementType === 'data-viewer')
			return initWidgetTableViewer(id);

		console.log(objectElementType);

		return 0;
	}

	loadUI()
	{
		console.log("client__load_ui");
	}

	init ()
	{
		this.mainAppContent = document.getElementById('shp-main-app-content');
		this.server.setHttpServerRoot(httpApiRootPath);

		this.initColorMode(true);

		this.onClick ('a.shp-simple-tabs-item', function () {shc.simpleTabsEvent(this);});
		this.onClick ('.shp-app-action', function (e) {this.widgetAction(e);}.bind(this));

		this.initUI();

		if ('serviceWorker' in navigator && e10ServiceWorkerURL !== undefined) {
			navigator.serviceWorker.register(e10ServiceWorkerURL)
				.then(function(reg){
				}).catch(function(err) {
				console.log("Service worker registration error: ", err)
			});
		}
	}

	applyUIData (responseUIData)
	{
		this.mqtt.applyUIData(responseUIData);
		this.iot.applyUIData(responseUIData);
	}

	apiCall(apiActionId, outsideApiParams)
  {
    var apiParams = {};
    apiParams['requestType'] = 'appCommand';
    apiParams['actionId'] = apiActionId;
    if (outsideApiParams !== undefined)
      apiParams = {...apiParams, ...outsideApiParams};

    console.log("CLIENT-API-CALL", apiParams);

    var url = 'api/v2';

    shc.server.post (url, apiParams,
      function (data) {
        console.log("--app-api-call-success--");
        this.doAppAPIResponse(data);
      }.bind(this),
      function (data) {
        console.log("--api-app-call-error--");
      }.bind(this)
    );
  }

	doAppAPIResponse(data)
	{
		window.location.reload(true);
	}
}
