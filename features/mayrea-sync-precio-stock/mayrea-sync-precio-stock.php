<?php
/**
 * Plugin Name:       Mayrea Sync Precio y Stock
 * Description:       Sincroniza SKU, precio y stock de WooCommerce desde la API de StProductos. Solo actualiza productos existentes; no crea ni elimina productos. Programado 4 veces al día (00:00, 06:00, 12:00, 18:00 ART).
 * Version:           1.0.0
 * Author:            Mayrea
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       mayrea-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'MAYREA_SYNC_VERSION', '1.0.0' );
define( 'MAYREA_SYNC_API_URL', 'https://aguilaapi.com/api/StProductos/sitio/dwaguila3g_mayrea/stock' );
define( 'MAYREA_SYNC_HOOK_RUN', 'mayrea_sync_run' );
define( 'MAYREA_SYNC_HOOK_BATCH', 'mayrea_sync_batch' );
define( 'MAYREA_SYNC_GROUP', 'mayrea_sync' );
define( 'MAYREA_SYNC_MIN_RECORDS', 1000 );
define( 'MAYREA_SYNC_BATCH_SIZE', 300 );
define( 'MAYREA_SYNC_TIME_BUDGET', 15 );
define( 'MAYREA_SYNC_SNAPSHOT_TTL', 3 * HOUR_IN_SECONDS );

const MAYREA_SYNC_OPT_RUN    = '_mayrea_sync_current';
const MAYREA_SYNC_OPT_LAST   = '_mayrea_sync_last_run';
const MAYREA_SYNC_OPT_ERROR  = '_mayrea_sync_last_error';
const MAYREA_SYNC_OPT_LOCK   = '_mayrea_sync_running';
const MAYREA_SYNC_OPT_CHECK  = '_mayrea_sync_sched_check';
const MAYREA_SYNC_TRANSIENT  = '_mayrea_sync_snapshot';

/* -------------------------------------------------------------------------
 * Programación (Action Scheduler): 4 corridas diarias, ancladas a 00:00 ART.
 * ---------------------------------------------------------------------- */

function mayrea_sync_next_slot() {
	$now   = new DateTimeImmutable( 'now', wp_timezone() );
	$slots = array( 0, 6, 12, 18 );

	foreach ( $slots as $hour ) {
		$candidate = $now->setTime( $hour, 0, 0 );
		if ( $candidate > $now ) {
			return $candidate->getTimestamp();
		}
	}

	return $now->modify( 'tomorrow' )->setTime( 0, 0, 0 )->getTimestamp();
}

function mayrea_sync_schedule() {
	if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
		return false;
	}
	if ( as_has_scheduled_action( MAYREA_SYNC_HOOK_RUN ) ) {
		return true;
	}

	as_schedule_recurring_action(
		mayrea_sync_next_slot(),
		6 * HOUR_IN_SECONDS,
		MAYREA_SYNC_HOOK_RUN,
		array(),
		MAYREA_SYNC_GROUP
	);

	return true;
}

function mayrea_sync_maybe_self_heal() {
	$last = (int) get_option( MAYREA_SYNC_OPT_CHECK, 0 );
	if ( ( time() - $last ) < HOUR_IN_SECONDS ) {
		return;
	}
	update_option( MAYREA_SYNC_OPT_CHECK, time(), false );
	mayrea_sync_schedule();
}

add_action( 'init', 'mayrea_sync_maybe_self_heal', 20 );

register_activation_hook( __FILE__, 'mayrea_sync_schedule' );

/* -------------------------------------------------------------------------
 * Utilidades
 * ---------------------------------------------------------------------- */

function mayrea_sync_log( $message, $level = 'info' ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => 'mayrea-sync' ) );
	}
}

function mayrea_sync_set_error( $message ) {
	update_option( MAYREA_SYNC_OPT_ERROR, array( 'time' => time(), 'message' => $message ), false );
	mayrea_sync_log( $message, 'error' );
}

function mayrea_sync_is_running() {
	$lock = (int) get_option( MAYREA_SYNC_OPT_LOCK, 0 );
	return ( time() - $lock ) < HOUR_IN_SECONDS;
}

/* -------------------------------------------------------------------------
 * Corrida: descarga la API y deja un snapshot; luego encadena lotes.
 * ---------------------------------------------------------------------- */

