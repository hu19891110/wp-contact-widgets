<?php

namespace WPCW;

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

abstract class Base_Widget extends \WP_Widget {

	/**
	 * Default properties for fields
	 *
	 * @var array
	 */
	protected $field_defaults = [
		'key'            => '',
		'icon'           => '',
		'class'          => 'widefat',
		'id'             => '',
		'name'           => '',
		'label'          => '',
		'label_after'    => false,
		'description'    => '',
		'type'           => 'text',
		'sanitizer'      => 'sanitize_text_field',
		'escaper'        => 'esc_html',
		'form_callback'  => 'render_form_input',
		'default'        => '', // Used mainly for social fields to add default value
		'value'          => '',
		'placeholder'    => '',
		'sortable'       => true,
		'atts'           => '', // Input attributes
		'show_front_end' => true, // Are we showing this field on the front end?
		'show_empty'     => false, // Show the field even if value is empty
		'select_options' => [], // Only used if type=select & form_callback=render_form_select
	];

	/**
	 * Widget base constructor
	 *
	 * @param string $id_base
	 * @param string $name
	 * @param array  $widget_options
	 */
	public function __construct( $id_base, $name, array $widget_options ) {

		parent::__construct( $id_base, $name, $widget_options );

		if ( has_action( 'wp_enqueue_scripts', [ $this, 'front_end_enqueue_scripts' ] ) ) {

			return;

		}

		// Enqueue style if widget is active (appears in a sidebar) or if in Customizer preview.
		if ( is_active_widget( false, false, $this->id_base ) || is_customize_preview() ) {

			add_action( 'wp_enqueue_scripts', [ $this, 'front_end_enqueue_scripts' ] );

		}

	}

	/**
	 * Add common ressources needed for the form
	 *
	 * @param array $instance
	 *
	 * @return string|void
	 */
	public function form( $instance ) {

		add_action( 'admin_footer',                            [ $this, 'enqueue_scripts' ] );
		add_action( 'customize_controls_print_footer_scripts', [ $this, 'print_customizer_scripts' ] );

		?>
		<script>
			( function ( $ ) {

				// This let us know that we appended a new widget to reset sortables
				$( document ).trigger( 'wpcw.change' );

			} )( jQuery );
		</script>
		<?php

	}

	/**
	 * Sanitize widget form values as they are saved
	 *
	 * @param  array $new_instance Values just sent to be saved.
	 * @param  array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {

		$fields = $this->get_fields( $old_instance );

		// Force value for checkbox since they are not posted
		foreach ( $fields as $key => $field ) {

			if ( 'checkbox' === $field['type'] && ! isset( $new_instance[ $key ]['value'] ) ) {

				$new_instance[ $key ] = [ 'value' => 'no' ];

			}

		}

		// Starting at 1 since title order is 0
		$order = 1;

		foreach ( $new_instance as $key => &$instance ) {

			$sanitizer_callback = $fields[ $key ]['sanitizer'];

			// Title can't be an array
			if ( 'title' === $key ) {

				$instance = $sanitizer_callback( $instance['value'] );

				continue;

			}

			$instance['value'] = $sanitizer_callback( $instance['value'] );
			$instance['order'] = $order++;

		}

		return $new_instance;

	}

	/**
	 * Initialize fields for use on front-end of forms
	 *
	 * @param  array $instance
	 * @param  array $fields (optional)
	 * @param  bool  $ordered (optional)
	 *
	 * @return array
	 */
	protected function get_fields( array $instance, array $fields = [], $ordered = true ) {

		$order = 0;

		foreach ( $fields as $key => &$field ) {

			$common_properties = [
				'key'   => $key,
				'icon'  => $key,
				'order' => ! empty( $instance[ $key ]['order'] ) ? absint( $instance[ $key ]['order'] ) : $order,
				'id'    => $this->get_field_id( $key ),
				'name'  => $this->get_field_name( $key ) . '[value]',
				'value' => ! empty( $instance[ $key ]['value'] ) ? $instance[ $key ]['value'] : '',
			];

			$common_properties = wp_parse_args( $common_properties, $this->field_defaults );
			$field             = wp_parse_args( $field, $common_properties );

			$default_closure = function( $value ) { return $value; };

			foreach ( [ 'escaper', 'sanitizer' ] as $key ) {

				$field[ $key ] = ! is_callable( $field[ $key ] ) ? $default_closure : $field[ $key ];

			}

			$order++;

		}

		if ( $ordered ) {

			$fields = $this->order_field( $fields );

		}

		return $fields;

	}

