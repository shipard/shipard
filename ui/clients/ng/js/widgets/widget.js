class ShipardWidget {
  rootElm = null;
  rootId = '';
  numPad = null;

  init (rootElm)
  {
    this.rootElm = rootElm;
    this.rootId = this.rootElm.getAttribute('id');

    this.on(this, 'click', '.shp-widget-action', function (e, ownerWidget, event){ownerWidget.widgetAction(e, event)});
  }

  widgetAction(e, event)
  {
    //console.log('widgetAction', event);
    let actionId = e.getAttribute('data-action');
    this.doAction(actionId, e);
    event.stopPropagation();
  }

  doAction (actionId, e)
  {
    console.log("ACTION-WIDGET: ", actionId);

    switch (actionId)
    {
      case 'inline-action': return this.inlineAction(e);
      case 'select-main-tab': return this.selectMainTab(e);
      case 'select-simple-tab': return this.selectSimpleTab(e);
			case 'open-popup': return this.openPopup(e);
      case 'open-modal': return this.openModal(e);
      case 'closeModal': return this.closeModal(e);
      case 'treeListGroupOC': return this.treeListGroupOC(e);
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

    console.log("__INLINE_ACTION", requestParams);
  }

  openModal(e)
  {
    const modalType = e.getAttribute('data-modal-type');

    //console.log("OPEN-MODAL; ", modalType, e);

    var modalParams = {};
	  var modalAttrs = {
      'parent-widget-id': this.rootElm.getAttribute('id'),
      'parent-widget-type': 'unknown',
    };

    this.elementPrefixedAttributes (e, 'data-action-param-', modalParams);

    let newEnvelope = document.createElement('data-modal-env');
    newEnvelope.setAttribute('data-request-type', 'dataModal');
    newEnvelope.innerHTML = "<div class='tlbr'><span class='backIcon shp-widget-action' data-action='closeModal'></span><span class='modalTitle'></span></div><div class='content'></div>";

    for (const oneParamId in modalParams)
      newEnvelope.setAttribute('data-action-param-'+oneParamId, modalParams[oneParamId]);

    newEnvelope.id = 'shc_meid_'+shc.counter++;

    //newEnvelope.innerHTML = "čekejte, prosím, data se načítají...";

    document.body.appendChild(newEnvelope);

    newEnvelope.shpWidget = new ShipardWidget();
    newEnvelope.shpWidget.init(newEnvelope);

    switch (modalType)
    {
      case 'viewer':  console.log('Viewer!');
                      break;
    }

    let apiParams = {
      'cgType': 2,
      'requestType': 'openModal',
      //'formOp': e.formOp,
    };

    this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);

    console.log("API-CALL-MODAL", apiParams);

    var url = 'api/v2';

    shc.server.post (url, apiParams,
      function (data) {
        console.log("--api-call-MODAL-success--", data);
        this.doWidgetModalResponse(data, newEnvelope.id);
      }.bind(this),
      function (data) {
        console.log("--api-call-MODAL-error--");
      }.bind(this)
    );

    return 0;
  }

  closeModal(e)
  {
    this.rootElm.remove();
    return 0;
  }

  openPopup(e)
  {
    const url = e.getAttribute ('data-url');
    var height = ((screen.availHeight * window.devicePixelRatio) * 0.8) | 0;
    var width = (height * .7 + 50) | 0;
		let popUpId = '-openPopupAtt';

    var nw = window.open(url, "shpd-cl-ng"+popUpId, "location=no,status=no,width=" + width + ",height=" + height);

    nw.focus();

    return 0;
  }

  selectMainTab (e)
  {
    //console.log("__SELECT_MAIN__TAB__", e);
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
    //console.log("SELECT MAIN TAB: ", inputValueId);
    this.apiCall('reloadContent', apiParams);
  }

  selectSimpleTab (e)
  {
    //console.log("__SELECT_SIMPLE__TAB__", e);
    const tabsId = e.getAttribute('data-tabs');
    const tabsElement = document.getElementById(tabsId);

		let oldActiveTabElement = tabsElement.querySelector('.active');
		oldActiveTabElement.classList.remove('active');
		e.classList.add('active');

    const tabsOldElementContentId = oldActiveTabElement.getAttribute('data-tab-id');
    document.getElementById(tabsOldElementContentId).classList.remove('active');

    const tabsNewElementContentId = e.getAttribute('data-tab-id');
    document.getElementById(tabsNewElementContentId).classList.add('active');
  }

  treeListGroupOC(e)
  {
    let itemElement = e.parentElement;
		if (itemElement.classList.contains('open'))
    {
      itemElement.classList.remove('open');
      itemElement.classList.add('closed');
    }
    else
    {
      itemElement.classList.remove('closed');
      itemElement.classList.add('open');
    }
  }

  apiCall(apiActionId, outsideApiParams)
  {
    var apiParams = {};
    apiParams['requestType'] = this.rootElm.getAttribute('data-request-type');
    apiParams['classId'] = this.rootElm.getAttribute('data-class-id');
    apiParams['actionId'] = apiActionId;
    apiParams['widgetId'] = this.rootElm.id;
    if (outsideApiParams !== undefined)
      apiParams = {...apiParams, ...outsideApiParams};

    this.detectValues(apiParams);

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

  apiCallObject(classId, outsideApiParams)
  {
    var apiParams = {};
    apiParams['requestType'] = 'object';
    apiParams['classId'] = classId;
    if (outsideApiParams !== undefined)
      apiParams = {...apiParams, ...outsideApiParams};

    console.log("API-CALL-OBJECT", apiParams);

    var url = 'api/v2';

    shc.server.post (url, apiParams,
      function (data) {
        console.log("--api-call-success--");
        this.doApiObjectResponse(data);
      }.bind(this),
      function (data) {
        console.log("--api-call-error--");
      }.bind(this)
    );
  }

  detectValues(data)
  {
    //const inputs = this.rootElm.querySelectorAll("input[data-wid='"+this.rootId+"']");
    const inputs = this.rootElm.querySelectorAll("input");

    for (let i = 0; i < inputs.length; ++i)
    {
      //console.log("INPUT: ", inputs[i]);
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

  doApiObjectResponse(data)
  {
    console.log(data);
  }

  doWidgetModalResponse(data, targetElementId)
  {
    if (data['response'] !== undefined && data['response']['uiData'] !== undefined)
      shc.applyUIData (data['response']['uiData']);

    //console.log('doWidgetModalResponse', data);

    var targetModalElement = document.getElementById(targetElementId);
    var contentElement = targetModalElement.querySelector('div.content');
    var tlbrElement = targetModalElement.querySelector('div.tlbr');
    console.log("tlbrElement", tlbrElement);
    var backIconElement = tlbrElement.querySelector('.backIcon');
    var titleElement = tlbrElement.querySelector('.modalTitle');

    if (data.response.hcBackIcon !== undefined)
      backIconElement.innerHTML = data.response.hcBackIcon;

    if (data.response.hcTitle !== undefined)
      titleElement.innerHTML = data.response.hcTitle;

    this.setInnerHTML(contentElement, data.response.hcFull);

    if (data.response.objectType === 'dataView')
      initWidgetTableViewer(data.response.objectId);
    else
    {
      console.log("init-other-widget");
      let e = document.getElementById(data.response.objectId);
      e.shpWidget = new ShipardWidget();
      e.shpWidget.init(e);
    }
  }

	on(ownerWidget, eventType, selector, callback) {
		this.rootElm.addEventListener(eventType, function (event) {
      var ce = event.target.closest(selector);
			if (ce) {

				callback.call(ce, ce, ownerWidget, event);
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
  }

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

  openModalForm(formOp, params, attrs)
  {
    let newEnvelope = document.createElement('data-modal-form-env');
    newEnvelope.setAttribute('data-request-type', 'dataForm');
    for (const oneParamId in params)
      newEnvelope.setAttribute('data-action-param-'+oneParamId, params[oneParamId]);
    for (const oneParamId in attrs)
      newEnvelope.setAttribute('data-'+oneParamId, attrs[oneParamId]);

    newEnvelope.id = 'shc_meid_'+shc.counter++;

    newEnvelope.innerHTML = "čekejte, prosím, data se načítají...";

    document.body.appendChild(newEnvelope);

    newEnvelope.formOp = formOp;
    newEnvelope.shpWidget = new ShipardTableForm();
    newEnvelope.shpWidget.init(newEnvelope);
  }

  setInnerHTML(elm, html) {
    elm.innerHTML = html;

    Array.from(elm.querySelectorAll("script"))
      .forEach( oldScriptEl => {
        const newScriptEl = document.createElement("script");

        Array.from(oldScriptEl.attributes).forEach( attr => {
          newScriptEl.setAttribute(attr.name, attr.value)
        });

        const scriptText = document.createTextNode(oldScriptEl.innerHTML);
        newScriptEl.appendChild(scriptText);

        oldScriptEl.parentNode.replaceChild(newScriptEl, oldScriptEl);
    });
  }
}

function inputCh()
{
  console.log("--CHANGE--");
}