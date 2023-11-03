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
  }

  doAction (actionId, e)
  {
    switch (actionId)
    {
      case 'newform': return this.actionNewForm(e);
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

  viewerRefreshLoadNext()
  {
    const tableName = this.rootElm.getAttribute ("data-table");
    if (!tableName)
      return;

    this.df2FillViewerLines ();
  }

  df2FillViewerLines ()
  {
    var tableName = this.rootElm.getAttribute ("data-table");
    if (!tableName)
      return;

    var viewerOptions = this.rootElm.getAttribute ("data-viewer-view-id");

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
