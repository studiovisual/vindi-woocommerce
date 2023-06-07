<?php 
	if(!defined('ABSPATH')) 
		exit; 
?>

<?php if($is_trial): ?>
	<div style="padding: 10px;border: 1px solid #f00; background-color: #fdd; color: #f00; margin: 10px 2px">
		<h3 style="color: #f00"><?php _e('MODO DE TESTES', VINDI); ?></h3>

		<p>
			<?php _e('Sua conta na Vindi está em <strong>Modo Trial</strong>. Este modo é proposto para a realização de testes e, portanto, nenhum pedido será efetivamente cobrado.', VINDI); ?>
		</p>
	</div>
<?php endif; ?>

<vindi-pix>
	<?php do_action('vindi_pix_form_start', $id); ?>

	<div class="vindi-invoice-description">
		<h3><?php _e('Gere o código para realizar o pagamento', VINDI); ?></h3>

		<ul>
			<li><?php _e('Gere o código pelo botão abaixo', VINDI); ?></li>
			<li><?php _e('Acesse seu Internet Banking ou app de pagamentos', VINDI); ?></li>
			<li><?php _e('Cole o código e realize o pagamento', VINDI); ?></li>
		</ul>
	</div>

	<?php do_action('vindi_pix_form_end', $id); ?>

	<dialog
		id="vindi-pix-dialog"
		class="vindi-pix-dialog"
	>
		<a class="vindi-pix-dialog__close" href="<?= home_url() ?>">
			<img src="<?= plugins_url('/assets/images/close.svg', plugin_dir_path(__FILE__)) ?>" />
		</a>

		<div class="vindi-pix-dialog__content">
			<h3 class="vindi-pix-dialog__title"><?php _e('Gere o código para realizar o pagamento', VINDI); ?></h3>

			<ul class="vindi-pix-dialog__list">
				<li><?php _e('Copie o código ou utilize o QR code abaixo para iniciar o pagamento', VINDI); ?></li>
				<li><?php _e('Acesse seu Internet Banking ou app de pagamentos', VINDI); ?></li>
				<li><?php _e('Cole o código e realize o pagamento', VINDI); ?></li>
			</ul>

			<div class="vindi-pix-dialog__image-container">
				<img class="vindi-pix-dialog__image" src="" />
			</div>

			<input class="vindi-pix-dialog__input" type="text" readonly value="3-2138210-38-02130-21930-123912.revistaoeste.com.br/21390i1dsadsapk" />

			<a class="vindi-pix-dialog__button" href="#">Copiar código</a>
		</div>

		<footer class="vindi-pix-dialog__footer">
      <?php do_action('vindi_pix_dialog_footer_start', $id); ?>

			<h4 class="vindi-pix-dialog__footer-title">Estamos aguardando o pagamento...</h4>

			<h5 class="vindi-pix-dialog__footer-subtitle">
        <img src="<?= plugins_url('/assets/images/clock.svg', plugin_dir_path(__FILE__)) ?>" />

        Pague e será creditado na hora.
      </h5>

      <?php do_action('vindi_pix_dialog_footer_end', $id); ?>
		</footer>

		<p class="vindi-pix-dialog__info">
			Ainda não identificamos seu pagamento, <br />
			mas você <a href="<?= home_url() ?>">poderá acessar o site</a> enquanto ocorre o processamento. <br />
			Notificaremos assim que o estiver OK.
		</p>
	</dialog>
</vindi-pix>
