class ShipardFilesUploader
{
  rootElm = null;
  inputElm = null;
  infoElm = null;

  uploadInProgress = 0;
  uploadDone = false;

  init (rootElm)
  {
    this.rootElm = rootElm;
    this.inputElm = this.rootElm.querySelector('input[type="file"]');
    this.infoElm = this.rootElm.querySelector('.shpd-files-upload-info');
  }

  resetInfo ()
  {
		var info = '<table class="default fullWidth">';
		for (var i = 0; i < this.inputElm.files.length; i++)
    {
			var file = this.inputElm.files[i];
			var fileSize = 0;
			if (file.size > 1024 * 1024)
				fileSize = (Math.round(file.size * 100 / (1024 * 1024)) / 100).toString() + 'MB';
			else
				fileSize = (Math.round(file.size * 100 / 1024) / 100).toString() + 'KB';
			info += '<tr>' + '<td>' + file.name + "</td><td class='number'>" + fileSize + '</td><td>-</td></tr>';
		}
		info += '</table>';

    this.infoElm.innerHTML = info;
  }

  uploadFiles()
  {
    this.uploadInProgress = this.inputElm.files.length;

    let baseUrl = this.rootElm.getAttribute('data-upload-url');
    //infoPanel.attr('data-fip', input.files.length);
    for (var i = 0; i < this.inputElm.files.length; i++)
    {
      let file = this.inputElm.files[i];
      let url = baseUrl + '/' + file.name;
      this.uploadOneFile(url, file, i);
    }
  }

  uploadOneFile(url, file, idx)
  {
		var xhr = new XMLHttpRequest();

    /*
    xhr.upload.addEventListener("progress", function (e) {
			e10.e10AttWidgetUploadProgress(e, infoPanel, idx);
		}, false);
    */
		xhr.onload = (e) => { this.uploadIsDone(idx); };

		xhr.open("POST", url);
		xhr.setRequestHeader("Cache-Control", "no-cache");
		xhr.setRequestHeader("Content-Type", "application/octet-stream");
		xhr.send(file);
	}

  uploadIsDone(idx)
  {
    this.uploadInProgress--;
    if (this.uploadInProgress === 0)
      this.uploadDone = true;

    let table = this.infoElm.querySelector('table');
    let row = table.rows[idx];
    let cell = row.cells[2];
    cell.style.backgroundColor = 'green';
	}
}
