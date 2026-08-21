<?php
/**
 * Plugin Name: Harvey Plum Jetpack Popular Posts Controls
 * Plugin URI: https://harveyplum.com
 * Description: Adds a GUI for Jetpack Top Posts & Pages filters, featured image styling, item metadata, and display tweaks.
 * Version: 1.0.1
 * Author: Harvey Plum
 * Author URI: https://harveyplum.com
 * GitHub Plugin URI: https://github.com/HarveyPlum/harvey-plum-jetpack-popular-posts
 * Update URI: https://github.com/HarveyPlum/harvey-plum-jetpack-popular-posts
 * Primary Branch: main
 * Release Asset: true
 * License: GPL-2.0-or-later
 * Text Domain: hp-jetpack-popular-posts
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package HarveyPlum\JetpackPopularPosts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HP_JPPC_VERSION', '1.0.1' );
define( 'HP_JPPC_OPTION', 'hp_jppc_settings' );

/**
 * Main plugin class.
 */
final class HP_Jetpack_Popular_Posts_Controls {
	/**
	 * Settings for the Jetpack Top Posts widget currently being rendered.
	 *
	 * @var array|null
	 */
	private static $active_widget_settings = null;

	/**
	 * Current widget wrapper ID, when WordPress provides one.
	 *
	 * @var string
	 */
	private static $active_widget_id = '';

	/**
	 * Widget IDs that already received scoped inline CSS during this request.
	 *
	 * @var array
	 */
	private static $printed_widget_styles = array();

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_action_links' ) );

		add_action( 'jetpack_widget_top_posts_after_fields', array( __CLASS__, 'render_widget_fields' ), 20 );
		add_filter( 'jetpack_top_posts_saving', array( __CLASS__, 'save_widget_fields' ), 10, 2 );
		add_filter( 'widget_display_callback', array( __CLASS__, 'capture_widget_instance' ), 10, 3 );

		add_filter( 'jetpack_top_posts_days', array( __CLASS__, 'filter_top_posts_days' ), 10, 2 );
		add_filter( 'jetpack_top_posts_widget_count', array( __CLASS__, 'filter_widget_count' ) );
		add_filter( 'jetpack_top_posts_widget_image_options', array( __CLASS__, 'filter_image_options' ) );
		add_filter( 'jetpack_widget_get_top_posts', array( __CLASS__, 'filter_posts' ), 10, 3 );
		add_filter( 'jetpack_top_posts_widget_permalink', array( __CLASS__, 'filter_permalink' ), 10, 2 );