	/**
	 * Order array by field order
	 *
	 * @param  array $fields
	 *
	 * @return array
	 */
	protected function order_field( array $fields ) {

		uksort( $fields, function( $a, $b ) use ( $fields ) {

			// We want title first and order of non sortable fields doesn't matter
			if ( ! $fields[ $a ]['sortable'] && 'title' !== $a ) {

				return 1;

			}

			return ( $fields[ $a ]['order'] < $fields[ $b ]['order'] ) ? -1 : 1;

		} );

		return $fields;

	}

	/**
	 * Check if all the fields we show on the front-end are empty
	 *
	 * @param  array $fields
	 *
	 * @return bool
	 */
	protected function is_widget_empty( array $fields ) {

		foreach ( $fields as $key => $field ) {

			/**
			 * Filter to ignore the title when checking if a widget is empty
			 *
			 * @since 1.0.0
			 *
			 * @var bool
			 */
			$ignore_title = (bool) apply_filters( 'wpcw_is_widget_empty_ignore_title', false );

			if ( 'title' === $key && $ignore_title ) {

				continue;

			}

			if ( ! empty( $field['value'] ) && $field['show_front_end'] ) {

				return false;

			}

		}

		return true;

	}

	/**
	 * Print current label
	 *
	 * @param array $field
	 */
	protected function print_label( array $field ) {

		printf(
			' <label for="%s" title="%s">%s</label>',
			esc_attr( $field['id'] ),
			esc_attr( $field['description'] ),
			esc_html( $field['label'] )
		);

	}

	/**
	 * Print label and wrapper
	 * @param array $field
	 */
	protected function before_form_field( array $field ) {

		$classes = [ $field['type'], $field['key'] ];

		if ( ! $field['sortable'] ) {

			$classes[] = 'not-sortable';

		}

		if ( $field['label_after'] ) {

			$classes[] = 'label-after';

		}

		printf(
			'<p class="%s">',
			implode( ' ', $classes )
		);

		if ( ! $field['label_after'] ) {

			$this->print_label( $field );

		}

		if ( $field['sortable'] ) {

			echo '<span>';

		}

	}

	/**
	 * Render input field for admin form
	 *
	 * @param array $field
	 */
	protected function render_form_input( array $field ) {

		if ( ! is_admin() ) {

			return;

		}

		$this->before_form_field( $field );

		printf(
			'<input class="%s" id="%s" name="%s" type="%s" value="%s" placeholder="%s" autocomplete="off" %s>',
			esc_attr( $field['class'] ),
			esc_attr( $field['id'] ),
			esc_attr( $field['name'] ),
			esc_attr( $field['type'] ),
			esc_attr( $field['value'] ),
			esc_attr( $field['placeholder'] ),
			esc_attr( $field['atts'] )
		);

		if ( $field['label_after'] ) {

			$this->print_label( $field );

		}

		$this->after_form_field( $field );

	}

	/**
	 * Render select field
	 *
	 * @param array $field
	 */
	protected function render_form_select( array $field ) {

		$this->before_form_field( $field );

		printf(
			'<select class="%s" id="%s" name="%s" autocomplete="off">',
			esc_attr( $field['class'] ),
			esc_attr( $field['id'] ),
			esc_attr( $field['name'] )
		);

		foreach ( $field['select_options'] as $value => $name ) {

			printf(
				'<option value="%s" %s>%s</option>',
				$value,
				$field['value'] === $value ? 'selected' : '',
				$name
			);

		}

		echo '</select>';

		if ( $field['label_after'] ) {

			$this->print_label( $field );

		}

		$this->after_form_field( $field );

	}

	/**
	 * Render textarea field for admin widget form
	 *
	 * @param array $field
	 */
	protected function render_form_textarea( array $field ) {

		$this->before_form_field( $field );

		printf(
			'<textarea class="%s" id="%s" name="%s" placeholder="%s">%s</textarea>',
			esc_attr( $field['class'] ),
			esc_attr( $field['id'] ),
			esc_attr( $field['name'] ),
			esc_attr( $field['placeholder'] ),
			esc_textarea( $field['value'] )
		);

		$this->after_form_field( $field );

	}

