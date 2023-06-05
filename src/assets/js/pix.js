class vindiPix extends HTMLElement {

  constructor() {
    // establish prototype chain
    super();

    this.dialog = document.getElementById('vindi-pix-dialog');
    this.image  = this.dialog?.querySelector('.vindi-pix-dialog__image');
    this.input  = this.dialog?.querySelector('.vindi-pix-dialog__input');
  }

  showDialog(transaction) {
    if(!transaction)
      return;

    const response = transaction['gateway_response_fields'];

    if(this.image)
      this.image.src = response['qrcode_path'];
    if(this.input)
      this.input.value = response['qrcode_original_path'];
    
    this.dialog?.showModal();
  }

}

customElements.define('vindi-pix', vindiPix);