		add_action( 'jetpack_widget_top_posts_before_post', array( __CLASS__, 'render_before_post' ) );
		add_action( 'jetpack_widget_top_posts_after_post', array( __CLASS__, 'render_after_post' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_css' ) );
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'days'                  => 2,
			'count_override'        => 0,
			'min_age_days'          => '',
			'max_age_days'          => '',
			'allowed_categories'    => array(),
			'allowed_tags'          => array(),
			'excluded_post_ids'     => '',
			'hide_current_post'     => 0,
			'before_enabled'        => 0,
			'before_content'        => '',
			'after_enabled'         => 0,
			'after_content'         => '',
			'image_preset'          => 'default',
			'image_aspect_ratio'    => 'default',
			'image_position'        => 'center center',
			'image_spacing'         => 'default',
			'image_radius'          => 'default',
			'image_border'          => 'none',
			'image_border_color'    => '#d0d7de',
			'image_shadow'          => 'none',
			'image_hover'           => 'none',
			'fallback_to_avatars'   => 1,
			'default_image_url'     => '',
			'utm_source'            => '',
			'utm_medium'            => '',
			'utm_campaign'          => '',
			'custom_css'            => '',
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	private static function settings() {
		$saved = get_option( HP_JPPC_OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Get settings for the currently rendering widget, falling back to site defaults.
	 *
	 * @return array
	 */
	private static function active_settings() {
		if ( is_array( self::$active_widget_settings ) ) {
			return self::$active_widget_settings;
		}

		return self::settings();
	}

	/**
	 * Get plugin settings stored on a widget instance.
	 *
	 * @param array $instance Widget instance.
	 * @return array
	 */
	private static function settings_from_instance( $instance ) {
		$settings = self::settings();

		if ( isset( $instance['hp_jppc'] ) && is_array( $instance['hp_jppc'] ) ) {
			$settings = wp_parse_args( $instance['hp_jppc'], $settings );
		}

		return wp_parse_args( $settings, self::defaults() );
	}

	/**
	 * Add Settings page link from the Plugins table.
	 *
	 * @param array $links Plugin action links.
	 * @return array
	 */
	public static function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=hp-jetpack-popular-posts' ) ),
			esc_html__( 'Settings', 'hp-jetpack-popular-posts' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register the admin page.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'Jetpack Popular Posts', 'hp-jetpack-popular-posts' ),
			__( 'Jetpack Popular Posts', 'hp-jetpack-popular-posts' ),
			'manage_options',
			'hp-jetpack-popular-posts',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings() {
		register_setting(
			'hp_jppc_settings_group',
			HP_JPPC_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param array $input Submitted settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$output   = array();

		$output['days'] = isset( $input['days'] ) ? (int) $input['days'] : $defaults['days'];
		if ( 0 === $output['days'] || $output['days'] < -1 ) {
			$output['days'] = $defaults['days'];
		}

		$output['count_override'] = isset( $input['count_override'] ) ? absint( $input['count_override'] ) : 0;
		if ( $output['count_override'] > 10 ) {
			$output['count_override'] = 10;
		}

		$output['min_age_days'] = self::sanitize_optional_integer( $input, 'min_age_days' );
		$output['max_age_days'] = self::sanitize_optional_integer( $input, 'max_age_days' );

		$output['allowed_categories'] = self::sanitize_terms( $input, 'allowed_categories' );
		$output['allowed_tags']       = self::sanitize_terms( $input, 'allowed_tags' );
		$output['excluded_post_ids']  = self::sanitize_id_list( isset( $input['excluded_post_ids'] ) ? $input['excluded_post_ids'] : '' );
		$output['hide_current_post']  = ! empty( $input['hide_current_post'] ) ? 1 : 0;

		$output['before_enabled'] = ! empty( $input['before_enabled'] ) ? 1 : 0;
		$output['before_content'] = isset( $input['before_content'] ) ? wp_kses_post( $input['before_content'] ) : '';
		$output['after_enabled']  = ! empty( $input['after_enabled'] ) ? 1 : 0;
		$output['after_content']  = isset( $input['after_content'] ) ? wp_kses_post( $input['after_content'] ) : '';

		$output['image_preset']       = self::sanitize_choice( $input, 'image_preset', array_keys( self::image_presets() ), $defaults['image_preset'] );
		$output['image_aspect_ratio'] = self::sanitize_choice( $input, 'image_aspect_ratio', array_keys( self::image_aspect_ratios() ), $defaults['image_aspect_ratio'] );
		$output['image_position']     = self::sanitize_choice( $input, 'image_position', array_keys( self::image_positions() ), $defaults['image_position'] );
		$output['image_spacing']      = self::sanitize_choice( $input, 'image_spacing', array_keys( self::spacing_options() ), $defaults['image_spacing'] );
		$output['image_radius']       = self::sanitize_choice( $input, 'image_radius', array_keys( self::radius_options() ), $defaults['image_radius'] );
		$output['image_border']       = self::sanitize_choice( $input, 'image_border', array_keys( self::border_options() ), $defaults['image_border'] );
		$output['image_border_color'] = isset( $input['image_border_color'] ) ? sanitize_hex_color( $input['image_border_color'] ) : $defaults['image_border_color'];
		if ( empty( $output['image_border_color'] ) ) {
			$output['image_border_color'] = $defaults['image_border_color'];
		}
		$output['image_shadow']        = self::sanitize_choice( $input, 'image_shadow', array_keys( self::shadow_options() ), $defaults['image_shadow'] );
		$output['image_hover']         = self::sanitize_choice( $input, 'image_hover', array_keys( self::hover_options() ), $defaults['image_hover'] );
		$output['fallback_to_avatars'] = ! empty( $input['fallback_to_avatars'] ) ? 1 : 0;
		$output['default_image_url']   = isset( $input['default_image_url'] ) ? esc_url_raw( $input['default_image_url'] ) : '';

		$output['utm_source']   = isset( $input['utm_source'] ) ? sanitize_key( $input['utm_source'] ) : '';
		$output['utm_medium']   = isset( $input['utm_medium'] ) ? sanitize_key( $input['utm_medium'] ) : '';
		$output['utm_campaign'] = isset( $input['utm_campaign'] ) ? sanitize_title( $input['utm_campaign'] ) : '';
		$output['custom_css']   = isset( $input['custom_css'] ) ? wp_strip_all_tags( $input['custom_css'] ) : '';

		return wp_parse_args( $output, $defaults );
	}

	/**
	 * Sanitize a nullable integer field.
	 *
	 * @param array  $input Submitted settings.
	 * @param string $key Setting key.
	 * @return string|int
	 */
	private static function sanitize_optional_integer( $input, $key ) {
		if ( ! isset( $input[ $key ] ) || '' === trim( (string) $input[ $key ] ) ) {
			return '';
		}

		return absint( $input[ $key ] );
	}

	/**
	 * Sanitize a term ID array.
	 *
	 * @param array  $input Submitted settings.
	 * @param string $key Setting key.
	 * @return array
	 */
	private static function sanitize_terms( $input, $key ) {
		if ( empty( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $input[ $key ] ) ) );
	}

	/**
	 * Sanitize a comma-separated post ID list.
	 *
	 * @param string $value Raw list.
	 * @return string
	 */
	private static function sanitize_id_list( $value ) {
		$ids = preg_split( '/[\s,]+/', (string) $value );
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		return implode( ', ', $ids );
	}

	/**
	 * Sanitize a select/radio value.
	 *
	 * @param array  $input Submitted settings.
	 * @param string $key Setting key.
	 * @param array  $choices Allowed values.
	 * @param string $default Default value.
	 * @return string
	 */
	private static function sanitize_choice( $input, $key, $choices, $default ) {
		if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $choices, true ) ) {
			return $input[ $key ];
		}

		return $default;
	}

	/**
	 * Image source presets. These are applied through Jetpack's image options filter.
	 *
	 * @return array
	 */
	private static function image_presets() {
		return array(
			'default'   => array(
				'label'       => __( 'Jetpack default', 'hp-jetpack-popular-posts' ),
				'description' => __( 'Keeps the widget source image size unchanged.', 'hp-jetpack-popular-posts' ),
				'avatar_size' => null,
				'width'       => null,
				'height'      => null,
			),
			'compact'   => array(
				'label'       => __( 'Compact list', 'hp-jetpack-popular-posts' ),
				'description' => __( 'A tidy thumbnail for dense sidebars.', 'hp-jetpack-popular-posts' ),
				'avatar_size' => 96,
				'width'       => 96,
				'height'      => 96,
			),
			'balanced'  => array(
				'label'       => __( 'Balanced card', 'hp-jetpack-popular-posts' ),
				'description' => __( 'A larger image that works well in list layouts.', 'hp-jetpack-popular-posts' ),
				'avatar_size' => 240,
				'width'       => 320,
				'height'      => 180,
			),
			'editorial' => array(
				'label'       => __( 'Editorial wide', 'hp-jetpack-popular-posts' ),
				'description' => __( 'A wide feature image for homepage and article rail treatments.', 'hp-jetpack-popular-posts' ),
				'avatar_size' => 480,
				'width'       => 480,
				'height'      => 270,
			),
			'poster'    => array(
				'label'       => __( 'Poster', 'hp-jetpack-popular-posts' ),
				'description' => __( 'A taller crop for visual directories and media-heavy sidebars.', 'hp-jetpack-popular-posts' ),
				'avatar_size' => 360,
				'width'       => 360,
				'height'      => 480,
			),
		);
	}

	/**
	 * Aspect ratio options.
	 *
	 * @return array
	 */
	private static function image_aspect_ratios() {
		return array(
			'default' => __( 'Use preset crop', 'hp-jetpack-popular-posts' ),
			'square'  => __( 'Square', 'hp-jetpack-popular-posts' ),
			'wide'    => __( 'Wide 16:9', 'hp-jetpack-popular-posts' ),
			'classic' => __( 'Classic 4:3', 'hp-jetpack-popular-posts' ),
			'poster'  => __( 'Poster 3:4', 'hp-jetpack-popular-posts' ),
		);
	}

	/**
	 * Object-position options.
	 *
	 * @return array
	 */
	private static function image_positions() {
		return array(
			'center center' => __( 'Center', 'hp-jetpack-popular-posts' ),
			'center top'    => __( 'Top', 'hp-jetpack-popular-posts' ),
			'center bottom' => __( 'Bottom', 'hp-jetpack-popular-posts' ),
			'left center'   => __( 'Left', 'hp-jetpack-popular-posts' ),
			'right center'  => __( 'Right', 'hp-jetpack-popular-posts' ),
		);
	}

	/**
	 * Spacing options.
	 *
	 * @return array
	 */
	private static function spacing_options() {
		return array(
			'default' => __( 'Theme default', 'hp-jetpack-popular-posts' ),
			'tight'   => __( 'Tight', 'hp-jetpack-popular-posts' ),
			'roomy'   => __( 'Roomy', 'hp-jetpack-popular-posts' ),
			'stacked' => __( 'Stack image above title', 'hp-jetpack-popular-posts' ),
		);
	}

	/**
	 * Radius options.
	 *
	 * @return array
	 */
	private static function radius_options() {
		return array(
			'default' => __( 'Theme default', 'hp-jetpack-popular-posts' ),
			'square'  => __( 'Square', 'hp-jetpack-popular-posts' ),
			'soft'    => __( 'Soft corners', 'hp-jetpack-popular-posts' ),
			'rounded' => __( 'Rounded', 'hp-jetpack-popular-posts' ),
			'circle'  => __( 'Circle', 'hp-jetpack-popular-posts' ),
		);
	}

	/**
	 * Border options.
	 *
	 * @return array
	 */
	private static function border_options() {
		return array(
			'none'   => __( 'None', 'hp-jetpack-popular-posts' ),
			'hair'   => __( 'Hairline', 'hp-jetpack-popular-posts' ),
			'strong' => __( 'Strong', 'hp-jetpack-popular-posts' ),
			'frame'  => __( 'Inset frame', 'hp-jetpack-popular-posts' ),
		);
	}

	/**
	 * Shadow options.
	 *
	 * @return array
	 */
	private static function shadow_options() {
		return array(
			'none'   => __( 'None', 'hp-jetpack-popular-posts' ),
			'subtle' => __( 'Subtle', 'hp-jetpack-popular-posts' ),
			'lifted' => __( 'Lifted', 'hp-jetpack-popular-posts' ),
			'deep'   => __( 'Deep', 'hp-jetpack-popular-posts' ),
		);
	}

	/**
	 * Hover options.
	 *
	 * @return array
	 */
	private static function hover_options() {
		return array(
			'none'   => __( 'None', 'hp-jetpack-popular-posts' ),
			'bright' => __( 'Brighten', 'hp-jetpack-popular-posts' ),
			'zoom'   => __( 'Zoom in', 'hp-jetpack-popular-posts' ),
			'lift'   => __( 'Lift', 'hp-jetpack-popular-posts' ),
		);
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = self::settings();
		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$tags       = get_tags(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		?>
		<div class="wrap hp-jppc-wrap">
			<h1><?php esc_html_e( 'Jetpack Popular Posts Controls', 'hp-jetpack-popular-posts' ); ?></h1>
			<p>
				<?php esc_html_e( 'Configure Jetpack Top Posts & Pages filters without editing theme files.', 'hp-jetpack-popular-posts' ); ?>
			</p>

			<?php if ( ! class_exists( 'Jetpack_Top_Posts_Widget' ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Jetpack Top Posts & Pages does not appear to be loaded. Activate Jetpack Stats and Extra Sidebar Widgets for these controls to affect the widget.', 'hp-jetpack-popular-posts' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'hp_jppc_settings_group' ); ?>

				<div class="hp-jppc-grid">
					<section class="hp-jppc-card">
						<h2><?php esc_html_e( 'Stats Window', 'hp-jetpack-popular-posts' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="hp-jppc-days"><?php esc_html_e( 'Days used to calculate top posts', 'hp-jetpack-popular-posts' ); ?></label>
								</th>
								<td>
									<input id="hp-jppc-days" name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[days]" type="number" min="-1" step="1" value="<?php echo esc_attr( (string) $settings['days'] ); ?>" class="small-text">
									<p class="description"><?php esc_html_e( 'Jetpack defaults to 2. Jetpack recommends 10 days or fewer; use -1 for all-time results.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="hp-jppc-count"><?php esc_html_e( 'Override number shown', 'hp-jetpack-popular-posts' ); ?></label>
								</th>
								<td>
									<input id="hp-jppc-count" name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[count_override]" type="number" min="0" max="10" step="1" value="<?php echo esc_attr( (string) $settings['count_override'] ); ?>" class="small-text">
									<p class="description"><?php esc_html_e( 'Use 0 to keep each widget or shortcode setting. Jetpack caps this at 10.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
						</table>
					</section>

					<section class="hp-jppc-card">
						<h2><?php esc_html_e( 'Post Filters', 'hp-jetpack-popular-posts' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Published age', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<?php esc_html_e( 'At least', 'hp-jetpack-popular-posts' ); ?>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[min_age_days]" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $settings['min_age_days'] ); ?>" class="small-text">
										<?php esc_html_e( 'days old', 'hp-jetpack-popular-posts' ); ?>
									</label>
									<br>
									<label>
										<?php esc_html_e( 'No more than', 'hp-jetpack-popular-posts' ); ?>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[max_age_days]" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $settings['max_age_days'] ); ?>" class="small-text">
										<?php esc_html_e( 'days old', 'hp-jetpack-popular-posts' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Leave blank to allow any publish date. Example: 30 to 60 days old creates a “popular last month” list.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Categories', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<?php self::render_term_checkboxes( 'allowed_categories', $categories, $settings['allowed_categories'] ); ?>
									<p class="description"><?php esc_html_e( 'Leave empty to allow all categories.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Tags', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<?php self::render_term_checkboxes( 'allowed_tags', $tags, $settings['allowed_tags'] ); ?>
									<p class="description"><?php esc_html_e( 'Leave empty to allow all tags.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="hp-jppc-excluded"><?php esc_html_e( 'Excluded post IDs', 'hp-jetpack-popular-posts' ); ?></label>
								</th>
								<td>
									<input id="hp-jppc-excluded" name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[excluded_post_ids]" type="text" value="<?php echo esc_attr( $settings['excluded_post_ids'] ); ?>" class="regular-text" placeholder="123, 456">
									<p class="description"><?php esc_html_e( 'Comma-separated post or page IDs to remove from the results.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Current page', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[hide_current_post]" type="checkbox" value="1" <?php checked( 1, $settings['hide_current_post'] ); ?>>
										<?php esc_html_e( 'Hide the current post or page from its own Top Posts list.', 'hp-jetpack-popular-posts' ); ?>
									</label>
								</td>
							</tr>
						</table>
					</section>

					<section class="hp-jppc-card">
						<h2><?php esc_html_e( 'Before and After Data', 'hp-jetpack-popular-posts' ); ?></h2>
						<p><?php esc_html_e( 'Add safe HTML before or after every item in the widget.', 'hp-jetpack-popular-posts' ); ?></p>
						<p class="description">
							<?php esc_html_e( 'Available tokens: {post_id}, {title}, {date}, {author}, {categories}', 'hp-jetpack-popular-posts' ); ?>
						</p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Before each item', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[before_enabled]" type="checkbox" value="1" <?php checked( 1, $settings['before_enabled'] ); ?>>
										<?php esc_html_e( 'Enable before content', 'hp-jetpack-popular-posts' ); ?>
									</label>
									<textarea name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[before_content]" rows="4" class="large-text code" placeholder="<span class=&quot;hp-jppc-meta&quot;>{date}</span>"><?php echo esc_textarea( $settings['before_content'] ); ?></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'After each item', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[after_enabled]" type="checkbox" value="1" <?php checked( 1, $settings['after_enabled'] ); ?>>
										<?php esc_html_e( 'Enable after content', 'hp-jetpack-popular-posts' ); ?>
									</label>
									<textarea name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[after_content]" rows="4" class="large-text code" placeholder="<span class=&quot;hp-jppc-meta&quot;>By {author}</span>"><?php echo esc_textarea( $settings['after_content'] ); ?></textarea>
								</td>
							</tr>
						</table>
					</section>

					<section class="hp-jppc-card hp-jppc-featured-card">
						<h2><?php esc_html_e( 'Featured Images', 'hp-jetpack-popular-posts' ); ?></h2>
						<p><?php esc_html_e( 'Choose a visual treatment instead of hand-entering dimensions.', 'hp-jetpack-popular-posts' ); ?></p>
						<div class="hp-jppc-preview" aria-hidden="true">
							<div class="hp-jppc-preview-image"></div>
							<div class="hp-jppc-preview-lines">
								<span></span>
								<span></span>
							</div>
						</div>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="hp-jppc-image-preset"><?php esc_html_e( 'Image treatment', 'hp-jetpack-popular-posts' ); ?></label>
								</th>
								<td>
									<select id="hp-jppc-image-preset" name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[image_preset]">
										<?php foreach ( self::image_presets() as $value => $preset ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['image_preset'], $value ); ?>>
												<?php echo esc_html( $preset['label'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php echo esc_html( self::image_presets()[ $settings['image_preset'] ]['description'] ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Crop and focus', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<?php esc_html_e( 'Aspect ratio', 'hp-jetpack-popular-posts' ); ?>
										<?php self::render_select( 'image_aspect_ratio', self::image_aspect_ratios(), $settings['image_aspect_ratio'] ); ?>
									</label>
									<label>
										<?php esc_html_e( 'Focus', 'hp-jetpack-popular-posts' ); ?>
										<?php self::render_select( 'image_position', self::image_positions(), $settings['image_position'] ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Image style', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<?php esc_html_e( 'Corners', 'hp-jetpack-popular-posts' ); ?>
										<?php self::render_select( 'image_radius', self::radius_options(), $settings['image_radius'] ); ?>
									</label>
									<label>
										<?php esc_html_e( 'Border', 'hp-jetpack-popular-posts' ); ?>
										<?php self::render_select( 'image_border', self::border_options(), $settings['image_border'] ); ?>
									</label>
									<label>
										<?php esc_html_e( 'Border color', 'hp-jetpack-popular-posts' ); ?>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[image_border_color]" type="color" value="<?php echo esc_attr( $settings['image_border_color'] ); ?>">
									</label>
									<br>
									<label>
										<?php esc_html_e( 'Shadow', 'hp-jetpack-popular-posts' ); ?>
										<?php self::render_select( 'image_shadow', self::shadow_options(), $settings['image_shadow'] ); ?>
									</label>
									<label>
										<?php esc_html_e( 'Hover', 'hp-jetpack-popular-posts' ); ?>
										<?php self::render_select( 'image_hover', self::hover_options(), $settings['image_hover'] ); ?>
									</label>
									<label>
										<?php esc_html_e( 'Spacing', 'hp-jetpack-popular-posts' ); ?>
										<?php self::render_select( 'image_spacing', self::spacing_options(), $settings['image_spacing'] ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Fallback image', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[fallback_to_avatars]" type="checkbox" value="1" <?php checked( 1, $settings['fallback_to_avatars'] ); ?>>
										<?php esc_html_e( 'Use Jetpack fallback images when posts have no featured image.', 'hp-jetpack-popular-posts' ); ?>
									</label>
									<br>
									<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[default_image_url]" type="url" value="<?php echo esc_attr( $settings['default_image_url'] ); ?>" class="regular-text" placeholder="https://example.com/default.jpg">
									<p class="description"><?php esc_html_e( 'Optional fallback URL. Leave blank to use Jetpack’s default.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
						</table>
					</section>

					<section class="hp-jppc-card">
						<h2><?php esc_html_e( 'Tracking and CSS', 'hp-jetpack-popular-posts' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'UTM parameters', 'hp-jetpack-popular-posts' ); ?></th>
								<td>
									<label>
										<?php esc_html_e( 'Source', 'hp-jetpack-popular-posts' ); ?>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[utm_source]" type="text" value="<?php echo esc_attr( $settings['utm_source'] ); ?>" class="regular-text">
									</label>
									<br>
									<label>
										<?php esc_html_e( 'Medium', 'hp-jetpack-popular-posts' ); ?>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[utm_medium]" type="text" value="<?php echo esc_attr( $settings['utm_medium'] ); ?>" class="regular-text">
									</label>
									<br>
									<label>
										<?php esc_html_e( 'Campaign', 'hp-jetpack-popular-posts' ); ?>
										<input name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[utm_campaign]" type="text" value="<?php echo esc_attr( $settings['utm_campaign'] ); ?>" class="regular-text">
									</label>
									<p class="description"><?php esc_html_e( 'Blank fields are ignored. These are appended to Top Posts links only.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="hp-jppc-css"><?php esc_html_e( 'Custom CSS', 'hp-jetpack-popular-posts' ); ?></label>
								</th>
								<td>
									<textarea id="hp-jppc-css" name="<?php echo esc_attr( HP_JPPC_OPTION ); ?>[custom_css]" rows="5" class="large-text code"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Output on the front end after Jetpack widget styles.', 'hp-jetpack-popular-posts' ); ?></p>
								</td>
							</tr>
						</table>
					</section>
				</div>

				<?php submit_button(); ?>
			</form>

			<footer class="hp-jppc-footer">
				<?php esc_html_e( 'Need support? Email support@harveyplum.com', 'hp-jetpack-popular-posts' ); ?>
			</footer>
		</div>

		<style>
			.hp-jppc-grid {
				display: grid;
				grid-template-columns: minmax(0, 1fr);
				gap: 16px;
				max-width: 1180px;
			}

			.hp-jppc-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 8px 20px 16px;
			}

			.hp-jppc-card h2 {
				margin-bottom: 0;
			}

			.hp-jppc-card label {
				display: inline-block;
				margin: 0 14px 10px 0;
			}

			.hp-jppc-card select {
				vertical-align: middle;
			}

			.hp-jppc-featured-card {
				position: relative;
			}

			.hp-jppc-preview {
				display: flex;
				align-items: center;
				gap: 14px;
				max-width: 360px;
				margin: 14px 0 4px;
				padding: 14px;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				background: #f6f7f7;
			}

			.hp-jppc-preview-image {
				width: 86px;
				aspect-ratio: 16 / 9;
				border: 2px solid #d0d7de;
				border-radius: 8px;
				background:
					linear-gradient(135deg, rgba(20, 87, 166, 0.42), rgba(10, 10, 10, 0)),
					linear-gradient(45deg, #8fb7c9, #f7d08a);
				box-shadow: 0 8px 22px rgba(0, 0, 0, 0.18);
			}

			.hp-jppc-preview-lines {
				flex: 1;
			}

			.hp-jppc-preview-lines span {
				display: block;
				height: 10px;
				margin: 8px 0;
				border-radius: 999px;
				background: #c3c4c7;
			}

			.hp-jppc-preview-lines span:last-child {
				width: 70%;
			}

			.hp-jppc-terms {
				max-height: 150px;
				overflow: auto;
				padding: 8px 10px;
				border: 1px solid #dcdcde;
				background: #fff;
			}

			.hp-jppc-terms label {
				display: block;
				margin-bottom: 4px;
			}

			.hp-jppc-footer {
				margin-top: 24px;
				padding: 16px 0;
				color: #50575e;
			}

			@media (min-width: 1100px) {
				.hp-jppc-grid {
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
			}
		</style>
		<?php
	}

	/**
	 * Add Harvey Plum controls directly to Jetpack's Top Posts & Pages widget form.
	 *
	 * @param array $args Jetpack passes the widget instance and widget object.
	 */
	public static function render_widget_fields( $args ) {
		$instance = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
		$widget   = isset( $args[1] ) && is_object( $args[1] ) ? $args[1] : null;

		if ( ! $widget || ! method_exists( $widget, 'get_field_name' ) ) {
			return;
		}

		$settings = self::settings_from_instance( $instance );
		?>
		<hr>
		<div class="hp-jppc-widget-fields">
			<p><strong><?php esc_html_e( 'Harvey Plum display controls', 'hp-jetpack-popular-posts' ); ?></strong></p>

			<p>
				<label for="<?php echo esc_attr( $widget->get_field_id( 'hp_jppc_days' ) ); ?>">
					<?php esc_html_e( 'Stats days:', 'hp-jetpack-popular-posts' ); ?>
				</label>
				<input
					id="<?php echo esc_attr( $widget->get_field_id( 'hp_jppc_days' ) ); ?>"
					name="<?php echo esc_attr( self::widget_field_name( $widget, 'days' ) ); ?>"
					type="number"
					min="-1"
					step="1"
					value="<?php echo esc_attr( (string) $settings['days'] ); ?>"
					class="tiny-text"
				>
				<span class="description"><?php esc_html_e( '-1 for all time', 'hp-jetpack-popular-posts' ); ?></span>
			</p>

			<p>
				<label for="<?php echo esc_attr( $widget->get_field_id( 'hp_jppc_image_preset' ) ); ?>">
					<?php esc_html_e( 'Featured image treatment:', 'hp-jetpack-popular-posts' ); ?>
				</label>
				<?php self::render_widget_select( $widget, 'image_preset', self::image_preset_labels(), $settings['image_preset'] ); ?>
			</p>

			<p>
				<label><?php esc_html_e( 'Crop:', 'hp-jetpack-popular-posts' ); ?></label>
				<?php self::render_widget_select( $widget, 'image_aspect_ratio', self::image_aspect_ratios(), $settings['image_aspect_ratio'] ); ?>
				<?php self::render_widget_select( $widget, 'image_position', self::image_positions(), $settings['image_position'] ); ?>
			</p>

			<p>
				<label><?php esc_html_e( 'Style:', 'hp-jetpack-popular-posts' ); ?></label>
				<?php self::render_widget_select( $widget, 'image_radius', self::radius_options(), $settings['image_radius'] ); ?>
				<?php self::render_widget_select( $widget, 'image_border', self::border_options(), $settings['image_border'] ); ?>
				<?php self::render_widget_select( $widget, 'image_shadow', self::shadow_options(), $settings['image_shadow'] ); ?>
			</p>

			<p>
				<label for="<?php echo esc_attr( $widget->get_field_id( 'hp_jppc_image_border_color' ) ); ?>">
					<?php esc_html_e( 'Border color:', 'hp-jetpack-popular-posts' ); ?>
				</label>
				<input
					id="<?php echo esc_attr( $widget->get_field_id( 'hp_jppc_image_border_color' ) ); ?>"
					name="<?php echo esc_attr( self::widget_field_name( $widget, 'image_border_color' ) ); ?>"
					type="color"
					value="<?php echo esc_attr( $settings['image_border_color'] ); ?>"
				>
			</p>

			<p>
				<label><?php esc_html_e( 'Hover and spacing:', 'hp-jetpack-popular-posts' ); ?></label>
				<?php self::render_widget_select( $widget, 'image_hover', self::hover_options(), $settings['image_hover'] ); ?>
				<?php self::render_widget_select( $widget, 'image_spacing', self::spacing_options(), $settings['image_spacing'] ); ?>
			</p>

			<p>
				<label>
					<input
						name="<?php echo esc_attr( self::widget_field_name( $widget, 'fallback_to_avatars' ) ); ?>"
						type="checkbox"
						value="1"
						<?php checked( 1, $settings['fallback_to_avatars'] ); ?>
					>
					<?php esc_html_e( 'Use fallback images when a post has no featured image.', 'hp-jetpack-popular-posts' ); ?>
				</label>
			</p>

			<p>
				<label for="<?php echo esc_attr( $widget->get_field_id( 'hp_jppc_default_image_url' ) ); ?>">
					<?php esc_html_e( 'Fallback image URL:', 'hp-jetpack-popular-posts' ); ?>
				</label>
				<input
					id="<?php echo esc_attr( $widget->get_field_id( 'hp_jppc_default_image_url' ) ); ?>"
					name="<?php echo esc_attr( self::widget_field_name( $widget, 'default_image_url' ) ); ?>"
					type="url"
					value="<?php echo esc_attr( $settings['default_image_url'] ); ?>"
					class="widefat"
				>
			</p>

			<p>
				<label>
					<input
						name="<?php echo esc_attr( self::widget_field_name( $widget, 'before_enabled' ) ); ?>"
						type="checkbox"
						value="1"
						<?php checked( 1, $settings['before_enabled'] ); ?>
					>
					<?php esc_html_e( 'Show before-item metadata', 'hp-jetpack-popular-posts' ); ?>
				</label>
				<textarea
					name="<?php echo esc_attr( self::widget_field_name( $widget, 'before_content' ) ); ?>"
					rows="3"
					class="widefat code"
					placeholder="<span class=&quot;hp-jppc-meta&quot;>{categories}</span>"
				><?php echo esc_textarea( $settings['before_content'] ); ?></textarea>
			</p>

			<p>
				<label>
					<input
						name="<?php echo esc_attr( self::widget_field_name( $widget, 'after_enabled' ) ); ?>"
						type="checkbox"
						value="1"
						<?php checked( 1, $settings['after_enabled'] ); ?>
					>
					<?php esc_html_e( 'Show after-item metadata', 'hp-jetpack-popular-posts' ); ?>
				</label>
				<textarea
					name="<?php echo esc_attr( self::widget_field_name( $widget, 'after_content' ) ); ?>"
					rows="3"
					class="widefat code"
					placeholder="<span class=&quot;hp-jppc-meta&quot;>{date}</span>"
				><?php echo esc_textarea( $settings['after_content'] ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Tokens: {post_id}, {title}, {date}, {author}, {categories}', 'hp-jetpack-popular-posts' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save Harvey Plum widget instance fields through Jetpack's widget saving filter.
	 *
	 * @param array $instance Sanitized Jetpack widget instance.
	 * @param array $new_instance Raw submitted widget instance.
	 * @return array
	 */
	public static function save_widget_fields( $instance, $new_instance ) {
		$instance['hp_jppc'] = self::sanitize_settings( isset( $new_instance['hp_jppc'] ) ? $new_instance['hp_jppc'] : array() );

		return $instance;
	}

	/**
	 * Capture the instance being rendered so Jetpack's frontend hooks can use its settings.
	 *
	 * @param array    $instance Widget instance.
	 * @param WP_Widget $widget Widget object.
	 * @param array    $args Widget args.
	 * @return array
	 */
	public static function capture_widget_instance( $instance, $widget, $args ) {
		if ( is_object( $widget ) && isset( $widget->id_base ) && 'top-posts' === $widget->id_base ) {
			self::$active_widget_settings = self::settings_from_instance( is_array( $instance ) ? $instance : array() );
			self::$active_widget_id       = isset( $args['widget_id'] ) ? sanitize_html_class( $args['widget_id'] ) : '';
		}

		return $instance;
	}

	/**
	 * Render term checkboxes.
	 *
	 * @param string $key Setting key.
	 * @param array  $terms Terms to show.
	 * @param array  $selected Selected term IDs.
	 */
	private static function render_term_checkboxes( $key, $terms, $selected ) {
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			echo '<p>' . esc_html__( 'No terms found.', 'hp-jetpack-popular-posts' ) . '</p>';
			return;
		}

		echo '<div class="hp-jppc-terms">';
		foreach ( $terms as $term ) {
			printf(
				'<label><input name="%1$s[%2$s][]" type="checkbox" value="%3$d" %4$s> %5$s</label>',
				esc_attr( HP_JPPC_OPTION ),
				esc_attr( $key ),
				absint( $term->term_id ),
				checked( in_array( (int) $term->term_id, array_map( 'intval', $selected ), true ), true, false ),
				esc_html( $term->name )
			);
		}
		echo '</div>';
	}

	/**
	 * Render a settings select.
	 *
	 * @param string $key Setting key.
	 * @param array  $options Select options.
	 * @param string $selected Selected value.
	 */
	private static function render_select( $key, $options, $selected ) {
		printf( '<select name="%1$s[%2$s]">', esc_attr( HP_JPPC_OPTION ), esc_attr( $key ) );
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Get a nested Harvey Plum widget field name.
	 *
	 * @param WP_Widget $widget Widget object.
	 * @param string    $key Setting key.
	 * @return string
	 */
	private static function widget_field_name( $widget, $key ) {
		return $widget->get_field_name( 'hp_jppc' ) . '[' . $key . ']';
	}

	/**
	 * Render a select inside Jetpack's widget form.
	 *
	 * @param WP_Widget $widget Widget object.
	 * @param string    $key Setting key.
	 * @param array     $options Select options.
	 * @param string    $selected Selected value.
	 */
	private static function render_widget_select( $widget, $key, $options, $selected ) {
		printf(
			'<select id="%1$s" name="%2$s">',
			esc_attr( $widget->get_field_id( 'hp_jppc_' . $key ) ),
			esc_attr( self::widget_field_name( $widget, $key ) )
		);
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Flatten image presets into labels for select controls.
	 *
	 * @return array
	 */
	private static function image_preset_labels() {
		$labels = array();

		foreach ( self::image_presets() as $key => $preset ) {
			$labels[ $key ] = $preset['label'];
		}

		return $labels;
	}

	/**
	 * Filter Jetpack's stats window.
	 *
	 * @param int   $days Number of days.
	 * @param array $args Widget args.
	 * @return int
	 */
	public static function filter_top_posts_days( $days, $args ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( isset( $args['widget_id'] ) ) {
			self::$active_widget_id = sanitize_html_class( $args['widget_id'] );
		}

		$settings = self::active_settings();

		return (int) $settings['days'];
	}

	/**
	 * Filter number of displayed posts.
	 *
	 * @param int $count Current count.
	 * @return int
	 */
	public static function filter_widget_count( $count ) {
		$settings = self::active_settings();
		$override = (int) $settings['count_override'];

		if ( $override > 0 ) {
			return $override;
		}

		return $count;
	}

	/**
	 * Filter Jetpack image options.
	 *
	 * @param array $options Image options.
	 * @return array
	 */
	public static function filter_image_options( $options ) {
		$settings = self::active_settings();
		$preset   = self::resolved_image_preset( $settings );

		if ( ! empty( $preset['avatar_size'] ) ) {
			$options['avatar_size'] = absint( $preset['avatar_size'] );
		}

		if ( ! empty( $preset['width'] ) ) {
			$options['width'] = absint( $preset['width'] );
		}

		if ( ! empty( $preset['height'] ) ) {
			$options['height'] = absint( $preset['height'] );
		}

		$options['fallback_to_avatars'] = ! empty( $settings['fallback_to_avatars'] );

		if ( ! empty( $settings['default_image_url'] ) ) {
			$options['gravatar_default'] = esc_url_raw( $settings['default_image_url'] );
		}

		return $options;
	}

	/**
	 * Resolve image preset and optional aspect ratio into concrete Jetpack image dimensions.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function resolved_image_preset( $settings ) {
		$presets = self::image_presets();
		$preset  = isset( $presets[ $settings['image_preset'] ] ) ? $presets[ $settings['image_preset'] ] : $presets['default'];

		if ( 'default' === $settings['image_aspect_ratio'] || empty( $preset['width'] ) ) {
			return $preset;
		}

		$width = absint( $preset['width'] );

		switch ( $settings['image_aspect_ratio'] ) {
			case 'square':
				$height = $width;
				break;
			case 'classic':
				$height = (int) round( $width * 0.75 );
				break;
			case 'poster':
				$height = (int) round( $width * 1.333 );
				break;
			case 'wide':
			default:
				$height = (int) round( $width * 0.5625 );
				break;
		}

		$preset['height'] = max( 1, $height );

		return $preset;
	}

	/**
	 * Filter result posts.
	 *
	 * @param array $posts Result posts.
	 * @param array $post_ids Original post IDs.
	 * @param int   $count Desired count.
	 * @return array
	 */
	public static function filter_posts( $posts, $post_ids, $count ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$settings = self::active_settings();

		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return $posts;
		}

		$excluded_ids = self::parse_id_list( $settings['excluded_post_ids'] );
		$current_id   = is_singular() ? get_queried_object_id() : 0;

		foreach ( $posts as $key => $post ) {
			$post_id = isset( $post['post_id'] ) ? absint( $post['post_id'] ) : 0;

			if ( ! $post_id ) {
				unset( $posts[ $key ] );
				continue;
			}

			if ( $current_id && ! empty( $settings['hide_current_post'] ) && $current_id === $post_id ) {
				unset( $posts[ $key ] );
				continue;
			}

			if ( in_array( $post_id, $excluded_ids, true ) ) {
				unset( $posts[ $key ] );
				continue;
			}

			if ( ! self::post_matches_age_filter( $post_id, $settings ) ) {
				unset( $posts[ $key ] );
				continue;
			}

			if ( ! self::post_matches_terms( $post_id, 'category', $settings['allowed_categories'] ) ) {
				unset( $posts[ $key ] );
				continue;
			}

			if ( ! self::post_matches_terms( $post_id, 'post_tag', $settings['allowed_tags'] ) ) {
				unset( $posts[ $key ] );
				continue;
			}
		}

		return array_values( $posts );
	}

	/**
	 * Parse a comma-separated ID list.
	 *
	 * @param string $value Raw IDs.
	 * @return array
	 */
	private static function parse_id_list( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return array();
		}

		$ids = preg_split( '/[\s,]+/', (string) $value );

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Check age filters.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function post_matches_age_filter( $post_id, $settings ) {
		if ( '' === $settings['min_age_days'] && '' === $settings['max_age_days'] ) {
			return true;
		}

		$post_time = get_post_time( 'U', true, $post_id );
		if ( ! $post_time ) {
			return true;
		}

		$age_days = floor( ( current_time( 'timestamp', true ) - $post_time ) / DAY_IN_SECONDS );

		if ( '' !== $settings['min_age_days'] && $age_days < (int) $settings['min_age_days'] ) {
			return false;
		}

		if ( '' !== $settings['max_age_days'] && $age_days > (int) $settings['max_age_days'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Check term restrictions.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $allowed_term_ids Allowed IDs.
	 * @return bool
	 */
	private static function post_matches_terms( $post_id, $taxonomy, $allowed_term_ids ) {
		if ( empty( $allowed_term_ids ) ) {
			return true;
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return true;
		}

		$post_term_ids = wp_get_post_terms(
			$post_id,
			$taxonomy,
			array(
				'fields' => 'ids',
			)
		);

		if ( is_wp_error( $post_term_ids ) || empty( $post_term_ids ) ) {
			return false;
		}

		return (bool) array_intersect( array_map( 'intval', $post_term_ids ), array_map( 'intval', $allowed_term_ids ) );
	}

	/**
	 * Append UTM parameters to Top Posts links.
	 *
	 * @param string $permalink Current permalink.
	 * @param array  $post Post array.
	 * @return string
	 */
	public static function filter_permalink( $permalink, $post ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$settings = self::active_settings();
		$args     = array();

		foreach ( array( 'source', 'medium', 'campaign' ) as $key ) {
			$setting_key = 'utm_' . $key;
			if ( ! empty( $settings[ $setting_key ] ) ) {
				$args[ $setting_key ] = $settings[ $setting_key ];
			}
		}

		if ( empty( $args ) ) {
			return $permalink;
		}

		return add_query_arg( $args, $permalink );
	}

	/**
	 * Render configured content before a post item.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function render_before_post( $post_id ) {
		self::print_widget_style_once();

		$settings = self::active_settings();

		if ( empty( $settings['before_enabled'] ) || '' === trim( $settings['before_content'] ) ) {
			return;
		}

		echo self::prepare_template( $settings['before_content'], $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render configured content after a post item.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function render_after_post( $post_id ) {
		$settings = self::active_settings();

		if ( empty( $settings['after_enabled'] ) || '' === trim( $settings['after_content'] ) ) {
			return;
		}

		echo self::prepare_template( $settings['after_content'], $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Replace content tokens.
	 *
	 * @param string $template Template.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private static function prepare_template( $template, $post_id ) {
		$category_names = wp_get_post_terms(
			$post_id,
			'category',
			array(
				'fields' => 'names',
			)
		);

		if ( is_wp_error( $category_names ) ) {
			$category_names = array();
		}

		$replacements = array(
			'{post_id}'    => (string) absint( $post_id ),
			'{title}'      => get_the_title( $post_id ),
			'{date}'       => get_the_date( '', $post_id ),
			'{author}'     => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) ),
			'{categories}' => implode( ', ', $category_names ),
		);

		return wp_kses_post( strtr( $template, $replacements ) );
	}

	/**
	 * Enqueue frontend CSS tweaks.
	 */
	public static function enqueue_frontend_css() {
		$settings = self::settings();
		$css      = self::build_frontend_css( $settings );

		if ( '' === trim( $css ) ) {
			return;
		}

		wp_register_style( 'hp-jppc-frontend', false, array(), HP_JPPC_VERSION );
		wp_enqueue_style( 'hp-jppc-frontend' );
		wp_add_inline_style( 'hp-jppc-frontend', $css );
	}

	/**
	 * Print scoped CSS once for the current widget instance.
	 */
	private static function print_widget_style_once() {
		$style_key = self::$active_widget_id ? self::$active_widget_id : 'hp-jppc-global';

		if ( isset( self::$printed_widget_styles[ $style_key ] ) ) {
			return;
		}

		$settings = self::active_settings();
		$scope    = self::$active_widget_id ? '#' . self::$active_widget_id : '';
		$css      = self::build_frontend_css( $settings, $scope );

		if ( '' === trim( $css ) ) {
			return;
		}

		self::$printed_widget_styles[ $style_key ] = true;

		printf( '<style id="%1$s">%2$s</style>', esc_attr( 'hp-jppc-' . $style_key ), wp_strip_all_tags( $css ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build frontend CSS for image treatments.
	 *
	 * @param array  $settings Settings.
	 * @param string $scope Optional selector prefix.
	 * @return string
	 */
	private static function build_frontend_css( $settings, $scope = '' ) {
		$css    = '';
		$preset = self::resolved_image_preset( $settings );
		$width  = ! empty( $preset['width'] ) ? absint( $preset['width'] ) : 0;
		$height = ! empty( $preset['height'] ) ? absint( $preset['height'] ) : 0;

		$desc_scope     = $scope ? $scope . ' ' : '';
		$image_selector = $scope . '.widget_top-posts .widgets-list-layout img.widgets-list-layout-blavatar,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout img.widgets-list-layout-blavatar,' . $desc_scope . '.widget-grid-view-image img';
		$link_selector  = $scope . '.widget_top-posts .widgets-list-layout li>a:first-child,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout li>a:first-child,' . $desc_scope . '.widget-grid-view-image a';

		if ( 'default' !== $settings['image_preset'] || 'default' !== $settings['image_radius'] || 'none' !== $settings['image_border'] || 'none' !== $settings['image_shadow'] || 'none' !== $settings['image_hover'] || 'default' !== $settings['image_aspect_ratio'] || 'center center' !== $settings['image_position'] ) {
			$css .= $image_selector . '{display:block;object-fit:cover;object-position:' . esc_attr( $settings['image_position'] ) . ';transition:transform .18s ease,filter .18s ease,box-shadow .18s ease;}';
			$css .= $link_selector . '{overflow:hidden;}';
		}

		if ( $width > 0 ) {
			$list_width = self::list_width_for_preset( $settings['image_preset'] );
			$css       .= sprintf(
				$scope . '.widget_top-posts .widgets-list-layout li>a:first-child,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout li>a:first-child{width:%1$s;max-width:%2$dpx;}' . $scope . '.widget_top-posts .widgets-list-layout img.widgets-list-layout-blavatar,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout img.widgets-list-layout-blavatar{width:100%%;max-width:100%%;}',
				esc_attr( $list_width ),
				$width
			);
		}

		if ( $width > 0 && $height > 0 ) {
			$css .= sprintf(
				$image_selector . '{aspect-ratio:%1$d / %2$d;height:auto;}',
				$width,
				$height
			);
		}

		if ( 'default' !== $settings['image_radius'] ) {
			$css .= $image_selector . '{border-radius:' . esc_attr( self::radius_css_value( $settings['image_radius'] ) ) . ';}';
		}

		if ( 'none' !== $settings['image_border'] ) {
			$border_width = 'strong' === $settings['image_border'] ? '2px' : '1px';
			$css         .= $image_selector . '{border:' . esc_attr( $border_width ) . ' solid ' . sanitize_hex_color( $settings['image_border_color'] ) . ';}';

			if ( 'frame' === $settings['image_border'] ) {
				$css .= $image_selector . '{padding:3px;background:#fff;}';
			}
		}

		if ( 'none' !== $settings['image_shadow'] ) {
			$css .= $image_selector . '{box-shadow:' . esc_attr( self::shadow_css_value( $settings['image_shadow'] ) ) . ';}';
		}

		if ( 'none' !== $settings['image_hover'] ) {
			$hover_selector = $scope . '.widget_top-posts .widgets-list-layout li>a:first-child:hover img,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout li>a:first-child:hover img,' . $desc_scope . '.widget-grid-view-image a:hover img';
			$css           .= $hover_selector . '{' . self::hover_css_value( $settings['image_hover'] ) . '}';
		}

		if ( 'default' !== $settings['image_spacing'] ) {
			$css .= self::spacing_css( $settings['image_spacing'], $scope );
		}

		if ( ! empty( $settings['custom_css'] ) ) {
			$css .= "\n" . $settings['custom_css'];
		}

		return $css;
	}

	/**
	 * Get list image width for a source preset.
	 *
	 * @param string $preset Preset key.
	 * @return string
	 */
	private static function list_width_for_preset( $preset ) {
		switch ( $preset ) {
			case 'compact':
				return '96px';
			case 'poster':
				return '38%';
			case 'editorial':
				return '46%';
			case 'balanced':
				return '42%';
			default:
				return '40%';
		}
	}

	/**
	 * Convert radius option to CSS.
	 *
	 * @param string $radius Radius option.
	 * @return string
	 */
	private static function radius_css_value( $radius ) {
		switch ( $radius ) {
			case 'square':
				return '0';
			case 'soft':
				return '6px';
			case 'rounded':
				return '14px';
			case 'circle':
				return '999px';
			default:
				return 'inherit';
		}
	}

	/**
	 * Convert shadow option to CSS.
	 *
	 * @param string $shadow Shadow option.
	 * @return string
	 */
	private static function shadow_css_value( $shadow ) {
		switch ( $shadow ) {
			case 'subtle':
				return '0 2px 8px rgba(0,0,0,.12)';
			case 'lifted':
				return '0 8px 22px rgba(0,0,0,.18)';
			case 'deep':
				return '0 14px 38px rgba(0,0,0,.24)';
			default:
				return 'none';
		}
	}

	/**
	 * Convert hover option to CSS declarations.
	 *
	 * @param string $hover Hover option.
	 * @return string
	 */
	private static function hover_css_value( $hover ) {
		switch ( $hover ) {
			case 'bright':
				return 'filter:brightness(1.08);';
			case 'zoom':
				return 'transform:scale(1.045);';
			case 'lift':
				return 'transform:translateY(-2px);filter:brightness(1.04);';
			default:
				return '';
		}
	}

	/**
	 * Convert spacing option to CSS.
	 *
	 * @param string $spacing Spacing option.
	 * @param string $scope Optional selector prefix.
	 * @return string
	 */
	private static function spacing_css( $spacing, $scope = '' ) {
		switch ( $spacing ) {
			case 'tight':
				return $scope . '.widget_top-posts .widgets-list-layout li,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout li{margin-bottom:.45rem;}' . $scope . '.widget_top-posts .widgets-list-layout div.widgets-list-layout-links,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout div.widgets-list-layout-links{padding-left:.55rem;}';
			case 'roomy':
				return $scope . '.widget_top-posts .widgets-list-layout li,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout li{margin-bottom:1rem;}' . $scope . '.widget_top-posts .widgets-list-layout div.widgets-list-layout-links,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout div.widgets-list-layout-links{padding-left:1rem;line-height:1.35;}';
			case 'stacked':
				return $scope . '.widget_top-posts .widgets-list-layout li,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout li{display:block;margin-bottom:1.15rem;}' . $scope . '.widget_top-posts .widgets-list-layout li>a:first-child,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout li>a:first-child{display:block;width:100%;max-width:none;margin:0 0 .55rem;}' . $scope . '.widget_top-posts .widgets-list-layout div.widgets-list-layout-links,' . $scope . '.jetpack_top_posts_widget .widgets-list-layout div.widgets-list-layout-links{width:100%;max-width:none;padding-left:0;}';
			default:
				return '';
		}
	}
}

HP_Jetpack_Popular_Posts_Controls::init();
