class ShipardTableViewer extends ShipardWidget
{
  elmViewerLines = null;
  elmViewerRows = null;


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

    this.on(this, 'click', 'div.rows-list.mainViewer>div.r', function (e, ownerWidget, event){this.rowClick(e, event)}.bind(this));
    //this.on(this, 'click', 'div.rows-list.mainViewer>div.r *', function (e, ownerWidget, event){this.rowClick(e, event)}.bind(this));
  }

  doAction (actionId, e)
  {
    console.log("viewerAction", actionId);
    switch (actionId)
    {
      case 'newform': return this.actionNewForm(e);
      case 'viewerTabsReload': this.viewerTabsReload(e);
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

  viewerRefreshLoadNext()
  {
    const tableName = this.rootElm.getAttribute ("data-table");
    if (!tableName)
      return;

    this.df2FillViewerLines ();
  }

  rowClick(e, event)
  {
    let oldActiveRowElement = this.elmViewerLines.querySelector('.active');
    if (oldActiveRowElement)
      oldActiveRowElement.classList.remove('active');
		e.classList.add('active');

    console.log("___ROW_CLICK2___", e);
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
