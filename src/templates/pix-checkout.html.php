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

<fieldset>
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
</fieldset>

<dialog
    id="vindi-pix-dialog"
	class="vindi-pix-dialog"
>
  
</dialog>