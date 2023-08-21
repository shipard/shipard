class ShipardWidget {
  rootElm = null;
  rootId = '';
  numPad = null;

  init (rootElm)
  {
    this.rootElm = rootElm;
    this.rootId = this.rootElm.getAttribute('id');

    this.on(this, 'click', '.shp-widget-action', function (e, ownerWidget){ownerWidget.widgetAction(e)});
    this.on(this, 'click', '.shp-widget-action>i', function (e, ownerWidget){ownerWidget.widgetAction(e.parentElement)});
  }

  widgetAction(e)
  {
    let actionId = e.getAttribute('data-action');
    this.doAction(actionId, e);
  }

  doAction (actionId, e)
  {
    console.log("ACTION: ", actionId);

    switch (actionId)
    {
      case 'inline-action': return this.inlineAction(e);
      case 'select-main-tab': return this.selectMainTab(e);
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

    /*
		e10.server.api(requestParams, function(data) {
		if (data.reloadNotifications === 1)
			e10NCReset();

		if (e.parent().hasClass('btn-group'))
		{
			e.parent().find('>button.active').removeClass('active');
			e.addClass('active');
		}
	  });
    */

    console.log("__INLINE_ACTION", requestParams);
  }

  selectMainTab (e)
  {
    const tabsId = e.getAttribute('data-tabs');
    const inputValueId = this.rootId + '_' + tabsId + '_Value';
    const inputElement = document.getElementById(inputValueId);
    inputElement.value = e.getAttribute('data-tab-id');

    const tabsElementId = this.rootId + '_' + tabsId;
    const tabsElement = document.getElementById(tabsElementId);

		let oldActiveTabElement = tabsElement.querySelector('.active');
		oldActiveTabElement.classList.remove('active');
		e.classList.add('active');

    let apiParams = {'cgType': 2};

    this.apiCall('reloadContent', apiParams);
    //console.log("SELECT MAIN TAB: ", inputValueId);
  }

  apiCall(apiActionId, outsideApiParams)
  {
    var apiParams = {};
    apiParams['requestType'] = this.rootElm.getAttribute('data-request-type');
    apiParams['classId'] = this.rootElm.getAttribute('data-class-id');
    apiParams['actionId'] = apiActionId;
    if (outsideApiParams !== undefined)
      apiParams = {...apiParams, ...outsideApiParams};

    this.detectValues(apiParams);

    console.log("API-CALL", apiParams);

    var url = 'api/v2';

    shc.server.post (url, apiParams,
      function (data) {
        console.log("--api-call-success--");
        this.doWidgetResponse(data);
      }.bind(this),
      function (data) {
        console.log("--api-call-error--");
      }.bind(this)
    );
  }

  detectValues(data)
  {
    const inputs = this.rootElm.querySelectorAll("input[data-wid='"+this.rootId+"']");

    for (let i = 0; i < inputs.length; ++i)
    {
      const valueKey = inputs[i].getAttribute('name');
      data[valueKey] = inputs[i].value;
    }
  }

  doWidgetResponse(data)
  {
    if (data['response'] !== undefined && data['response']['uiData'] !== undefined)
      shc.applyUIData (data['response']['uiData']);

    console.log(data);
  }

	on(ownerWidget, eventType, selector, callback) {
		this.rootElm.addEventListener(eventType, function (event) {
			if (event.target.matches(selector)) {
				callback.call(event.target, event.target, ownerWidget);
			}
		});
	}

	onClick(ownerWidget, selector, callback) {this.on(ownerWidget, 'click', selector, callback)};


  nf (n, c)
  {
    var
        c = isNaN(c = Math.abs(c)) ? 2 : c,
        d = '.',
        t = ' ',
        s = n < 0 ? "-" : "",
        i = parseInt(n = Math.abs(+n || 0).toFixed(c)) + "",
        j = (j = i.length) > 3 ? j % 3 : 0;
    return s + (j ? i.substr(0, j) + t : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : "");
  }

  parseFloat (n) {
    var str = n.replace (',', '.');
    return parseFloat(str);
  }

  round (value, decimals) {
    return Number(Math.round(value+'e'+decimals)+'e-'+decimals);
  }

  escapeHtml (str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  };

  elmHide(e)
  {
		e.classList.add('d-none');
  }

  elmShow(e)
  {
		e.classList.remove('d-none');
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
}

