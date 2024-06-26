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
		<?php do_action('vindi_pix_dialog'); ?>
	>
		<a class="vindi-pix-dialog__close" href="<?= apply_filters('vindi_pix_dialog_close_href', home_url()) ?>" aria-label="Fechar">
			<img src="<?= plugins_url('/assets/images/close.svg', plugin_dir_path(__FILE__)) ?>" />
		</a>

		<div class="vindi-pix-dialog__content">
			<?php do_action('vindi_pix_dialog_content_start', $id); ?>

			<h3 class="vindi-pix-dialog__title"><?php _e('Código gerado com sucesso', VINDI); ?></h3>

			<ul class="vindi-pix-dialog__list">
				<li><?php _e('Copie o código ou utilize o QR code abaixo para iniciar o pagamento', VINDI); ?></li>
				<li><?php _e('Acesse seu Internet Banking ou app de pagamentos', VINDI); ?></li>
				<li><?php _e('Cole o código e realize o pagamento', VINDI); ?></li>
			</ul>

			<div class="vindi-pix-dialog__image-container" <?php do_action('vindi_pix_qrcode'); ?>>
				<embed class="vindi-pix-dialog__image" src="" title="QR Code para pagamento">
			</div>

			<input class="vindi-pix-dialog__input" type="text" readonly value="3-2138210-38-02130-21930-123912.revistaoeste.com.br/21390i1dsadsapk" <?php do_action('vindi_pix_code'); ?> />

			<a class="vindi-pix-dialog__button" href="#" <?php do_action('vindi_pix_copy'); ?>>Copiar código</a>

			<?php do_action('vindi_pix_dialog_content_end', $id); ?>
		</div>

		<?php if(apply_filters('vindi_pix_dialog_show_footer', true)): ?>
			<footer class="vindi-pix-dialog__footer">
				<?php do_action('vindi_pix_dialog_footer_start', $id); ?>

				<h4 class="vindi-pix-dialog__footer-title">Estamos aguardando o pagamento...</h4>

				<h5 class="vindi-pix-dialog__footer-subtitle">
					<img src="<?= plugins_url('/assets/images/clock.svg', plugin_dir_path(__FILE__)) ?>" />

					Pague e será creditado na hora.
				</h5>

				<?php do_action('vindi_pix_dialog_footer_end', $id); ?>
			</footer>
		<?php endif; ?>

		<?php if(apply_filters('vindi_pix_dialog_show_info', true)): ?>
			<p class="vindi-pix-dialog__info">
				<?= apply_filters('vindi_pix_dialog_show_info', 'Ainda não identificamos seu pagamento, <br /> mas você <a href="' . home_url() .'">poderá acessar o site</a> enquanto ocorre o processamento. <br /> Notificaremos assim que o estiver OK.') ?>
			</p>
		<?php endif; ?>
	</dialog>
</vindi-pix>
