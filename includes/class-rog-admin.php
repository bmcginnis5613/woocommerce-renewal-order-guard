<?php
defined( 'ABSPATH' ) || exit;

final class ROG_Admin {
	private static $file;

	public static function init( $file ) {
		self::$file = $file;
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_rog_save', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_rog_search_products', array( __CLASS__, 'search' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Renewal Order Guard', 'Renewal Order Guard', 'manage_woocommerce', 'renewal-order-guard', array( __CLASS__, 'page' ) );
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! class_exists( 'WC_Subscriptions_Payment_Gateways' ) || ! class_exists( 'WC_Subscription' ) ) {
			echo '<div class="notice notice-error"><p>Renewal Order Guard requires WooCommerce and WooCommerce Subscriptions to be active.</p></div>';
		} elseif ( 'yes' === ROG_Guard::settings()['enabled'] && ! ROG_Guard::installed() ) {
			echo '<div class="notice notice-error"><p>Renewal Order Guard could not attach to the WooCommerce Subscriptions renewal callbacks. Protection is inactive. Check your Subscriptions version and other renewal customizations.</p></div>';
		}
	}

	public static function assets( $hook ) {
		if ( 'woocommerce_page_renewal-order-guard' !== $hook || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION );
		wp_enqueue_style( 'select2' );
		wp_enqueue_script( 'rog-admin', plugins_url( 'assets/admin.js', self::$file ), array( 'jquery', 'selectWoo' ), '1.0.0', true );
		wp_localize_script( 'rog-admin', 'rogAdmin', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'rog-search' ) ) );
	}

	private static function valid_product( $product ) {
		return $product && $product->is_type( array( 'subscription', 'variable-subscription', 'subscription_variation' ) );
	}

	public static function search() {
		check_ajax_referer( 'rog-search', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WC_Data_Store' ) ) {
			wp_send_json_error( array( 'message' => 'Not permitted.' ), 403 );
		}
		$term = isset( $_GET['term'] ) ? wc_clean( wp_unslash( $_GET['term'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json( array( 'results' => array() ) );
		}
		$ids = WC_Data_Store::load( 'product' )->search_products( $term, '', true, false, 100 );
		if ( ctype_digit( $term ) ) {
			array_unshift( $ids, absint( $term ) );
		}
		$results = array();
		foreach ( array_unique( $ids ) as $id ) {
			$product = wc_get_product( $id );
			if ( self::valid_product( $product ) && 'trash' !== $product->get_status() ) {
				$results[] = array( 'id' => $id, 'text' => wp_strip_all_tags( $product->get_formatted_name() ) );
			}
		}
		wp_send_json( array( 'results' => $results ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			wp_die( 'Not permitted.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rog-save' );
		$products = array();
		foreach ( array_unique( array_map( 'absint', (array) ( $_POST['products'] ?? array() ) ) ) as $id ) {
			if ( self::valid_product( wc_get_product( $id ) ) ) {
				$products[] = $id;
			}
		}
		$statuses = array_values( array_intersect( array( 'pending', 'on-hold', 'failed' ), array_map( 'sanitize_key', (array) ( $_POST['statuses'] ?? array() ) ) ) );
		update_option( ROG_Guard::OPTION, array( 'enabled' => isset( $_POST['enabled'] ) ? 'yes' : 'no', 'products' => $products, 'statuses' => $statuses ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=renewal-order-guard&saved=1' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$options = ROG_Guard::settings();
		?>
		<div class="wrap">
			<h1>Renewal Order Guard</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
			<p>Prevent a new scheduled renewal order when an unpaid renewal order already exists for the same subscription.</p>
			<?php if ( ! function_exists( 'wc_get_product' ) ) : ?><p>Activate WooCommerce and WooCommerce Subscriptions to configure this plugin.</p></div><?php return; endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rog_save">
				<?php wp_nonce_field( 'rog-save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Protection</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( 'yes', $options['enabled'] ); ?>> Enable duplicate renewal protection</label></td></tr>
					<tr><th scope="row"><label for="rog-products">Subscription products / variations</label></th><td>
						<select id="rog-products" name="products[]" multiple style="width: min(100%, 650px)">
						<?php foreach ( $options['products'] as $id ) : $product = wc_get_product( $id ); ?>
							<option value="<?php echo esc_attr( $id ); ?>" selected><?php echo esc_html( $product ? wp_strip_all_tags( $product->get_formatted_name() ) : 'Deleted product #' . $id ); ?></option>
						<?php endforeach; ?>
						</select>
						<p class="description">Search by name, SKU, or product ID. Select a variable subscription parent to cover every variation, or choose individual variations. An empty selection protects nothing.</p>
					</td></tr>
					<tr><th scope="row">Open order statuses</th><td>
					<?php foreach ( array( 'pending' => 'Pending payment', 'on-hold' => 'On hold', 'failed' => 'Failed' ) as $status => $label ) : ?>
						<label style="display:block;margin-bottom:8px"><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, $options['statuses'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<p class="description">Paid, Processing, Completed, Cancelled, and Refunded orders never block a renewal.</p>
					</td></tr>
				</table>
				<p><strong>When the payment is due:</strong> the existing open renewal order remains available for payment, the subscription is put On hold, and no new order or scheduled charge is initiated. Normal Subscriptions payment handling resumes when the existing order is paid. No billing dates are moved by this plugin.</p>
				<p>This also covers open renewal orders created before installation. It checks linked renewal orders, including early renewals, rather than unrelated orders from the same customer. If one item matches, protection applies to the entire subscription renewal.</p>
				<p>Protection covers renewals scheduled by WooCommerce Subscriptions. Gateways that schedule payments themselves, separate payment retries, and orders created directly by other plugins are outside its scope. If an open order is cancelled instead of paid, review and reschedule the subscription manually.</p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
