class ShipardTableViewer extends ShipardWidget
{
  detailModes = {
    panels: 0,
    details: 1,
  };

  dmDetail = 1;

  elmViewerLines = null;
  elmViewerRows = null;

  elmViewerDetail = null;
  elmViewerDetailContent = null;
  elmViewerDetailHeader = null;
  elmViewerDetailTabs = null;

  detailMode = this.detailModes.panels;

  doWidgetResponse(data)
  {
    console.log("ShipardTableViewer::doWidgetResponse");

    super.doWidgetResponse(data);
  }

  init(e)
  {
    console.log("ShipardTableViewer::init");
    super.init(e);
    this.rootElm.style.display = 'grid';

    const id = e.getAttribute('id');
    this.elmViewerLines = document.getElementById(id + 'Items');
    this.elmViewerRows = this.elmViewerLines.parentElement;
    this.elmViewerRows.addEventListener('scroll', (event) => {this.doScroll(event)});

    this.elmViewerDetail = this.rootElm.querySelector('div.detail');
    if (this.elmViewerDetail)
    {
      this.elmViewerDetailContent = this.elmViewerDetail.querySelector('div.content');
      this.elmViewerDetailHeader = this.elmViewerDetail.querySelector('div.header');
      this.elmViewerDetailTabs = this.elmViewerDetail.querySelector('div.tabs');
    }

    this.on(this, 'click', 'div.rows-list.mainViewer>div.r', function (e, ownerWidget, event){this.rowClick(e, event)}.bind(this));
    //this.on(this, 'click', 'div.rows-list.mainViewer>div.r *', function (e, ownerWidget, event){this.rowClick(e, event)}.bind(this));
  }

  doAction (actionId, e)
  {
    console.log("viewerAction", actionId);
    switch (actionId)
    {
      case 'newform': return this.actionNewForm(e);
      case 'viewerTabsReload': return this.viewerTabsReload(e);
      case 'detailSelect': return this.detailSelect(e);
      case 'viewerPanelTab': return this.viewerPanelTab(e);
    }

    return super.doAction (actionId, e);
  }

  doScroll(event)
  {
    const e = event.target;

    const loadOnProgress = parseInt(this.elmViewerRows.getAttribute ('data-loadonprogress'));
    if (loadOnProgress)
      return;

    const heightToEnd = e.scrollHeight - (e.scrollTop + e.clientHeight);
    if (heightToEnd <= 500)
    {
      this.elmViewerRows.setAttribute ('data-loadonprogress', 1);
      window.requestAnimationFrame (() => {this.viewerRefreshLoadNext();});
    }
  }

  viewerTabsReload(e)
  {
    let itemElement = e.parentElement;

    const inputElement = itemElement.querySelector('input');
    inputElement.value = e.getAttribute('data-value');

    let oldActiveTabElement = itemElement.querySelector('.active');
		oldActiveTabElement.classList.remove('active');
		e.classList.add('active');

    this.refreshData(e);
  }

  viewerPanelTab(e)
  {
    let itemElement = e.parentElement;

    let oldActiveTabElement = itemElement.querySelector('.active');
		oldActiveTabElement.classList.remove('active');
		e.classList.add('active');
  }

  viewerRefreshLoadNext()
  {
    const tableName = this.rootElm.getAttribute ("data-table");
    if (!tableName)
      return;

    this.df2FillViewerLines ();
  }

  rowClick(e, event)
  {
    if (!this.elmViewerDetail)
      return;

    if (e.classList.contains('active'))
    {
      e.classList.remove('active');
      this.detailClose();
      return;
    }

    let oldActiveRowElement = this.elmViewerLines.querySelector('.active');
    if (oldActiveRowElement)
      oldActiveRowElement.classList.remove('active');
		e.classList.add('active');

    this.detailOpen(e);
  }

  detailSelect(e)
  {
    console.log('detailSelect1');
    if (this.elmViewerDetailTabs)
    {
      const activeTabElement = this.elmViewerDetailTabs.querySelector('.active');

      if (activeTabElement)
      {
        activeTabElement.classList.remove('active');
        e.classList.add('active');

        console.log('detailSelect2');

        const activeRowElement = this.elmViewerLines.querySelector('.active');
        this.detailOpen(activeRowElement);
      }
    }

    return 0;
  }

