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


  doWidgetResponse(data)
  {
    super.doWidgetResponse(data);

    if (data['response']['type'] === 'loadNextData')
    {
      this.appendNextData(data);
      return;
    }
  }

  appendNextData(data)
  {
    this.elmViewerLines.removeChild(this.elmViewerLines.lastElementChild);
    this.elmViewerLines.innerHTML += data['response']['hcRows'];
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
