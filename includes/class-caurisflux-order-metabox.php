<?php
/**
 * Metabox sur la page commande WooCommerce — affiche les infos CaurisFlux
 * et propose une action "Vérifier le statut" pour les commandes pending.
 *
 * Compatible HPOS (Custom Order Tables) et legacy post type.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Order_Metabox {

	private const META_TX_ID    = '_caurisflux_transaction_id';
	private const META_PROVIDER = '_caurisflux_provider';
	private const META_ENV      = '_caurisflux_environment';
	private const NONCE_ACTION  = 'caurisflux_check_status';

	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		// AJAX endpoint — admin-ajax.php (les <form> ne peuvent pas être imbriquées
		// dans la grande <form id="post"> de l'éditeur de commande WC, on passe
		// donc par un appel AJAX déclenché par un bouton.
		add_action( 'wp_ajax_caurisflux_check_status', array( __CLASS__, 'ajax_check_status' ) );
	}

	public static function add_metabox(): void {
		// HPOS-aware screen IDs.
		$screens = array(
			'shop_order',
			wc_get_page_screen_id( 'shop-order' ),
		);

		foreach ( array_filter( $screens ) as $screen ) {
			add_meta_box(
				'caurisflux_order_metabox',
				__( 'CaurisFlux Payment', 'caurisflux-for-woocommerce' ),
				array( __CLASS__, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	public static function render( $post_or_order ): void {
		$order = ( $post_or_order instanceof WP_Post )
			? wc_get_order( $post_or_order->ID )
			: $post_or_order;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'caurisflux' !== $order->get_payment_method() ) {
			echo '<p class="cflux-meta-empty">';
			echo esc_html__( 'Cette commande n\'utilise pas CaurisFlux.', 'caurisflux-for-woocommerce' );
			echo '</p>';
			return;
		}

		$tx_id    = (string) $order->get_meta( self::META_TX_ID );
		$provider = (string) $order->get_meta( self::META_PROVIDER );
		$env      = (string) $order->get_meta( self::META_ENV );
		$status   = $order->get_status();

		$status_pill_class = self::status_class( $status );
		$status_label      = wc_get_order_status_name( $status );
		?>
		<div class="cflux-meta">
			<?php if ( 'sandbox' === $env ) : ?>
				<div class="cflux-meta-badge cflux-meta-badge-sandbox">
					<?php esc_html_e( 'SANDBOX', 'caurisflux-for-woocommerce' ); ?>
				</div>
			<?php elseif ( 'live' === $env ) : ?>
				<div class="cflux-meta-badge cflux-meta-badge-live">
					<?php esc_html_e( 'LIVE', 'caurisflux-for-woocommerce' ); ?>
				</div>
			<?php endif; ?>

			<div class="cflux-meta-row">
				<span class="cflux-meta-label"><?php esc_html_e( 'Statut', 'caurisflux-for-woocommerce' ); ?></span>
				<span class="cflux-meta-pill <?php echo esc_attr( $status_pill_class ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</div>

			<?php if ( '' !== $tx_id ) : ?>
				<div class="cflux-meta-row">
					<span class="cflux-meta-label"><?php esc_html_e( 'Transaction ID', 'caurisflux-for-woocommerce' ); ?></span>
					<code class="cflux-meta-code"><?php echo esc_html( $tx_id ); ?></code>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $provider ) : ?>
				<div class="cflux-meta-row">
					<span class="cflux-meta-label"><?php esc_html_e( 'Provider', 'caurisflux-for-woocommerce' ); ?></span>
					<span class="cflux-meta-value"><?php echo esc_html( $provider ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( in_array( $status, array( 'pending', 'on-hold' ), true ) && '' !== $tx_id ) : ?>
				<div class="cflux-meta-actions">
					<button
						type="button"
						class="button button-secondary cflux-check-status-btn"
						data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
					>
						<?php esc_html_e( 'Vérifier le statut', 'caurisflux-for-woocommerce' ); ?>
					</button>
					<span class="cflux-check-status-result"></span>
				</div>
				<?php self::render_inline_js(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Inline JS pour le bouton "Vérifier le statut" — un seul appel AJAX
	 * et reload de la page si le statut a changé.
	 */
	private static function render_inline_js(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		(function(){
			document.addEventListener('click', function(e){
				var btn = e.target && e.target.closest && e.target.closest('.cflux-check-status-btn');
				if (!btn) return;
				e.preventDefault();
				var orderId = btn.getAttribute('data-order-id');
				var nonce = btn.getAttribute('data-nonce');
				var result = btn.parentElement.querySelector('.cflux-check-status-result');
				btn.disabled = true;
				if (result) result.textContent = '<?php echo esc_js( __( 'Vérification…', 'caurisflux-for-woocommerce' ) ); ?>';
				var data = new FormData();
				data.append('action', 'caurisflux_check_status');
				data.append('order_id', orderId);
				data.append('_wpnonce', nonce);
				fetch('<?php echo esc_url_raw( $ajax_url ); ?>', { method: 'POST', credentials: 'same-origin', body: data })
					.then(function(r){ return r.json().then(function(j){ return [r.ok, j]; }); })
					.then(function(arr){
						btn.disabled = false;
						if (result) {
							result.textContent = arr[1] && arr[1].data && arr[1].data.message
								? arr[1].data.message
								: (arr[0] ? '<?php echo esc_js( __( 'OK', 'caurisflux-for-woocommerce' ) ); ?>' : '<?php echo esc_js( __( 'Erreur', 'caurisflux-for-woocommerce' ) ); ?>');
						}
						// Reload après 800ms si le statut a changé.
						if (arr[0] && arr[1] && arr[1].data && arr[1].data.status_changed) {
							setTimeout(function(){ window.location.reload(); }, 800);
						}
					})
					.catch(function(err){
						btn.disabled = false;
						if (result) result.textContent = String(err);
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX handler — répond en JSON.
	 *  - { success: true, data: { status, message, status_changed } }
	 *  - { success: false, data: { message } }
	 */
	public static function ajax_check_status(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'caurisflux-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION );

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'caurisflux-for-woocommerce' ) ), 404 );
		}

		$tx_id = (string) $order->get_meta( self::META_TX_ID );
		if ( '' === $tx_id ) {
			wp_send_json_error( array( 'message' => __( 'Aucune transaction CaurisFlux associée.', 'caurisflux-for-woocommerce' ) ) );
		}

		$client = CaurisFlux_Client::from_settings();
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'Configuration CaurisFlux invalide.', 'caurisflux-for-woocommerce' ) ) );
		}

		$response = $client->get_payment_status( $tx_id );
		if ( ! $response['success'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s = error */
					__( 'CaurisFlux : échec vérification statut (%s).', 'caurisflux-for-woocommerce' ),
					$response['error'] ?? 'unknown'
				)
			);
			wp_send_json_error( array( 'message' => $response['error'] ?? __( 'Erreur API', 'caurisflux-for-woocommerce' ) ) );
		}

		$payload = $response['data']['data'] ?? $response['data'];
		$status  = strtolower( (string) ( $payload['status'] ?? '' ) );
		$message = (string) ( $payload['message'] ?? '' );

		$status_changed   = false;
		$response_message = sprintf(
			/* translators: %s = status */
			__( 'Statut: %s', 'caurisflux-for-woocommerce' ),
			strtoupper( $status )
		);

		if ( in_array( $status, array( 'success', 'completed', 'paid' ), true ) ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $tx_id );
				$order->add_order_note(
					sprintf(
						/* translators: %s = message */
						__( 'CaurisFlux : statut vérifié manuellement → SUCCESS. %s', 'caurisflux-for-woocommerce' ),
						$message
					)
				);
				$status_changed   = true;
				$response_message = __( 'Paiement confirmé !', 'caurisflux-for-woocommerce' );
			} else {
				$response_message = __( 'Déjà payée.', 'caurisflux-for-woocommerce' );
			}
		} elseif ( in_array( $status, array( 'failed', 'expired', 'cancelled', 'canceled' ), true ) ) {
			if ( 'failed' !== $order->get_status() ) {
				$order->update_status(
					'failed',
					sprintf(
						/* translators: %1$s = status, %2$s = message */
						__( 'CaurisFlux : statut vérifié manuellement → %1$s. %2$s', 'caurisflux-for-woocommerce' ),
						strtoupper( $status ),
						$message
					)
				);
				$status_changed   = true;
				$response_message = sprintf(
					/* translators: %s = status */
					__( 'Paiement %s.', 'caurisflux-for-woocommerce' ),
					strtoupper( $status )
				);
			}
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %1$s = status, %2$s = message */
					__( 'CaurisFlux : statut actuel = %1$s. %2$s', 'caurisflux-for-woocommerce' ),
					strtoupper( $status ),
					$message
				)
			);
		}

		$order->save();

		wp_send_json_success(
			array(
				'status'         => $status,
				'message'        => $response_message,
				'status_changed' => $status_changed,
			)
		);
	}

	private static function status_class( string $status ): string {
		switch ( $status ) {
			case 'completed':
			case 'processing':
				return 'cflux-pill-success';
			case 'failed':
			case 'cancelled':
				return 'cflux-pill-failed';
			case 'pending':
			case 'on-hold':
				return 'cflux-pill-pending';
			default:
				return 'cflux-pill-neutral';
		}
	}
}