function mayrea_sync_run() {
	if ( mayrea_sync_is_running() ) {
		return;
	}

	update_option( MAYREA_SYNC_OPT_LOCK, time(), false );

	$response = wp_remote_get(
		MAYREA_SYNC_API_URL,
		array(
			'timeout'    => 60,
			'blocking'   => true,
			'sslverify'  => false,
			'user-agent' => 'MayreaSync/' . MAYREA_SYNC_VERSION,
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		mayrea_sync_set_error( 'No se pudo consultar la API: ' . $response->get_error_message() );
		delete_option( MAYREA_SYNC_OPT_LOCK );
		return;
	}

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		mayrea_sync_set_error( 'La API respondió HTTP ' . wp_remote_retrieve_response_code( $response ) );
		delete_option( MAYREA_SYNC_OPT_LOCK );
		return;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) || count( $data ) < MAYREA_SYNC_MIN_RECORDS ) {
		mayrea_sync_set_error(
			'Payload inválido o demasiado pequeño (' . ( is_array( $data ) ? count( $data ) : 'no es array' ) . ' registros). Sincronización abortada para evitar dañar la tienda.'
		);
		delete_option( MAYREA_SYNC_OPT_LOCK );
		return;
	}

	$items   = array();
	$invalid = 0;

	foreach ( $data as $row ) {
		$code = isset( $row['codigoStProducto'] ) ? trim( (string) $row['codigoStProducto'] ) : '';

		if ( '' === $code ) {
			$invalid++;
			continue;
		}

		$price = isset( $row['precioL4StConIva'] ) ? $row['precioL4StConIva'] : null;
		$stock = isset( $row['wStock'] ) ? $row['wStock'] : null;

		if ( ! is_numeric( $price ) || ! is_numeric( $stock ) ) {
			$invalid++;
			continue;
		}

		$items[ $code ] = array(
			'price' => round( (float) $price, 2 ),
			'stock' => max( 0, (int) $stock ),
		);
	}

	if ( count( $items ) < MAYREA_SYNC_MIN_RECORDS ) {
		mayrea_sync_set_error( 'Menos de ' . MAYREA_SYNC_MIN_RECORDS . ' registros válidos tras normalizar. Sincronización abortada.' );
		delete_option( MAYREA_SYNC_OPT_LOCK );
		return;
	}

	set_transient( MAYREA_SYNC_TRANSIENT, $items, MAYREA_SYNC_SNAPSHOT_TTL );

	update_option(
		MAYREA_SYNC_OPT_RUN,
		array(
			'started'          => time(),
			'api_total'        => count( $items ),
			'api_invalid'      => $invalid,
			'offset'           => 0,
			'processed'        => 0,
			'saved'            => 0,
			'price_updates'    => 0,
			'stock_updates'    => 0,
			'status_updates'   => 0,
			'unchanged'        => 0,
			'unmatched'        => 0,
			'unmatched_sample' => array(),
			'errors'           => 0,
			'batches'          => 0,
		),
		false
	);

	mayrea_sync_log( 'Corrida iniciada: ' . count( $items ) . ' registros en la API.' );

	as_schedule_async_action( MAYREA_SYNC_HOOK_BATCH, array( 0 ), MAYREA_SYNC_GROUP );
}

/* -------------------------------------------------------------------------
 * Lote: procesa un tramo del snapshot respetando presupuesto de tiempo.
 * ---------------------------------------------------------------------- */

function mayrea_sync_sku_map() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT p.ID AS id, pm.meta_value AS sku
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		 WHERE pm.meta_key = '_sku'
		   AND p.post_type = 'product'
		   AND p.post_status <> 'trash'",
		ARRAY_A
	);

	$map = array();

	foreach ( (array) $rows as $row ) {
		$key = trim( (string) $row['sku'] );
		if ( '' === $key ) {
			continue;
		}
		$map[ $key ][] = (int) $row['id'];
	}

	return $map;
}

