class ShipardWizardForm extends ShipardTableForm
{
  pageNumber = 0;

  init(e)
  {
    console.log("ShipardWizardForm::init");
    super.init(e);
    this.rootElm.style.display = 'grid';

  }

  create(e)
  {
    let apiParams = {
      'cgType': 2,
      'formOp': e.formOp,
      'pageNumber': this.pageNumber,
    };

    this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);

    this.apiCall('createGuideForm', apiParams);
  }

  doAction (actionId, e)
  {
    console.log("guide form action: ", actionId);
    switch (actionId)
    {
      case 'wizardnext': return this.wizardNext(e);
      case 'closeForm': return this.closeForm(e);
    }

    return super.doAction(actionId, e);
  }

  wizardNext(e)
  {
    if (this.doUploadFiles(e))
    {
      setTimeout (function () {this.wizardNext (e);}.bind(this), 200);
      return;
    }

    const noClose = parseInt(e.getAttribute('data-noclose'));
    this.pageNumber++;

    this.getFormData();

    let apiParams = {
      'cgType': 2,
      'formOp': 'wizardNext',
      'formData': this.formData,
      //'rd': this.formData['recData'],
      'noCloseForm': noClose,
      'pageNumber': this.pageNumber,
      'nazdar': 'ahoj',
    };


    this.elementPrefixedAttributes (this.rootElm, 'data-action-param-', apiParams);
    this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);
    //console.log('wizardNext FD: ', this.formData);
    this.apiCall('wizardNext', apiParams);

    return 0;
  }

  checkForm(changedInput)
  {
    this.getFormData();

    let apiParams = {
      'cgType': 2,
      'formOp': 'check',
      'formData': this.formData,
      'noCloseForm': 1,
    };


    this.elementPrefixedAttributes (this.rootElm, 'data-action-param-', apiParams);
    //this.elementPrefixedAttributes (e, 'data-action-param-', apiParams);

    this.apiCall('checkForm', apiParams);

    return 0;
  }

  doWidgetResponse(data)
  {
    //console.log("doWidgerResponse / FORM: ", data['response']['type']);

    if (data['response']['type'] === 'createGuideForm')
    {
      this.rootElm.innerHTML = data['response']['hcFull'];
      if (data['response']['formData'] !== undefined)
        this.setFormData(data['response']['formData']);
      else
        this.setFormData({recData: {}});

      this.on(this, 'change', 'input', function (e, ownerWidget){ownerWidget.inputValueChanged(e)});

      this.focusFirstInput();

      return;
    }
    if (data['response']['type'] === 'wizardNext')
    {
      let noCloseForm = data['response']['saveResult']['noCloseForm'] ?? 0;
      //console.log('noCloseForm: ', noCloseForm);

      if (!noCloseForm)
      {
        const parentWidgetType = this.rootElm.getAttribute('data-parent-widget-type');
        //console.log('parentWidgetType: ', parentWidgetType);
        if (parentWidgetType === 'viewer')
        {
          const parentWidgetId = this.rootElm.getAttribute('data-parent-widget-id');
          if (parentWidgetId)
          {
            const parentElement = document.getElementById(parentWidgetId);
            if (parentElement)
              parentElement.shpWidget.refreshData();
          }
        }
        else if (parentWidgetType === 'board')
        {
          const parentWidgetId = this.rootElm.getAttribute('data-parent-widget-id');
          if (parentWidgetId)
          {
            const parentElement = document.getElementById(parentWidgetId);
            if (parentElement)
              parentElement.shpWidget.refreshData();
          }
        }

        this.closeForm();
        return;
      }

      this.rootElm.innerHTML = data['response']['hcFull'];
      this.setFormData(data['response']['formData']);
      return;
    }

    if (data['response']['type'] === 'checkForm')
    {
      //console.log("---CHECK-FORM---", data['response']);
      this.rootElm.innerHTML = data['response']['hcFull'];
      this.setFormData(data['response']['formData']);
      return;
    }

    super.doWidgetResponse(data);
  }

  closeForm(e)
  {
    this.rootElm.remove();

    return 0;
  }

  doUploadFiles()
  {
    //let fileInput = form.find (':input.e10-att-input-file').first();
    console.log('doUploadFiles - CHECK');
    let fileInput = this.rootElm.querySelector('div.shpd-files-upload-input');
    if (!fileInput || fileInput.fileUploader === undefined)
      return 0;

    if (fileInput.fileUploader.uploadInProgress)
      return 1;
    if (fileInput.fileUploader.uploadDone)
      return 0;

    fileInput.fileUploader.uploadFiles();

    return 1;
  }
}
