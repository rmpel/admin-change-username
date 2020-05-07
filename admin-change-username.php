<?php
/*
Plugin Name: Admin Change Username
Plugin URI: https://github.com/rmpel/admin-change-username
Version: 0.0.1
Author: Remon Pel
Description: Allows admins to change usernames
Requires PHP: 5.6.0
Requires at least: 4.0
Textdomain: rmp_acu
*/

namespace RemonPel\Tools;

use WP_Error;

class AdminChangeUsername {

	public static function getInstance() {
		static $instance;
		if ( ! $instance ) {
			$instance = new static();
		}

		return $instance;
	}

	public function __construct() {
		global $pagenow;

		add_action( 'plugins_loaded', function () {
			load_plugin_textdomain( 'rmp_acu', false, plugin_basename( dirname( __FILE__ ) ) . '/pomo' );
		} );

		/** Hook in the 'errors' hook as this is just in time and allows us to return an error in case of conflict */

		add_action( 'user_profile_update_errors', function ( &$errors, $update, &$user ) {
			return call_user_func( [ static::class, 'getInstance' ] )->handle_post( $errors, $update, $user );
		}, PHP_INT_MAX, 3 );

		/** handle redirects of old slugs. This might pose an issue if multiple users have had at some point the same name, as
		 * there will be multiple hits on the old nicename, but so be it, the first found is used to redirect. */
		/** of course, only when needed, a.k.a. when the user-url would result in a 404. */
		add_filter( 'pre_handle_404', function ( $is_404, $query ) {
			return call_user_func( [ static::class, 'getInstance' ] )->handle_redirects( $is_404, $query );
		}, ~PHP_INT_MAX, 2 );

		/** Finally, remove the no-edit restriction */
		add_action( 'admin_footer', [ static::class, 'admin_footer' ] );

		add_action( 'admin_menu', function () {
			add_submenu_page( 'users.php', __( 'Admin Change Username', 'rmp_acu' ), __( 'Admin Change Username', 'rmp_acu' ), 'manage_options', 'rmp_acu', [
				static::class,
				'admin_page_callback'
			] );
		} );
	}


	private function handle_post( &$errors, $update, &$user ) {
		/* only do the work if allowed and needed */
		if ( current_user_can( 'create_users' ) && isset( $_POST['user_login'] ) && $_POST['user_login'] != $user->user_login ) {

			/** this is how WordPress does it */
			$_POST['user_login'] = trim( sanitize_user( $_POST['user_login'] ) );

			/** returns a user ID for an existing user based on this username */
			$exists = username_exists( $_POST['user_login'] );
			if ( $exists && $exists != $user->ID ) {
				/** @var WP_Error $errors */
				$errors->add( 'user_login', __( '<strong>ERROR</strong>: This username is already registered. Please choose another one.' ) );
			} else {
				/** this happens when the username either does not exist, or belongs to the user being edited */

				/** Optional: Auto-Update other fields as well?? */
				//      if ( $user->first_name == $user->user_login ) {
				//        $user->first_name = $_POST['user_login'];
				//      }
				//      if ( $user->last_name == $user->user_login ) {
				//        $user->last_name = $_POST['user_login'];
				//      }
				//      if ( $user->display_name == $user->user_login ) {
				//        $user->display_name = $_POST['user_login'];
				//      }
				//      if ( $user->nickname == $user->user_login ) {
				//        $user->nickname = $_POST['user_login'];
				//      }

				// preserve old user slug, for redirecting
				/** ugly, but sure-fire way to get the user-slug, as WordPress has nasty caching on it */
				add_user_meta( $user->ID, '_old_user_nicename', basename( trim( get_author_posts_url( $user->ID ), '/' ) ) );

				/** set the new username and slug */
				$user->user_login    = $_POST['user_login'];
				$user->user_nicename = sanitize_title( $_POST['user_login'] );

				/** add the user_login to the list of data to save, as this is disabled by default */
				$user_login            = $user->user_login;
				$also_store_user_login = function ( $data ) use ( $user_login ) {
					return $data + array( 'user_login' => $user_login );
				};
				/** add the data */
				add_filter( 'wp_pre_insert_user_data', $also_store_user_login );
				/** save the user */
				wp_update_user( $user );
				/** remove the data, so WordPress is returned to "it's original state" */
				remove_filter( 'wp_pre_insert_user_data', $also_store_user_login );

				// delete current user slug
				/** same ugly way, but it works :) */
				delete_user_meta( $user->ID, '_old_user_nicename', basename( trim( get_author_posts_url( $user->ID ), '/' ) ) );
			}
		}
	}

	private function config( $get, $set = null ) {
		$config = get_option( 'settings_' . static::class, [] );
		if ( ! $config ) {
			$config = [];
		};
		if ( null !== $set ) {
			$config[ $get ] = $set;
			update_option( 'settings_' . static::class, $config );
		}

		return $config[ $get ];
	}


	function handle_redirects( $is_404, $query ) {
		if ( is_author() && $this->config( 'redirect-old-users' ) ) {
			$queried_user_name = get_query_var( 'author_name' );
			$args              = array(
				'meta_query' => array(
					array(
						'key'   => '_old_user_nicename',
						'value' => $queried_user_name,
					),
				)
			);
			$alternatives      = get_users( $args );
			/* there is an old user-slug found? use the first hit and redirect to it */
			if ( $alternatives ) {
				wp_redirect( get_author_posts_url( $alternatives[0]->ID ) );
				exit;
			}
		}

		return $is_404;
	}

	public static function admin_footer() {
		if ( current_user_can( 'create_users' ) ) {
			?>
            <script>
                jQuery("#user_login").prop('disabled', false);
                jQuery("#user_login + .description").remove();
            </script>
			<?php
		}
	}

	// admin panel
	public static function admin_page_callback() {
		global $wpdb;

		if (current_user_can('manage_options')) {

			if ( isset( $_POST ) && isset( $_POST['options'] ) ) {
				$new_settings = $_POST['options'];
				$new_settings = array_filter( $new_settings );
				foreach ($new_settings as $setting => $value) {
					static::getInstance()->config($setting, $value);
				}

				print '<script>document.location=' . json_encode( remove_query_arg( '_' ) ) . ';</script>';
				exit;
			}

		}

		$option_list = [
			'redirect-old-users' => [
				'title'  => __( 'Redirect old user-names?', 'rmp_acu' ),
				'remark' => __( 'If you are changing usernames because of security, do NOT activate this.', 'rmp_acu' )
			]
		];

		?>
        <div class="wrap">
            <h2><?php _e( 'Admin Change Username', 'rmp_acu' ); ?></h2>
            <form action="<?php print esc_attr( add_query_arg( [ '_' => microtime( true ) ] ) ); ?>" method="post">
				<?php foreach ( $option_list as $option => $data ) { ?>
                    <div class="set-wrap">
                        <input id="option-<?php print $option; ?>"
                               type="checkbox" <?php checked('on', static::getInstance()->config($option)); ?>
                               value="on" name="options[<?php print $option; ?>]"/>
                        <label for="option-<?php print $option; ?>"><?php print $data[ 'title' ]; ?></label>
                    </div>
				<?php } ?>
                <button class="button button-primary button-large"><?php _e( 'Save', 'rmp_acu' ); ?></button>
            </form>
        </div>
		<?php
	}
}

AdminChangeUsername::getInstance();