	/**
	 * Render the hours container and select fields
	 *
	 * @param  array $field Field data.
	 * @param  array $day   The current day in the iteration.
	 * @param  array $hours The array of times.
	 *
	 * @since NEXT
	 *
	 * @return mixed
	 */
	protected function render_day_input( $field, $day, array $hours ) {

		$field['name']     = str_replace( 'value', strtolower( $day ), $field['name'] );
		$field['disabled'] = $hours['not_open'] ? true : false;

		$open_label = $hours['not_open'] ? __( 'CLOSED', 'contact-widgets' ) : __( 'OPEN', 'contact-widgets' );
		$open_class = $hours['not_open'] ? 'closed' : 'open';

		$apply_to_all_toggle = key( $field['days'] ) === $day ? '<a href="#" class="js_wpcw_apply_hours_to_all">' . __( 'Apply to All', 'contact-widgets' ) . '</a>' : '';

		$closed_checkbox = '<input name="' . $field['name'] . '[not_open]" id="' . $field['name'] . '[not_open]" class="js_wpcw_closed_checkbox" type="checkbox" value="1" ' . $this->checked( $hours['not_open'], true ) . '><label for="' . $field['name'] . '[not_open]" class="js_wpcw_closed_checkbox"><small>' . esc_html__( 'Closed', 'contact-widgets' ) . '</small></label>';

		printf(
			'<div class="day-container closed">%1$s<div class="hidden-container">%2$s %3$s</div></div>',
			'<strong>' . esc_html( ucwords( $day ) ) . '</strong><span class="toggle"></span><span class="open-label ' . $open_class . '">' . $open_label . '</span>',
			$this->render_hours_selection( $field, sanitize_title( $day ), $hours ),
			'<span class="day-checkbox-toggle">' . $apply_to_all_toggle . $closed_checkbox . '</span>'
		);

	}

	/**
	 * Render the 'Hours' select fields
	 *
	 * @param array $field Field data
	 * @param array $day   The current day in the iteration.
	 * @param array $hours The array of times.
	 *
	 * @since NEXT
	 *
	 * @return mixed
	 */
	protected function render_hours_selection( $field, $day, $hours ) {

		ob_start();

		$times = $this->get_time_array();

		$field['name'] = str_replace( 'value', strtolower( $day ), $field['name'] );

		$disabled_field = $field['disabled'] ? ' disabled="disabled"' : '';

		$x = 1;

		while ( $x <= count( $field['days'][ $day ]['open'] ) ) {

			?>

			<div class="hours-selection">

				<select name="<?php echo esc_attr( $field['name'] . '[open][' . $x . ']' ); ?>" <?php echo $disabled_field; ?>>

				<?php

				foreach ( $times as $time ) {

					$select = isset( $hours['open'][ $x ] ) ? $hours['open'][ $x ] : '';

					?>

					<option <?php selected( $select, $time ); ?>><?php echo esc_html( $time ); ?></option>

					<?php

				}

				?>

				</select>

				<select name="<?php echo esc_attr( $field['name'] . '[closed][' . $x . ']' ); ?>" <?php echo $disabled_field; ?>>

				<?php

				foreach ( $times as $time ) {

					$select = isset( $hours['closed'][ $x ] ) ? $hours['closed'][ $x ] : '';

					?>

					<option <?php selected( $select, $time ); ?>><?php echo esc_html( $time ); ?></option>

					<?php

				}

				?>

				</select>

				<a href="#" class="<?php echo esc_attr( ( 1 === $x ) ? 'add' : 'remove' ); ?>-time button-secondary">

					<?php echo ( 1 === $x ) ? esc_html__( 'Add', 'contact-widgets' ) : '<span class="dashicons dashicons-no-alt"></span>'; ?>

				</a>

			</div>

			<?php

			$x++;

		}

		return ob_get_clean();

	}

	/**
	 * Generate an array of times in half hour increments
	 *
	 * @since NEXT
	 *
	 * @return array
	 */
	protected function get_time_array() {

		/**
		 * Filter the hour increments in the select field
		 *
		 * @since NEXT
		 *
		 * @return string
		 */
		switch ( apply_filters( 'wpcw_hour_increment', 'half_hour' ) ) {

			case 'fifteen_minutes':

				$step = 900;

				break;

			case 'half_hour':
			default:

				$step = 1800;

			break;

		}

		$steps = range( 0, 47 * 1800, $step );

		return array_map( function ( $time ) {

			return date( get_option( 'time_format' ), $time );

		}, $steps );

	}