  detailOpen(activeRowElement)
  {
    this.detailMode = this.detailModes.details;
    this.rootElm.classList.remove('dmPanels');
    this.rootElm.classList.add('dmDetails');

    const viewId = this.rootElm.getAttribute ('data-viewer-view-id');
    const tableId = this.rootElm.getAttribute ('data-table');
    const rowNdx = activeRowElement.getAttribute('data-pk');

    let detailId = 'default';
    if (this.elmViewerDetailTabs)
    {
      const activeTabElement = this.elmViewerDetailTabs.querySelector('.active');

      if (activeTabElement)
        detailId = activeTabElement.getAttribute('data-detail');
    }

    var apiParams = {};
    apiParams['requestType'] = 'dataViewerDetail';
    apiParams['actionId'] = 'loadDetail';
    apiParams['table'] = tableId;
    apiParams['viewId'] = viewId;
    apiParams['detailId'] = detailId;
    apiParams['pk'] = rowNdx;

    this.detectValues(apiParams);

    //console.log("API-CALL-DETAIL-OPEN", apiParams);

    var url = 'api/v2';

    shc.server.post (url, apiParams,
      function (data) {
        //console.log("--api-call-detail-success--", data);
        this.doDetailOpenResponse(data);
      }.bind(this),
      function (data) {
        console.log("--api-call-error--");
      }.bind(this)
    );

    //
  }

  detailClose()
  {
    this.detailMode = this.detailModes.panels;
    this.rootElm.classList.remove('dmDetails');
    this.rootElm.classList.add('dmPanels');
  }

  doDetailOpenResponse(data)
  {
    this.setInnerHTML(this.elmViewerDetailHeader, data.response.hcHeader);
    this.setInnerHTML(this.elmViewerDetailContent, data.response.hcContent);

  }

  df2FillViewerLines ()
  {
    var tableName = this.rootElm.getAttribute ("data-table");
    if (!tableName)
      return;

    //var viewerOptions = this.rootElm.getAttribute ("data-viewer-view-id");

    var rowsPageNumber = parseInt (this.elmViewerLines.getAttribute ('data-rowspagenumber')) + 1;
    var viewId = this.rootElm.getAttribute ('data-viewer-view-id');

    let apiParams = {
      'cgType': 2,
      'table': tableName,
      'rowsPageNumber': rowsPageNumber,
      'viewId': viewId,
    };
    this.apiCall('loadNextData', apiParams);

    return true;
  }

  refreshData ()
  {
    var tableName = this.rootElm.getAttribute ("data-table");
    if (!tableName)
      return;

    var viewId = this.rootElm.getAttribute ('data-viewer-view-id');

    let apiParams = {
      'cgType': 2,
      'table': tableName,
      'rowsPageNumber': 0,
      'viewId': viewId,
    };
    this.apiCall('refreshData', apiParams);

    return true;
  }

  actionNewForm(e)
  {
	  var formParams = {};
	  var formAttrs = {
      'parent-widget-id': this.rootElm.getAttribute('id'),
      'parent-widget-type': 'viewer',
    };
    this.elementPrefixedAttributes (this.rootElm, 'data-form-param-', formParams);
    this.elementPrefixedAttributes (e, 'data-action-param-', formParams);
    this.openModalForm('new', formParams, formAttrs);
  }

  doWidgetResponse(data)
  {
    super.doWidgetResponse(data);

    if (data['response']['type'] === 'loadNextData')
    {
      this.appendNextData(data);
      return;
    }
    else if (data['response']['type'] === 'refreshData')
    {
      this.appendNextData(data, 1);
      return;
    }
  }

  appendNextData(data, clear)
  {
    if (clear !== undefined)
    {

      this.elmViewerLines.innerHTML = data['response']['hcRows'];
      this.elmViewerLines.parentElement.scrollTop = 0;
    }
    else
    {
      this.elmViewerLines.removeChild(this.elmViewerLines.lastElementChild);
      this.elmViewerLines.innerHTML += data['response']['hcRows'];
    }
    this.elmViewerLines.setAttribute ('data-rowspagenumber', data['response']['rowsPageNumber']);
    this.elmViewerRows.setAttribute ('data-loadonprogress', 0);
  }
}

function initWidgetTableViewer(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new ShipardTableViewer();
  e.shpWidget.init(e);
  return 1;
}