function mayrea_sync_batch( $offset = 0 ) {
	$stats = get_option( MAYREA_SYNC_OPT_RUN );

	if ( ! is_array( $stats ) ) {
		delete_transient( MAYREA_SYNC_TRANSIENT );
		delete_option( MAYREA_SYNC_OPT_LOCK );
		return;
	}

	$items = get_transient( MAYREA_SYNC_TRANSIENT );

	if ( ! is_array( $items ) || empty( $items ) ) {
		mayrea_sync_set_error( 'Snapshot expirado; la corrida se interrumpe. Se retomará en la próxima programación.' );
		delete_option( MAYREA_SYNC_OPT_LOCK );
		return;
	}

	$offset    = max( 0, (int) $offset );
	$chunk     = array_slice( $items, $offset, MAYREA_SYNC_BATCH_SIZE, true );
	$sku_map   = mayrea_sync_sku_map();
	$started   = microtime( true );
	$processed = 0;
	$n         = 0;

	foreach ( $chunk as $code => $item ) {
		$code  = (string) $code;
		$ids   = isset( $sku_map[ $code ] ) ? $sku_map[ $code ] : array();
		$processed++;
		$n++;

		if ( empty( $ids ) ) {
			$stats['unmatched']++;
			if ( count( $stats['unmatched_sample'] ) < 30 && ! in_array( $code, $stats['unmatched_sample'], true ) ) {
				$stats['unmatched_sample'][] = $code;
			}
			continue;
		}

		foreach ( $ids as $product_id ) {
			try {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					$stats['errors']++;
					continue;
				}

				$changed = false;

				// --- Precio (precioL4StConIva) ---
				$price = $item['price'];

				if ( abs( (float) $product->get_regular_price() - $price ) > 0.005 ) {
					$product->set_regular_price( $price );
					$changed              = true;
					$stats['price_updates']++;
				}

				$sale = $product->get_sale_price();
				$on_sale = is_numeric( $sale ) && (float) $sale > 0;

				if ( ! $on_sale && abs( (float) $product->get_price() - $price ) > 0.005 ) {
					$product->set_price( $price );
					$changed = true;
				}

				// --- Stock (wStock) ---
				$stock = $item['stock'];
				$current_stock = $product->get_stock_quantity();

				if ( null === $current_stock || abs( (float) $current_stock - $stock ) > 0.005 ) {
					$product->set_stock_quantity( $stock );
					$changed              = true;
					$stats['stock_updates']++;
				}

				// --- Estado: wStock > 0 => instock, 0 => agotado ---
				$status = $stock > 0 ? 'instock' : 'outofstock';

				if ( $product->get_stock_status() !== $status ) {
					$product->set_stock_status( $status );
					$changed                = true;
					$stats['status_updates']++;
				}

				// --- SKU (codigoStProducto) solo si difiere ---
				if ( trim( (string) $product->get_sku() ) !== $code ) {
					$product->set_sku( $code );
					$changed = true;
				}

				if ( $changed ) {
					$product->save();
					$stats['saved']++;
				} else {
					$stats['unchanged']++;
				}
			} catch ( Throwable $e ) {
				$stats['errors']++;
			}
		}

		if ( 0 === ( $n % 5 ) && ( microtime( true ) - $started ) > MAYREA_SYNC_TIME_BUDGET ) {
			break;
		}
	}

	$offset             += $processed;
	$stats['offset']     = $offset;
	$stats['processed']  = $stats['processed'] + $processed;
	$stats['batches']++;

	update_option( MAYREA_SYNC_OPT_RUN, $stats, false );

	if ( $offset < count( $items ) ) {
		as_schedule_async_action( MAYREA_SYNC_HOOK_BATCH, array( $offset ), MAYREA_SYNC_GROUP );
		return;
	}

	mayrea_sync_finalize( $stats );
}

function mayrea_sync_finalize( $stats ) {
	$stats['finished'] = time();
	$stats['duration'] = $stats['finished'] - $stats['started'];

	update_option( MAYREA_SYNC_OPT_LAST, $stats, false );
	delete_option( MAYREA_SYNC_OPT_RUN );
	delete_transient( MAYREA_SYNC_TRANSIENT );
	delete_option( MAYREA_SYNC_OPT_LOCK );

	mayrea_sync_log(
		'Corrida finalizada en ' . $stats['duration'] . 's: '
		. $stats['saved'] . ' productos guardados, '
		. $stats['price_updates'] . ' precios, '
		. $stats['stock_updates'] . ' stocks, '
		. $stats['unmatched'] . ' SKUs sin match, '
		. $stats['errors'] . ' errores.'
	);

	if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
		LiteSpeed_Cache_API::purge_all( 'mayrea_sync' );
	}
}

add_action( MAYREA_SYNC_HOOK_RUN, 'mayrea_sync_run' );
add_action( MAYREA_SYNC_HOOK_BATCH, 'mayrea_sync_batch', 10, 1 );

/* -------------------------------------------------------------------------
 * Panel de estado en el admin (WooCommerce > Sync Precio y Stock).
 * ---------------------------------------------------------------------- */

function mayrea_sync_admin_menu() {
	add_submenu_page(
		'woocommerce',
		'Sync Precio y Stock',
		'Sync Precio y Stock',
		'manage_woocommerce',
		'mayrea-sync',
		'mayrea_sync_render_page'
	);
}

add_action( 'admin_menu', 'mayrea_sync_admin_menu' );