	/**
	 * Close wrapper of form field
	 *
	 * @param array
	 */
	protected function after_form_field( array $field ) {

		if ( $field['sortable'] ) {

			echo '<span class="wpcw-widget-sortable-handle"><span class="dashicons dashicons-menu"></span></span></span>';

		}

		echo '</p>';

	}

	/**
	 * Print beginning of front-end display
	 *
	 * @param array $args
	 * @param array $fields
	 */
	protected function before_widget( array $args, array &$fields ) {

		$title = array_shift( $fields );
		echo $args['before_widget'];

		if ( ! empty( $title['value'] ) ) {

			/**
			 * Filter the widget title
			 *
			 * @since 1.0.0
			 *
			 * @var string
			 */
			$title = (string) apply_filters( 'widget_title', $title['value'] );

			echo $args['before_title'] . $title . $args['after_title'];

		}

		echo '<ul>';

	}

	/**
	 * Print end of front-end display
	 *
	 * @param array $args
	 * @param array $fields
	 */
	protected function after_widget( array $args, array &$fields ) {

		echo '</ul>';

		if (
			! is_customize_preview()
			&& current_user_can( 'edit_theme_options' )
			&& current_user_can( 'customize' )
			&& isset( $args['id'] )
		) {

			// admin-bar.php -> wp_admin_bar_customize_menu()
			$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

			$edit_url = add_query_arg(
				[
					'autofocus' => [
						'section' => 'sidebar-widgets-' . $args['id'],
						'control' => 'widget_' . preg_replace( '/-(\d)/', '[$1]', $args['widget_id'] ),
					],
					'url' => urlencode( $current_url ),
				],
				wp_customize_url()
			);

			printf(
				'<a class="post-edit-link" data-widget-id="%s" href="%s">%s</a>',
				esc_attr( $args['widget_id'] ),
				esc_url( $edit_url ),
				__( 'Edit' )
			);

		}

		echo $args['after_widget'];

	}

	/**
	 * Helper to output only 'checked' and not checked='checked'
	 * IE 9 & 10 don't support the latter
	 *
	 * @param mixed  $helper  One of the values to compare
	 * @param mixed  $current (true) The other value to compare if not just true
	 * @param bool   $echo    Whether to echo or just return the string
	 * @return string html attribute or empty string
	 */
	public function checked( $helper, $current, $echo = false ) {

		$result = (string) $helper === (string) $current ?  'checked' : '';

		if ( $echo ) {

			echo $result;

		}

		return $result;

	}

	/**
	 * Print footer script and styles
	 */
	public function enqueue_scripts() {

		$suffix = SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style( 'font-awesome', '//maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css', [], '4.5.0' );
		wp_enqueue_style( 'wpcw-admin', \Contact_Widgets::$assets_url . "css/admin{$suffix}.css", [ 'font-awesome' ], Plugin::$version );
		wp_enqueue_script( 'wpcw-admin', \Contact_Widgets::$assets_url . "js/admin{$suffix}.js", [ 'jquery' ], Plugin::$version, true );

		if ( $GLOBALS['is_IE'] ) {

			wp_enqueue_style( 'wpcw-admin-ie', \Contact_Widgets::$assets_url . "css/admin-ie{$suffix}.css", [ 'wpcw-admin' ], Plugin::$version );

		}

	}

	/**
	 * Print customizer script
	 */
	public function print_customizer_scripts() {

		$this->enqueue_scripts();

		wp_print_styles( [ 'font-awesome', 'wpcw-admin', 'wpcw-admin-ie' ] );
		wp_print_scripts( 'wpcw-admin' );

	}

	/**
	 * Enqueue scripts and styles for front-end use
	 *
	 * @action wp_enqueue_scripts
	 */
	public function front_end_enqueue_scripts() {

		$suffix = SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_style( 'wpcw', \Contact_Widgets::$assets_url . "css/style{$suffix}.css", [], Plugin::$version );

		if ( is_customize_preview() ) {

			if ( ! wp_script_is( 'jquery', 'enqueued' ) ) {

				wp_enqueue_script( 'jquery' );

			}

			wp_enqueue_script( 'wpcw-helper', \Contact_Widgets::$assets_url . "js/customize-preview-helper{$suffix}.js", [ 'jquery' ], Plugin::$version, true );

		}

	}

}