function mayrea_sync_render_page() {
	$last  = get_option( MAYREA_SYNC_OPT_LAST, array() );
	$error = get_option( MAYREA_SYNC_OPT_ERROR, array() );
	$queue = isset( $_GET['mayrea_sync'] ) && 'queued' === $_GET['mayrea_sync']; // phpcs:ignore

	$next = function_exists( 'as_next_scheduled_action' )
		? as_next_scheduled_action( MAYREA_SYNC_HOOK_RUN )
		: false;
	?>
	<div class="wrap">
		<h1>Sync Precio y Stock</h1>

		<?php if ( $queue ) : ?>
			<div class="notice notice-success is-dismissible"><p>Sincronización encolada. Se ejecutará en unos segundos.</p></div>
		<?php endif; ?>

		<?php if ( ! empty( $error ) ) : ?>
			<div class="notice notice-error">
				<p><strong>Último error</strong> (<?php echo esc_html( date_i18n( 'd/m/Y H:i', $error['time'] ) ); ?>):
				<?php echo esc_html( $error['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:760px">
			<tbody>
				<tr>
					<td><strong>Estado</strong></td>
					<td>
						<?php if ( mayrea_sync_is_running() ) : ?>
							En curso...
						<?php elseif ( false === $next ) : ?>
							No programada (¿plugin activo?)
						<?php else : ?>
							Programada — próxima: <?php echo esc_html( date_i18n( 'd/m/Y H:i', (int) $next ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $last ) ) : ?>
					<tr><td><strong>Última corrida</strong></td><td><?php echo esc_html( date_i18n( 'd/m/Y H:i:s', $last['finished'] ) ); ?> (<?php echo (int) $last['duration']; ?>s)</td></tr>
					<tr><td><strong>Registros API</strong></td><td><?php echo (int) $last['api_total']; ?><?php echo ! empty( $last['api_invalid'] ) ? ' (' . (int) $last['api_invalid'] . ' inválidos)' : ''; ?></td></tr>
					<tr><td><strong>Productos procesados</strong></td><td><?php echo (int) $last['processed']; ?></td></tr>
					<tr><td><strong>Precios actualizados</strong></td><td><?php echo (int) $last['price_updates']; ?></td></tr>
					<tr><td><strong>Stocks actualizados</strong></td><td><?php echo (int) $last['stock_updates']; ?></td></tr>
					<tr><td><strong>Estados actualizados</strong></td><td><?php echo (int) $last['status_updates']; ?></td></tr>
					<tr><td><strong>Sin cambios</strong></td><td><?php echo (int) $last['unchanged']; ?></td></tr>
					<tr><td><strong>SKUs sin contraparte en la API</strong></td><td><?php echo (int) $last['unmatched']; ?></td></tr>
					<tr><td><strong>Errores</strong></td><td><?php echo (int) $last['errors']; ?></td></tr>
					<?php if ( ! empty( $last['unmatched_sample'] ) ) : ?>
						<tr>
							<td><strong>Ejemplos sin match</strong></td>
							<td style="word-break:break-all"><?php echo esc_html( implode( ', ', $last['unmatched_sample'] ) ); ?></td>
						</tr>
					<?php endif; ?>
				<?php else : ?>
					<tr><td colspan="2">Todavía no se ejecutó ninguna corrida.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<p class="description">Mapeo: <code>codigoStProducto → SKU</code>, <code>precioL4StConIva → Precio</code>, <code>wStock → Stock</code> (0 = Agotado). Solo se actualizan productos existentes: no se crean ni eliminan productos.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mayrea_sync_trigger">
			<?php wp_nonce_field( 'mayrea_sync_trigger' ); ?>
			<?php submit_button( 'Ejecutar sincronización ahora', 'primary', 'submit', false, mayrea_sync_is_running() ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
	</div>
	<?php
}

function mayrea_sync_trigger() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos.' );
	}
	check_admin_referer( 'mayrea_sync_trigger' );

	if ( ! function_exists( 'as_schedule_async_action' ) ) {
		wp_die( 'Action Scheduler no está disponible.' );
	}

	if ( ! mayrea_sync_is_running() ) {
		as_schedule_async_action( MAYREA_SYNC_HOOK_RUN, array(), MAYREA_SYNC_GROUP );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'mayrea-sync', 'mayrea_sync' => 'queued' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_mayrea_sync_trigger', 'mayrea_sync_trigger' );

/* -------------------------------------------------------------------------
 * WP-CLI: estado (solo consulta; no ejecuta sincronizaciones).
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {

	class Mayrea_Sync_CLI {

		/**
		 * Muestra el estado de la última sincronización.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mayrea-sync status
		 */
		public function status( $args, $assoc_args ) {
			$last  = get_option( MAYREA_SYNC_OPT_LAST, array() );
			$error = get_option( MAYREA_SYNC_OPT_ERROR, array() );

			if ( empty( $last ) ) {
				WP_CLI::log( 'Todavía no se ejecutó ninguna corrida.' );
			} else {
				foreach ( $last as $key => $value ) {
					if ( 'unmatched_sample' === $key ) {
						$value = implode( ', ', (array) $value );
					} elseif ( ! is_scalar( $value ) ) {
						$value = wp_json_encode( $value );
					}
					WP_CLI::log( $key . ': ' . $value );
				}
			}

			if ( ! empty( $error ) ) {
				WP_CLI::warning( date_i18n( 'd/m/Y H:i', $error['time'] ) . ' — ' . $error['message'] );
			}
		}
	}

	WP_CLI::add_command( 'mayrea-sync', 'Mayrea_Sync_CLI' );
}
