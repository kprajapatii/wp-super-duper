<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Super_Duper' ) ) {

	define( 'SUPER_DUPER_VER', '1.2.24' );

	/**
	 * A Class to be able to create a Widget, Shortcode or Block to be able to output content for WordPress.
	 *
	 * Should not be called direct but extended instead.
	 *
	 * Class WP_Super_Duper
	 * @since 1.0.16 change log moved to file change-log.txt - CHANGED
	 * @ver 1.1.1
	 */
	class WP_Super_Duper extends WP_Widget {

		public $version = SUPER_DUPER_VER;
		public $font_awesome_icon_version = "5.11.2";
		public $block_code;
		public $options;
		public $base_id;
		public $settings_hash;
		public $arguments = array();
		public $instance = array();
		private $class_name;

		/**
		 * The relative url to the current folder.
		 *
		 * @var string
		 */
		public $url = '';

		/**
		 * Take the array options and use them to build.
		 */
		public function __construct( $options ) {
			global $sd_widgets;

			$sd_widgets[ $options['base_id'] ] = array(
				'name'       => $options['name'],
				'class_name' => $options['class_name'],
				'output_types' => !empty($options['output_types']) ? $options['output_types'] : array()
			);
			$this->base_id                     = $options['base_id'];
			// lets filter the options before we do anything
			$options       = apply_filters( "wp_super_duper_options", $options );
			$options       = apply_filters( "wp_super_duper_options_{$this->base_id}", $options );
			$options       = $this->add_name_from_key( $options );
			$this->options = $options;

			$this->base_id   = $options['base_id'];
			$this->arguments = isset( $options['arguments'] ) ? $options['arguments'] : array();

			// nested blocks can't work as a widget
			if(!empty($this->options['nested-block'])){
				if(empty($this->options['output_types'])){
					$this->options['output_types'] = array('shortcode','block');
				}elseif (($key = array_search('widget', $this->options['output_types'])) !== false) {
					unset($this->options['output_types'][$key]);
				}
			}

			// init parent
			if(empty($this->options['output_types']) || in_array('widget',$this->options['output_types'])){
				parent::__construct( $options['base_id'], $options['name'], $options['widget_ops'] );
			}


			if ( isset( $options['class_name'] ) ) {
				// register widget
				$this->class_name = $options['class_name'];

				// register shortcode, this needs to be done even for blocks and widgets
				$this->register_shortcode();


				// Fusion Builder (avada) support
				if ( function_exists( 'fusion_builder_map' ) ) {
					add_action( 'init', array( $this, 'register_fusion_element' ) );
				}

                // maybe load the Bricks transformer class
                if( class_exists('\Bricks\Elements', false) ){
					add_action( 'init', array( $this, 'load_bricks_element_class' ) );
                }

				// register block
				if(empty($this->options['output_types']) || in_array('block',$this->options['output_types'])){
					add_action( 'admin_enqueue_scripts', array( $this, 'register_block' ) );
				}
			}

			// add the CSS and JS we need ONCE
			global $sd_widget_scripts;

			if ( ! $sd_widget_scripts ) {
				wp_add_inline_script( 'admin-widgets', $this->widget_js() );
				wp_add_inline_script( 'customize-controls', $this->widget_js() );
				wp_add_inline_style( 'widgets', $this->widget_css() );

				// maybe add elementor editor styles
				add_action( 'elementor/editor/after_enqueue_styles', array( $this, 'elementor_editor_styles' ) );

				$sd_widget_scripts = true;

				// add shortcode insert button once
				add_action( 'media_buttons', array( $this, 'wp_media_buttons' ), 1 );
				add_action( 'media_buttons', array( $this, 'shortcode_insert_button' ) );
				// generatepress theme sections compatibility
				if ( function_exists( 'generate_sections_sections_metabox' ) ) {
					add_action( 'generate_sections_metabox', array( $this, 'shortcode_insert_button_script' ) );
				}

				/* Load script on Divi theme builder page */
				if ( ( function_exists( 'et_builder_is_tb_admin_screen' ) && et_builder_is_tb_admin_screen() ) || ( function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled() && isset( $_GET['et_fb'] ) && '1' === $_GET['et_fb'] && et_pb_is_allowed( 'use_visual_builder' ) ) ) {
					add_thickbox();
					add_action( 'admin_footer', array( $this, 'shortcode_insert_button_script' ) );
				}

				if ( $this->is_preview() ) {
					add_action( 'wp_footer', array( $this, 'shortcode_insert_button_script' ) );
					// this makes the insert button work for elementor
					add_action( 'elementor/editor/after_enqueue_scripts', array(
						$this,
						'shortcode_insert_button_script'
					) ); // for elementor
				}
				// this makes the insert button work for cornerstone
				add_action( 'wp_print_footer_scripts', array( __CLASS__, 'maybe_cornerstone_builder' ) );

				add_action( 'wp_ajax_super_duper_get_widget_settings', array( __CLASS__, 'get_widget_settings' ) );
				add_action( 'wp_ajax_super_duper_get_picker', array( __CLASS__, 'get_picker' ) );

				// add generator text to head
				add_action( 'admin_head', array( $this, 'generator' ), 99 );
				add_action( 'wp_head', array( $this, 'generator' ), 99 );
			}

			do_action( 'wp_super_duper_widget_init', $options, $this );
		}

        /**
         * Load the Bricks conversion class if we are running Bricks.
         * @return void
         */
        public function load_bricks_element_class() {
                    include_once __DIR__ . '/includes/class-super-duper-bricks-element.php';
        }

		/**
		 * The register widget function
		 * @return void
		 */
		public function _register() {
			if(empty($this->options['output_types']) || in_array('widget',$this->options['output_types'])){
				parent::_register();
			}
		}

		/**
		 * Add our widget CSS to elementor editor.
		 */
		public function elementor_editor_styles() {
			wp_add_inline_style( 'elementor-editor', $this->widget_css( false ) );
		}

		public function register_fusion_element() {

			$options = $this->options;

			if ( $this->base_id ) {

				$params = $this->get_fusion_params();

				$args = array(
					'name'            => $options['name'],
					'shortcode'       => $this->base_id,
					'icon'            => $options['block-icon'] ? $options['block-icon'] : 'far fa-square',
					'allow_generator' => true,
				);

				if ( ! empty( $params ) ) {
					$args['params'] = $params;
				}

				fusion_builder_map( $args );
			}

		}

		public function get_fusion_params() {
			$params    = array();
			$arguments = $this->get_arguments();

			if ( ! empty( $arguments ) ) {
				foreach ( $arguments as $key => $val ) {
					$param = array();
					// type
					$param['type'] = str_replace(
						array(
							"text",
							"number",
							"email",
							"color",
							"checkbox"
						),
						array(
							"textfield",
							"textfield",
							"textfield",
							"colorpicker",
							"select",

						),
						$val['type'] );

					// multiselect
					if ( $val['type'] == 'multiselect' || ( ( $param['type'] == 'select' || $val['type'] == 'select' ) && ! empty( $val['multiple'] ) ) ) {
						$param['type']     = 'multiple_select';
						$param['multiple'] = true;
					}

					// heading
					$param['heading'] = isset( $val['title'] ) ? $val['title'] : '';

					// description
					$param['description'] = isset( $val['desc'] ) ? $val['desc'] : '';

					// param_name
					$param['param_name'] = $key;

					// Default
					$param['default'] = isset( $val['default'] ) ? $val['default'] : '';

					// Group
					if ( isset( $val['group'] ) ) {
						$param['group'] = $val['group'];
					}

					// value
					if ( $val['type'] == 'checkbox' ) {
						if ( isset( $val['default'] ) && $val['default'] == '0' ) {
							unset( $param['default'] );
						}
						$param['value'] = array( '0' => __( "No", 'ayecode-connect' ), '1' => __( "Yes", 'ayecode-connect' ) );
					} elseif ( $param['type'] == 'select' || $param['type'] == 'multiple_select' ) {
						$param['value'] = isset( $val['options'] ) ? $val['options'] : array();
					} else {
						$param['value'] = isset( $val['default'] ) ? $val['default'] : '';
					}

					// setup the param
					$params[] = $param;

				}
			}


			return $params;
		}

		/**
		 * Maybe insert the shortcode inserter button in the footer if we are in the cornerstone builder
		 */
		public static function maybe_cornerstone_builder() {
			if ( did_action( 'cornerstone_before_boot_app' ) ) {
				self::shortcode_insert_button_script();
			}
		}

		/**
		 * A function to ge the shortcode builder picker html.
		 *
		 * @param string $editor_id
		 *
		 * @return string
		 */
		public static function get_picker( $editor_id = '' ) {

			ob_start();
			if ( isset( $_POST['editor_id'] ) ) {
				$editor_id = esc_attr( $_POST['editor_id'] );
			} elseif ( isset( $_REQUEST['et_fb'] ) ) {
				$editor_id = 'main_content_content_vb_tiny_mce';
			}

			global $sd_widgets;

//			print_r($sd_widgets);exit;
			?>

			<div class="sd-shortcode-left-wrap">
				<?php
				ksort( $sd_widgets );
				//				print_r($sd_widgets);exit;
				if ( ! empty( $sd_widgets ) ) {
					echo '<select class="widefat" onchange="sd_get_shortcode_options(this);">';
					echo "<option>" . __( 'Select shortcode', 'ayecode-connect' ) . "</option>";
					foreach ( $sd_widgets as $shortcode => $class ) {
						if(!empty($class['output_types']) && !in_array('shortcode', $class['output_types'])){ continue; }
						echo "<option value='" . esc_attr( $shortcode ) . "'>" . esc_attr( $shortcode ) . " (" . esc_attr( $class['name'] ) . ")</option>";
					}
					echo "</select>";

				}
				?>
				<div class="sd-shortcode-settings"></div>
			</div>
			<div class="sd-shortcode-right-wrap">
				<textarea id='sd-shortcode-output' disabled></textarea>
				<div id='sd-shortcode-output-actions'>
					<?php if ( $editor_id != '' ) { ?>
						<button class="button sd-insert-shortcode-button" onclick="sd_insert_shortcode(<?php if ( ! empty( $editor_id ) ) { echo "'" . $editor_id . "'"; } ?>)"><?php esc_html_e( 'Insert shortcode', 'ayecode-connect' ); ?></button>
					<?php } ?>
					<button class="button" onclick="sd_copy_to_clipboard()"><?php esc_html_e( 'Copy shortcode' ); ?></button>
				</div>
			</div>
			<?php
			$html = ob_get_clean();

			if ( wp_doing_ajax() ) {
				echo $html;
				$should_die = true;

				// some builder get the editor via ajax so we should not die on those occasions
				$dont_die = array(
					'parent_tag',// WP Bakery
					'avia_request' // enfold
				);

				foreach ( $dont_die as $request ) {
					if ( isset( $_REQUEST[ $request ] ) ) {
						$should_die = false;
					}
				}

				if ( $should_die ) {
					wp_die();
				}
			} else {
				return $html;
			}

			return '';
		}

		/**
		 * Output the version in the header.
		 */
		public function generator() {
			$file = str_replace( array( "/", "\\" ), "/", realpath( __FILE__ ) );
			$plugins_dir = str_replace( array( "/", "\\" ), "/", realpath( WP_PLUGIN_DIR ) );

			// Find source plugin/theme of SD
			$source = array();
			if ( strpos( $file, $plugins_dir ) !== false ) {
				$source = explode( "/", plugin_basename( $file ) );
			} else if ( function_exists( 'get_theme_root' ) ) {
				$themes_dir = str_replace( array( "/", "\\" ), "/", realpath( get_theme_root() ) );

				if ( strpos( $file, $themes_dir ) !== false ) {
					$source = explode( "/", ltrim( str_replace( $themes_dir, "", $file ), "/" ) );
				}
			}

			echo '<meta name="generator" content="WP Super Duper v' . esc_attr( $this->version ) . '"' . ( ! empty( $source[0] ) ? ' data-sd-source="' . esc_attr( $source[0] ) . '"' : '' ) . ' />';
		}

		/**
		 * Get widget settings.
		 *
		 * @since 1.0.0
		 */
		public static function get_widget_settings() {
			global $sd_widgets;

			$shortcode = isset( $_REQUEST['shortcode'] ) && $_REQUEST['shortcode'] ? sanitize_title_with_dashes( $_REQUEST['shortcode'] ) : '';
			if ( ! $shortcode ) {
				wp_die();
			}
			$widget_args = isset( $sd_widgets[ $shortcode ] ) ? $sd_widgets[ $shortcode ] : '';
			if ( ! $widget_args ) {
				wp_die();
			}
			$class_name = isset( $widget_args['class_name'] ) && $widget_args['class_name'] ? $widget_args['class_name'] : '';
			if ( ! $class_name ) {
				wp_die();
			}

			// invoke an instance method
			$widget = new $class_name;

			ob_start();
			$widget->form( array() );
			$form = ob_get_clean();
			echo "<form id='$shortcode'>" . $form . "<div class=\"widget-control-save\"></div></form>";
			echo "<style>" . $widget->widget_css() . "</style>";
			echo "<script>" . $widget->widget_js() . "</script>";
			?>
			<?php
			wp_die();
		}

		/**
		 * Insert shortcode builder button to classic editor (not inside Gutenberg, not needed).
		 *
		 * @param string $editor_id Optional. Shortcode editor id. Default null.
		 * @param string $insert_shortcode_function Optional. Insert shortcode function. Default null.
		 *
		 *@since 1.0.0
		 *
		 */
		public static function shortcode_insert_button( $editor_id = '', $insert_shortcode_function = '' ) {
			global $sd_widgets, $shortcode_insert_button_once;
			if ( $shortcode_insert_button_once ) {
				return;
			}
			add_thickbox();

			/**
			 * Cornerstone makes us play dirty tricks :/
			 * All media_buttons are removed via JS unless they are two specific id's so we wrap our content in this ID so it is not removed.
			 */
			if ( function_exists( 'cornerstone_plugin_init' ) && ! is_admin() ) {
				echo '<span id="insert-media-button">';
			}

			echo self::shortcode_button( 'this', 'true' );

			// see opening note
			if ( function_exists( 'cornerstone_plugin_init' ) && ! is_admin() ) {
				echo '</span>'; // end #insert-media-button
			}

			// Add separate script for generatepress theme sections
			if ( function_exists( 'generate_sections_sections_metabox' ) && did_action( 'generate_sections_metabox' ) ) {
			} else {
				self::shortcode_insert_button_script( $editor_id, $insert_shortcode_function );
			}

			$shortcode_insert_button_once = true;
		}

		/**
		 * Gets the shortcode insert button html.
		 *
		 * @param string $id
		 * @param string $search_for_id
		 *
		 * @return mixed
		 */
		public static function shortcode_button( $id = '', $search_for_id = '' ) {
			ob_start();
			?>
			<span class="sd-lable-shortcode-inserter">
				<a onclick="sd_ajax_get_picker(<?php echo $id;
				if ( $search_for_id ) {
					echo "," . $search_for_id;
				} ?>);" href="#TB_inline?width=100%&height=550&inlineId=super-duper-content-ajaxed"
				   class="thickbox button super-duper-content-open" title="Add Shortcode">
					<span style="vertical-align: middle;line-height: 18px;font-size: 20px;"
						  class="dashicons dashicons-screenoptions"></span>
				</a>
				<div id="super-duper-content-ajaxed" style="display:none;">
					<span>Loading</span>
				</div>
			</span>

			<?php
			$html = ob_get_clean();

			// remove line breaks so we can use it in js
			return preg_replace( "/\r|\n/", "", trim( $html ) );
		}

		/**
		 * Makes SD work with the siteOrigin page builder.
		 *
		 * @return mixed
		 *@since 1.0.6
		 */
		public static function siteorigin_js() {
			ob_start();
			?>
			<script>
				/**
				 * Check a form to see what items should be shown or hidden.
				 */
				function sd_so_show_hide(form) {
					jQuery(form).find(".sd-argument").each(function () {
						var $element_require = jQuery(this).data('element_require');

						if ($element_require) {
							$element_require = $element_require.replace("&#039;", "'"); // replace single quotes
							$element_require = $element_require.replace("&quot;", '"'); // replace double quotes

							if (eval($element_require)) {
								jQuery(this).removeClass('sd-require-hide');
							} else {
								jQuery(this).addClass('sd-require-hide');
							}
						}
					});
				}

				/**
				 * Toggle advanced settings visibility.
				 */
				function sd_so_toggle_advanced($this) {
					var form = jQuery($this).parents('form,.form,.so-content');
					form.find('.sd-advanced-setting').toggleClass('sd-adv-show');
					return false;// prevent form submit
				}

				/**
				 * Initialise a individual widget.
				 */
				function sd_so_init_widget($this, $selector) {
					if (!$selector) {
						$selector = 'form';
					}
					// only run once.
					if (jQuery($this).data('sd-widget-enabled')) {
						return;
					} else {
						jQuery($this).data('sd-widget-enabled', true);
					}

					var $button = '<button title="<?php _e( 'Advanced Settings', 'ayecode-connect' );?>" class="button button-primary right sd-advanced-button" onclick="sd_so_toggle_advanced(this);return false;"><i class="fas fa-sliders-h" aria-hidden="true"></i></button>';
					var form = jQuery($this).parents('' + $selector + '');

					if (jQuery($this).val() == '1' && jQuery(form).find('.sd-advanced-button').length == 0) {
						jQuery(form).append($button);
					}

					// show hide on form change
					jQuery(form).on("change", function () {
						sd_so_show_hide(form);
					});

					// show hide on load
					sd_so_show_hide(form);
				}

				jQuery(function () {
					jQuery(document).on('open_dialog', function (w, e) {
						setTimeout(function () {
							if (jQuery('.so-panels-dialog-wrapper:visible .so-content.panel-dialog .sd-show-advanced').length) {
								if (jQuery('.so-panels-dialog-wrapper:visible .so-content.panel-dialog .sd-show-advanced').val() == '1') {
									sd_so_init_widget('.so-panels-dialog-wrapper:visible .so-content.panel-dialog .sd-show-advanced', 'div');
								}
							}
						}, 200);
					});
				});
			</script>
			<?php
			$output = ob_get_clean();

			/*
			 * We only add the <script> tags for code highlighting, so we strip them from the output.
			 */

			return str_replace( array(
				'<script>',
				'</script>'
			), '', $output );
		}

		/**
		 * Output the JS and CSS for the shortcode insert button.
		 *
		 * @param string $editor_id
		 * @param string $insert_shortcode_function
		 *
		 *@since 1.0.6
		 *
		 */
		public static function shortcode_insert_button_script( $editor_id = '', $insert_shortcode_function = '' ) {
			?>
			<style>
				.sd-shortcode-left-wrap {
					float: left;
					width: 60%;
				}

				.sd-shortcode-left-wrap .gd-help-tip {
					float: none;
				}

				.sd-shortcode-left-wrap .widefat {
					border-spacing: 0;
					width: 100%;
					clear: both;
					margin: 0;
					border: 1px solid #ddd;
					box-shadow: inset 0 1px 2px rgba(0, 0, 0, .07);
					background-color: #fff;
					color: #32373c;
					outline: 0;
					transition: 50ms border-color ease-in-out;
					padding: 3px 5px;
				}

				.sd-shortcode-left-wrap input[type=checkbox].widefat {
					border: 1px solid #b4b9be;
					background: #fff;
					color: #555;
					clear: none;
					cursor: pointer;
					display: inline-block;
					line-height: 0;
					height: 16px;
					margin: -4px 4px 0 0;
					margin-top: 0;
					outline: 0;
					padding: 0 !important;
					text-align: center;
					vertical-align: middle;
					width: 16px;
					min-width: 16px;
					-webkit-appearance: none;
					box-shadow: inset 0 1px 2px rgba(0, 0, 0, .1);
					transition: .05s border-color ease-in-out;
				}

				.sd-shortcode-left-wrap input[type=checkbox]:checked:before {
					content: "\f147";
					margin: -3px 0 0 -4px;
					color: #1e8cbe;
					float: left;
					display: inline-block;
					vertical-align: middle;
					width: 16px;
					font: normal 21px/1 dashicons;
					speak: none;
					-webkit-font-smoothing: antialiased;
					-moz-osx-font-smoothing: grayscale;
				}

				#sd-shortcode-output-actions button,
				.sd-advanced-button {
					color: #555;
					border-color: #ccc;
					background: #f7f7f7;
					box-shadow: 0 1px 0 #ccc;
					vertical-align: top;
					display: inline-block;
					text-decoration: none;
					font-size: 13px;
					line-height: 26px;
					height: 28px;
					margin: 0;
					padding: 0 10px 1px;
					cursor: pointer;
					border-width: 1px;
					border-style: solid;
					-webkit-appearance: none;
					border-radius: 3px;
					white-space: nowrap;
					box-sizing: border-box;
				}

				button.sd-advanced-button {
					background: #0073aa;
					border-color: #006799;
					box-shadow: inset 0 2px 0 #006799;
					vertical-align: top;
					color: #fff;
					text-decoration: none;
					text-shadow: 0 -1px 1px #006799, 1px 0 1px #006799, 0 1px 1px #006799, -1px 0 1px #006799;
					float: right;
					margin-right: 3px !important;
					font-size: 20px !important;
				}

				.sd-shortcode-right-wrap {
					float: right;
					width: 35%;
				}

				#sd-shortcode-output {
					background: rgba(255, 255, 255, .5);
					border-color: rgba(222, 222, 222, .75);
					box-shadow: inset 0 1px 2px rgba(0, 0, 0, .04);
					color: rgba(51, 51, 51, .5);
					overflow: auto;
					padding: 2px 6px;
					line-height: 1.4;
					resize: vertical;
				}

				#sd-shortcode-output {
					height: 250px;
					width: 100%;
				}

				<?php if ( function_exists( 'generate_sections_sections_metabox' ) ) { ?>
				.generate-sections-modal #custom-media-buttons > .sd-lable-shortcode-inserter {
					display: inline;
				}
				<?php } ?>
				<?php if ( function_exists( 'et_builder_is_tb_admin_screen' ) && et_builder_is_tb_admin_screen() ) { ?>
				body.divi_page_et_theme_builder div#TB_window.gd-tb-window{z-index:9999999}
				<?php } ?>
			</style>
			<?php
			if ( class_exists( 'SiteOrigin_Panels' ) ) {
				echo "<script>" . self::siteorigin_js() . "</script>";
			}
			?>
			<script>
				<?php
				if(! empty( $insert_shortcode_function )){
					echo $insert_shortcode_function;
				}else{

				/**
				 * Function for super duper insert shortcode.
				 *
				 * @since 1.0.0
				 */
				?>
				function sd_insert_shortcode($editor_id) {
					$shortcode = jQuery('#TB_ajaxContent #sd-shortcode-output').val();
					if ($shortcode) {
						if (!$editor_id) {
							<?php
							if ( isset( $_REQUEST['et_fb'] ) ) {
								echo '$editor_id = "#main_content_content_vb_tiny_mce";';
							} elseif ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'elementor' ) {
								echo '$editor_id = "#elementor-controls .wp-editor-container textarea";';
							} else {
								echo '$editor_id = "#wp-content-editor-container textarea";';
							}
							?>
						} else {
							$editor_id = '#' + $editor_id;
						}
						tmceActive = jQuery($editor_id).attr("aria-hidden") == "true" ? true : false;
						/* GeneratePress */
						if (jQuery('#generate-sections-modal-dialog ' + $editor_id).length) {
							$editor_id = '#generate-sections-modal-dialog ' + $editor_id;
							tmceActive = jQuery($editor_id).closest('.wp-editor-wrap').hasClass('tmce-active') ? true : false;
						}
						if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && tmceActive) {
							tinyMCE.execCommand('mceInsertContent', false, $shortcode);
						} else {
							var $txt = jQuery($editor_id);
							var caretPos = $txt[0].selectionStart;
							var textAreaTxt = $txt.val();
							var txtToAdd = $shortcode;
							var textareaValue = textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos);
							$txt.focus().val(textareaValue).change().keydown().blur().keyup().keypress().trigger('input').trigger('change');

							// set Divi react input value
							var input = document.getElementById("main_content_content_vb_tiny_mce");
							if (input) {
								sd_setNativeValue(input, textareaValue);
							}
						}
						tb_remove();
					}
				}

				/*
				 Set the value of elements controlled via react.
				 */
				function sd_setNativeValue(element, value) {
					let lastValue = element.value;
					element.value = value;
					let event = new Event("input", {target: element, bubbles: true});
					// React 15
					event.simulated = true;
					// React 16
					let tracker = element._valueTracker;
					if (tracker) {
						tracker.setValue(lastValue);
					}
					element.dispatchEvent(event);
				}
				<?php } ?>

				/*
				 Copies the shortcode to the clipboard.
				 */
				function sd_copy_to_clipboard() {
					/* Get the text field */
					var copyText = document.querySelector("#TB_ajaxContent #sd-shortcode-output");
					//un-disable the field
					copyText.disabled = false;
					/* Select the text field */
					copyText.select();
					/* Copy the text inside the text field */
					document.execCommand("Copy");
					//re-disable the field
					copyText.disabled = true;
					/* Alert the copied text */
					alert("Copied the text: " + copyText.value);
				}

				/*
				 Gets the shortcode options.
				 */
				function sd_get_shortcode_options($this) {
					$short_code = jQuery($this).val();
					if ($short_code) {
						var data = {
							'action': 'super_duper_get_widget_settings',
							'shortcode': $short_code,
							'attributes': 123,
							'post_id': 321,
							'_ajax_nonce': '<?php echo wp_create_nonce( 'super_duper_output_shortcode' );?>'
						};

						if (typeof ajaxurl === 'undefined') {
							var ajaxurl = "<?php echo admin_url( 'admin-ajax.php' );?>";
						}

						jQuery.post(ajaxurl, data, function (response) {
							jQuery('#TB_ajaxContent .sd-shortcode-settings').html(response);

							jQuery('#' + $short_code).on('change', 'select', function () {
								sd_build_shortcode($short_code);
							}); // take care of select tags

							jQuery('#' + $short_code).on('change keypress keyup', 'input,textarea', function () {
								sd_build_shortcode($short_code);
							});

							sd_build_shortcode($short_code);

							// resize the window to fit
							setTimeout(function () {
								jQuery('#TB_ajaxContent').css('width', 'auto').css('height', '75vh');
							}, 200);

							return response;
						});
					}
				}

				/*
				 Builds and inserts the shortcode into the viewer.
				 */
				function sd_build_shortcode($id) {
					var multiSelects = {};
					var multiSelectsRemove = [];

					$output = "[" + $id;

					$form_data = jQuery("#" + $id).serializeArray();

					// run checks for multiselects
					jQuery.each($form_data, function (index, element) {
						if (element && element.value) {
							$field_name = element.name.substr(element.name.indexOf("][") + 2);
							$field_name = $field_name.replace("]", "");
							// check if its a multiple
							if ($field_name.includes("[]")) {
								multiSelectsRemove[multiSelectsRemove.length] = index;
								$field_name = $field_name.replace("[]", "");
								if ($field_name in multiSelects) {
									multiSelects[$field_name] = multiSelects[$field_name] + "," + element.value;
								} else {
									multiSelects[$field_name] = element.value;
								}
							}
						}
					});

					// fix multiselects if any are found
					if (multiSelectsRemove.length) {

						// remove all multiselects
						multiSelectsRemove.reverse();
						multiSelectsRemove.forEach(function (index) {
							$form_data.splice(index, 1);
						});

						$ms_arr = [];
						// add multiselets back
						jQuery.each(multiSelects, function (index, value) {
							$ms_arr[$ms_arr.length] = {"name": "[][" + index + "]", "value": value};
						});
						$form_data = $form_data.concat($ms_arr);
					}

					if ($form_data) {
						$content = '';
						$form_data.forEach(function (element) {
							if (element.value) {
								$field_name = element.name.substr(element.name.indexOf("][") + 2);
								$field_name = $field_name.replace("]", "");
								if ($field_name == 'html') {
									$content = element.value;
								} else {
									$output = $output + " " + $field_name + '="' + element.value + '"';
								}
							}
						});
					}
					$output = $output + "]";

					// check for content field
					if ($content) {
						$output = $output + $content + "[/" + $id + "]";
					}

					jQuery('#TB_ajaxContent #sd-shortcode-output').html($output);
				}

				/*
				 Delay the init of the textareas for 1 second.
				 */
				(function () {
					setTimeout(function () {
						sd_init_textareas();
					}, 1000);
				})();

				/*
				 Init the textareas to be able to show the shortcode builder button.
				 */
				function sd_init_textareas() {
					// General textareas
					jQuery(document).on('focus', 'textarea', function () {
						if (jQuery(this).hasClass('wp-editor-area')) {
							// insert the shortcode button to the textarea lable if not there already
							if (!(jQuery(this).parent().find('.sd-lable-shortcode-inserter').length || (jQuery(this).closest('.wp-editor-wrap').length && jQuery(this).closest('.wp-editor-wrap').find('.sd-lable-shortcode-inserter').length))) {
								jQuery(this).parent().find('.quicktags-toolbar').append(sd_shortcode_button(jQuery(this).attr('id')));
							}
						} else {
							// insert the shortcode button to the textarea lable if not there already
							if (!jQuery("label[for='" + jQuery(this).attr('id') + "']").find('.sd-lable-shortcode-inserter').length) {
								jQuery("label[for='" + jQuery(this).attr('id') + "']").append(sd_shortcode_button(jQuery(this).attr('id')));
							}
						}
					});

					// The below tries to add the shortcode builder button to the builders own raw/shortcode sections.

					// DIVI
					jQuery(document).on('focusin', '.et-fb-codemirror', function () {
						// insert the shortcode button to the textarea lable if not there already
						if (!jQuery(this).closest('.et-fb-form__group').find('.sd-lable-shortcode-inserter').length) {
							jQuery(this).closest('.et-fb-form__group').find('.et-fb-form__label-text').append(sd_shortcode_button());
						}
					});
					/* Divi 5 */
					jQuery(document).on('focusin', '.et-vb-tinymce-html-input', function () {
						var $etEl = jQuery(this).closest('.et-vb-field-richtext').find('.et-vb-field-richtext-buttons');
						if (!$etEl.find('.sd-lable-shortcode-inserter').length) {
							if ($etEl.find('.et-vb-switch-editor-mode').length) {
								$etEl.find('.et-vb-switch-editor-mode').before(sd_shortcode_button());
							} else {
								$etEl.append(sd_shortcode_button());
							}
						}
					});

					// Beaver
					jQuery(document).on('focusin', '.fl-code-field', function () {
						// insert the shortcode button to the textarea lable if not there already
						if (!jQuery(this).closest('.fl-field-control-wrapper').find('.sd-lable-shortcode-inserter').length) {
							jQuery(this).closest('.fl-field-control-wrapper').prepend(sd_shortcode_button());
						}
					});

					// Fusion builder (avada)
					jQuery(document).on('focusin', '.CodeMirror.cm-s-default', function () {
						// insert the shortcode button to the textarea lable if not there already
						if (!jQuery(this).parent().find('.sd-lable-shortcode-inserter').length) {
							jQuery(sd_shortcode_button()).insertBefore(this);
						}
					});

					// Avia builder (enfold)
					jQuery(document).on('focusin', '#aviaTBcontent', function () {
						// insert the shortcode button to the textarea lable if not there already
						if (!jQuery(this).parent().parent().find('.avia-name-description ').find('.sd-lable-shortcode-inserter').length) {
							jQuery(this).parent().parent().find('.avia-name-description strong').append(sd_shortcode_button(jQuery(this).attr('id')));
						}
					});

					// Cornerstone textareas
					jQuery(document).on('focusin', '.cs-control.cs-control-textarea', function () {
						// insert the shortcode button to the textarea lable if not there already
						if (!jQuery(this).find('.cs-control-header label').find('.sd-lable-shortcode-inserter').length) {
							jQuery(this).find('.cs-control-header label').append(sd_shortcode_button());
						}
					});

					// Cornerstone main bar
					setTimeout(function () {
						// insert the shortcode button to the textarea lable if not there already
						if (!jQuery('.cs-bar-btns').find('.sd-lable-shortcode-inserter').length) {
							jQuery('<li style="text-align: center;padding: 5px;list-style: none;">' + sd_shortcode_button() + '</li>').insertBefore('.cs-action-toggle-custom-css');
						}
					}, 2000);

					// WP Bakery, code editor does not render shortcodes.
//					jQuery(document).on('focusin', '.wpb-textarea_raw_html', function () {
//						// insert the shortcode button to the textarea lable if not there already
//						if(!jQuery(this).parent().parent().find('.wpb_element_label').find('.sd-lable-shortcode-inserter').length){
//							jQuery(this).parent().parent().find('.wpb_element_label').append(sd_shortcode_button());
//						}
//					});
				}

				/**
				 * Gets the html for the picker via ajax and updates it on the fly.
				 *
				 * @param $id
				 * @param $search
				 */
				function sd_ajax_get_picker($id, $search) {
					if ($search) {
						$this = $id;
						$id = jQuery($this).closest('.wp-editor-wrap').find('.wp-editor-container textarea').attr('id');
					}

					var data = {
						'action': 'super_duper_get_picker',
						'editor_id': $id,
						'_ajax_nonce': '<?php echo wp_create_nonce( 'super_duper_picker' );?>'
					};

					if (!ajaxurl) {
						var ajaxurl = "<?php echo admin_url( 'admin-ajax.php' ); ?>";
					}

					jQuery.post(ajaxurl, data, function (response) {
						jQuery('#TB_ajaxContent').closest('#TB_window').addClass('gd-tb-window');
						jQuery('#TB_ajaxContent').html(response);
						//return response;
					}).then(function (env) {
						jQuery('body').on('thickbox:removed', function () {
							jQuery('#super-duper-content-ajaxed').html('');
						});
					});
				}

				/**
				 * Get the html for the shortcode inserter button depending on if a textarea id is available.
				 *
				 * @param $id string The textarea id.
				 * @returns {string}
				 */
				function sd_shortcode_button($id) {
					if ($id) {
						return '<?php echo self::shortcode_button( "\\''+\$id+'\\'" );?>';
					} else {
						return '<?php echo self::shortcode_button();?>';
					}
				}
			</script>
			<?php
		}

		/**
		 * Gets some CSS for the widgets screen.
		 *
		 * @param bool $advanced If we should include advanced CSS.
		 *
		 * @return mixed
		 */
		public function widget_css( $advanced = true ) {
			ob_start();
			?>
			<style>
				<?php if( $advanced ){ ?>
				.sd-advanced-setting {
					display: none;
				}

				.sd-advanced-setting.sd-adv-show {
					display: block;
				}

				.sd-argument.sd-require-hide,
				.sd-advanced-setting.sd-require-hide {
					display: none;
				}

				button.sd-advanced-button {
					margin-right: 3px !important;
					font-size: 20px !important;
				}

				<?php } ?>

				button.sd-toggle-group-button {
					background-color: #f3f3f3;
					color: #23282d;
					cursor: pointer;
					padding: 10px;
					width: 100%;
					border: none;
					text-align: left;
					outline: none;
					font-size: 13px;
					font-weight: bold;
					margin-bottom: 1px;
				}
				.elementor-control .sd-argument select[multiple]{height:100px}
				.elementor-control .sd-argument select[multiple] option{padding:3px}
			</style>
			<?php
			$output = ob_get_clean();

			/*
			 * We only add the <script> tags for code highlighting, so we strip them from the output.
			 */

			return str_replace( array(
				'<style>',
				'</style>'
			), '', $output );
		}

		/**
		 * Gets some JS for the widgets screen.
		 *
		 * @return mixed
		 */
		public function widget_js() {
			ob_start();
			?>
			<script>

				/**
				 * Toggle advanced settings visibility.
				 */
				function sd_toggle_advanced($this) {
					var form = jQuery($this).parents('form,.form');
					form.find('.sd-advanced-setting').toggleClass('sd-adv-show');
					return false;// prevent form submit
				}

				/**
				 * Check a form to see what items should be shown or hidden.
				 */
				function sd_show_hide(form) {
					jQuery(form).find(".sd-argument").each(function () {

						var $element_require = jQuery(this).data('element_require');

						if ($element_require) {

							$element_require = $element_require.replace("&#039;", "'"); // replace single quotes
							$element_require = $element_require.replace("&quot;", '"'); // replace double quotes

							if (eval($element_require)) {
								jQuery(this).removeClass('sd-require-hide');
							} else {
								jQuery(this).addClass('sd-require-hide');
							}
						}
					});
				}

				/**
				 * Initialise widgets from the widgets screen.
				 */
				function sd_init_widgets($selector) {
					jQuery(".sd-show-advanced").each(function (index) {
						sd_init_widget(this, $selector);
					});
				}

				/**
				 * Initialise a individual widget.
				 */
				function sd_init_widget($this, $selector, $form) {
					if (!$selector) {
						$selector = 'form';
					}
					// Only run once.
					if (jQuery($this).data('sd-widget-enabled')) {
						return;
					} else {
						jQuery($this).data('sd-widget-enabled', true);
					}

					var $button = '<button title="<?php _e( 'Advanced Settings', 'ayecode-connect' );?>" style="line-height: 28px;" class="button button-primary right sd-advanced-button" onclick="sd_toggle_advanced(this);return false;"><span class="dashicons dashicons-admin-settings" style="width: 28px;font-size: 28px;"></span></button>';
					var form = $form ? $form : jQuery($this).parents('' + $selector + '');

					if (jQuery($this).val() == '1' && jQuery(form).find('.sd-advanced-button').length == 0) {
						console.log('add advanced button');
						if (jQuery(form).find('.widget-control-save').length > 0) {
							jQuery(form).find('.widget-control-save').after($button);
						} else {
							jQuery(form).find('.sd-show-advanced').after($button);
						}
					} else {
						console.log('no advanced button, ' + jQuery($this).val() + ', ' + jQuery(form).find('.sd-advanced-button').length);
					}

					/* Show hide on form change */
					jQuery(form).on("change", function() {
						sd_show_hide(form);
					});

					/* Show hide on load */
					sd_show_hide(form);
				}

				/**
				 * Init a customizer widget.
				 */
				function sd_init_customizer_widget(section) {
					if (section.expanded) {
						section.expanded.bind(function (isExpanding) {
							if (isExpanding) {
								// is it a SD widget?
								if (jQuery(section.container).find('.sd-show-advanced').length) {
									// init the widget
									sd_init_widget(jQuery(section.container).find('.sd-show-advanced'), ".form");
								}
							}
						});
					}
				}

				/**
				 * If on widgets screen.
				 */
				jQuery(function () {
					// if not in customizer.
					if (!wp.customize) {
						sd_init_widgets("form");
					}

					/* Init on widget added */
					jQuery(document).on('widget-added', function (e, widget) {
						/* Is it a SD widget? */
						if (jQuery(widget).find('.sd-show-advanced').length) {
							var widgetId = jQuery(widget).find('[name="widget-id"]').length ? ': ' + jQuery(widget).find('[name="widget-id"]').val() : '';
							console.log('widget added' + widgetId);
							/* Init the widget */
							sd_init_widget(jQuery(widget).find('.sd-show-advanced'), "form", jQuery(widget).find('.sd-show-advanced').closest('form'));
						}
					});

					/* Init on widget updated */
					jQuery(document).on('widget-updated', function (e, widget) {
						/* Is it a SD widget? */
						if (jQuery(widget).find('.sd-show-advanced').length) {
							var widgetId = jQuery(widget).find('[name="widget-id"]').length ? ': ' + jQuery(widget).find('[name="widget-id"]').val() : '';
							console.log('widget updated' + widgetId);
							/* Init the widget */
							sd_init_widget(jQuery(widget).find('.sd-show-advanced'), "form", jQuery(widget).find('.sd-show-advanced').closest('form'));
						}
					});
				});

				/**
				 * We need to run this before jQuery is ready
				 */
				if (wp.customize) {
					wp.customize.bind('ready', function () {

						// init widgets on load
						wp.customize.control.each(function (section) {
							sd_init_customizer_widget(section);
						});

						// init widgets on add
						wp.customize.control.bind('add', function (section) {
							sd_init_customizer_widget(section);
						});

					});

				}
				<?php do_action( 'wp_super_duper_widget_js', $this ); ?>
			</script>
			<?php
			$output = ob_get_clean();

			/*
			 * We only add the <script> tags for code highlighting, so we strip them from the output.
			 */

			return str_replace( array(
				'<script>',
				'</script>'
			), '', $output );
		}


		/**
		 * Set the name from the argument key.
		 *
		 * @param $options
		 *
		 * @return mixed
		 */
		private function add_name_from_key( $options, $arguments = false ) {
			if ( ! empty( $options['arguments'] ) ) {
				foreach ( $options['arguments'] as $key => $val ) {
					$options['arguments'][ $key ]['name'] = $key;
				}
			} elseif ( $arguments && is_array( $options ) && ! empty( $options ) ) {
				foreach ( $options as $key => $val ) {
					$options[ $key ]['name'] = $key;
				}
			}

			return $options;
		}

		/**
		 * Register the parent shortcode.
		 *
		 * @since 1.0.0
		 */
		public function register_shortcode() {
			add_shortcode( $this->base_id, array( $this, 'shortcode_output' ) );
			add_action( 'wp_ajax_super_duper_output_shortcode', array( $this, 'render_shortcode' ) );
		}

		/**
		 * Render the shortcode via ajax so we can return it to Gutenberg.
		 *
		 * @since 1.0.0
		 */
		public function render_shortcode() {
			check_ajax_referer( 'super_duper_output_shortcode', '_ajax_nonce', true );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die();
			}

			// we might need the $post value here so lets set it.
			if ( isset( $_POST['post_id'] ) && $_POST['post_id'] ) {
				$post_obj = get_post( absint( $_POST['post_id'] ) );
				if ( ! empty( $post_obj ) && empty( $post ) ) {
					global $post;
					$post = $post_obj;
				}
			}

			if ( isset( $_POST['shortcode'] ) && $_POST['shortcode'] ) {
				$is_preview = $this->is_preview();
				$shortcode_name   = sanitize_title_with_dashes( $_POST['shortcode'] );
				$attributes_array = isset( $_POST['attributes'] ) && $_POST['attributes'] ? $_POST['attributes'] : array();
				$attributes       = '';
				if ( ! empty( $attributes_array ) ) {
					foreach ( $attributes_array as $key => $value ) {
						if ( is_array( $value ) ) {
							$value = implode( ",", $value );
						}

						if ( ! empty( $value ) ) {
							$value = wp_unslash( $value );

							// Encode [ and ].
							if ( $is_preview ) {
								$value = $this->encode_shortcodes( $value );
							}
						}
						$attributes .= " " . esc_attr( sanitize_title_with_dashes( $key ) ) . "='" . esc_attr( $value ) . "' ";
					}
				}

				$shortcode = "[" . esc_attr( $shortcode_name ) . " " . $attributes . "]";

				$content = do_shortcode( $shortcode );

				// Decode [ and ].
				if ( ! empty( $content ) && $is_preview ) {
					$content = $this->decode_shortcodes( $content );
				}

				echo $content;
			}
			wp_die();
		}

		/**
		 * Output the shortcode.
		 *
		 * @param array $args
		 * @param string $content
		 *
		 * @return string
		 */
		public function shortcode_output( $args = array(), $content = '' ) {
			$_instance = $args;

			$args = $this->argument_values( $args );

			// add extra argument so we know its a output to gutenberg
			//$args
			$args = $this->string_to_bool( $args );

			// if we have a enclosed shortcode we add it to the special `html` argument
			if ( ! empty( $content ) ) {
				$args['html'] = $content;
			}

			if ( ! $this->is_preview() ) {
				/**
				 * Filters the settings for a particular widget args.
				 *
				 * @param array          $args      The current widget instance's settings.
				 * @param WP_Super_Duper $widget    The current widget settings.
				 * @param array          $_instance An array of default widget arguments.
				 *
				 *@since 1.0.28
				 *
				 */
				$args = apply_filters( 'wp_super_duper_widget_display_callback', $args, $this, $_instance );

				if ( ! is_array( $args ) ) {
					return $args;
				}
			}

			$class = isset( $this->options['widget_ops']['classname'] ) ? esc_attr( $this->options['widget_ops']['classname'] ) : '';
			$class .= " sdel-".$this->get_instance_hash();

			$class = apply_filters( 'wp_super_duper_div_classname', $class, $args, $this );
			$class = apply_filters( 'wp_super_duper_div_classname_' . $this->base_id, $class, $args, $this );

			$attrs = apply_filters( 'wp_super_duper_div_attrs', '', $args, $this );
			$attrs = apply_filters( 'wp_super_duper_div_attrs_' . $this->base_id, '', $args, $this );

			$shortcode_args = array();
			$output         = '';
			$no_wrap        = isset( $this->options['no_wrap'] ) && $this->options['no_wrap'] ? true : false;
			if ( isset( $args['no_wrap'] ) && $args['no_wrap'] ) {
				$no_wrap = true;
			}
			$main_content = $this->output( $args, $shortcode_args, $content );
			if ( $main_content && ! $no_wrap ) {
				// wrap the shortcode in a div with the same class as the widget
				$output .= '<div class="' . $class . '" ' . $attrs . '>';
				if ( ! empty( $args['title'] ) ) {
					// if its a shortcode and there is a title try to grab the title wrappers
					$shortcode_args = array( 'before_title' => '', 'after_title' => '' );
					if ( empty( $instance ) ) {
						global $wp_registered_sidebars;
						if ( ! empty( $wp_registered_sidebars ) ) {
							foreach ( $wp_registered_sidebars as $sidebar ) {
								if ( ! empty( $sidebar['before_title'] ) ) {
									$shortcode_args['before_title'] = $sidebar['before_title'];
									$shortcode_args['after_title']  = $sidebar['after_title'];
									break;
								}
							}
						}
					}
					$output .= $this->output_title( $shortcode_args, $args );
				}
				$output .= $main_content;
				$output .= '</div>';
			} elseif ( $main_content && $no_wrap ) {
				$output .= $main_content;
			}

			// if preview show a placeholder if empty
			if ( $this->is_preview() && $output == '' ) {
				$output = $this->preview_placeholder_text( "{{" . $this->base_id . "}}" );
			}

			return apply_filters( 'wp_super_duper_widget_output', $output, $args, $shortcode_args, $this );
		}

		/**
		 * Placeholder text to show if output is empty and we are on a preview/builder page.
		 *
		 * @param string $name
		 *
		 * @return string
		 */
		public function preview_placeholder_text( $name = '' ) {
			return "<div style='background:#0185ba33;padding: 10px;border: 4px #ccc dashed;'>" . wp_sprintf( __( 'Placeholder for: %s', 'ayecode-connect' ), $name ) . "</div>";
		}

		/**
		 * Sometimes booleans values can be turned to strings, so we fix that.
		 *
		 * @param $options
		 *
		 * @return mixed
		 */
		public function string_to_bool( $options ) {
			// convert bool strings to booleans
			foreach ( $options as $key => $val ) {
				if ( $val == 'false' ) {
					$options[ $key ] = false;
				} elseif ( $val == 'true' ) {
					$options[ $key ] = true;
				}
			}

			return $options;
		}

		/**
		 * Get the argument values that are also filterable.
		 *
		 * @param $instance
		 *
		 * @return array
		 *@since 1.0.12 Don't set checkbox default value if the value is empty.
		 *
		 */
		public function argument_values( $instance ) {
			$argument_values = array();

			// set widget instance
			$this->instance = $instance;

			if ( empty( $this->arguments ) ) {
				$this->arguments = $this->get_arguments();
			}

			if ( ! empty( $this->arguments ) ) {
				foreach ( $this->arguments as $key => $args ) {
					// set the input name from the key
					$args['name'] = $key;
					//
					$argument_values[ $key ] = isset( $instance[ $key ] ) ? $instance[ $key ] : '';
					if ( $args['type'] == 'checkbox' && $argument_values[ $key ] == '' ) {
						// don't set default for an empty checkbox
					} elseif ( $argument_values[ $key ] == '' && isset( $args['default'] ) ) {
						$argument_values[ $key ] = $args['default'];
					}
				}
			}

			return $argument_values;
		}

		/**
		 * Set arguments in super duper.
		 *
		 * @return array Set arguments.
		 *@since 1.0.0
		 *
		 */
		public function set_arguments() {
			return $this->arguments;
		}

		/**
		 * Get arguments in super duper.
		 *
		 * @return array Get arguments.
		 *@since 1.0.0
		 *
		 */
		public function get_arguments() {
			if ( empty( $this->arguments ) ) {
				$this->arguments = $this->set_arguments();
			}

			$this->arguments = apply_filters( 'wp_super_duper_arguments', $this->arguments, $this->options, $this->instance );
			$this->arguments = $this->add_name_from_key( $this->arguments, true );

            if( !empty( $this->arguments['title']['value'] ) ){
                $this->arguments['title']['value'] = wp_kses_post( $this->arguments['title']['value'] );
            }

			return $this->arguments;
		}

		/**
		 * This is the main output class for all 3 items, widget, shortcode and block, it is extended in the calling class.
		 *
		 * @param array $args
		 * @param array $widget_args
		 * @param string $content
		 */
		public function output( $args = array(), $widget_args = array(), $content = '' ) {

		}

		/**
		 * Add the dynamic block code inline when the wp-block in enqueued.
		 */
		public function register_block() {
			wp_add_inline_script( 'wp-blocks', $this->block() );
			if ( class_exists( 'SiteOrigin_Panels' ) ) {
				wp_add_inline_script( 'wp-blocks', $this->siteorigin_js() );
			}
		}

		/**
		 * Check if we need to show advanced options.
		 *
		 * @return bool
		 */
		public function block_show_advanced() {

			$show      = false;
			$arguments = $this->get_arguments();

			if ( ! empty( $arguments ) ) {
				foreach ( $arguments as $argument ) {
					if ( isset( $argument['advanced'] ) && $argument['advanced'] ) {
						$show = true;
						break; // no need to continue if we know we have it
					}
				}
			}

			return $show;
		}

		/**
		 * Get the url path to the current folder.
		 *
		 * @return string
		 */
		public function get_url() {
			$url = $this->url;

			if ( ! $url ) {
				$content_dir = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
				$content_url = untrailingslashit( WP_CONTENT_URL );

				// Replace http:// to https://.
				if ( strpos( $content_url, 'http://' ) === 0 && strpos( plugins_url(), 'https://' ) === 0 ) {
					$content_url = str_replace( 'http://', 'https://', $content_url );
				}

				// Check if we are inside a plugin
				$file_dir = str_replace( "/includes", "", wp_normalize_path( dirname( __FILE__ ) ) );
				$url = str_replace( $content_dir, $content_url, $file_dir );
				$url = trailingslashit( $url );
				$this->url = $url;
			}

			return $url;
		}

		/**
		 * Get the url path to the current folder.
		 *
		 * @return string
		 */
		public function get_url_old() {

			$url = $this->url;

			if ( ! $url ) {
				// check if we are inside a plugin
				$file_dir = str_replace( "/includes", "", dirname( __FILE__ ) );

				$dir_parts = explode( "/wp-content/", $file_dir );
				$url_parts = explode( "/wp-content/", plugins_url() );

				if ( ! empty( $url_parts[0] ) && ! empty( $dir_parts[1] ) ) {
					$url       = trailingslashit( $url_parts[0] . "/wp-content/" . $dir_parts[1] );
					$this->url = $url;
				}
			}


			return $url;
		}

		/**
		 * Generate the block icon.
		 *
		 * Enables the use of Font Awesome icons.
		 *
		 * @note xlink:href is actually deprecated but href is not supported by all so we use both.
		 *
		 * @param $icon
		 *
		 * @return string
		 *@since 1.1.0
		 */
		public function get_block_icon( $icon ) {

			// check if we have a Font Awesome icon
			$fa_type = '';
			if ( substr( $icon, 0, 7 ) === "fas fa-" ) {
				$fa_type = 'solid';
			} elseif ( substr( $icon, 0, 7 ) === "far fa-" ) {
				$fa_type = 'regular';
			} elseif ( substr( $icon, 0, 7 ) === "fab fa-" ) {
				$fa_type = 'brands';
			} else {
				$icon = "'" . $icon . "'";
			}

			// set the icon if we found one
			if ( $fa_type ) {
				$fa_icon = str_replace( array( "fas fa-", "far fa-", "fab fa-" ), "", $icon );
				$icon    = "el('svg',{width: 20, height: 20, viewBox: '0 0 20 20'},el('use', {'xlink:href': '" . $this->get_url() . "icons/" . $fa_type . ".svg#" . $fa_icon . "','href': '" . $this->get_url() . "icons/" . $fa_type . ".svg#" . $fa_icon . "'}))";
			}

			return $icon;
		}

		public function group_arguments( $arguments ) {
			if ( ! empty( $arguments ) ) {
				$temp_arguments = array();
				$general        = __( "General", 'ayecode-connect' );
				$add_sections   = false;
				foreach ( $arguments as $key => $args ) {
					if ( isset( $args['group'] ) ) {
						$temp_arguments[ $args['group'] ][ $key ] = $args;
						$add_sections                             = true;
					} else {
						$temp_arguments[ $general ][ $key ] = $args;
					}
				}

				// only add sections if more than one
				if ( $add_sections ) {
					$arguments = $temp_arguments;
				}
			}

			return $arguments;
		}

		/**
		 * Parse used group tabs.
		 *
		 * @since 1.1.17
		 */
		public function group_block_tabs( $tabs, $arguments ) {
			if ( ! empty( $tabs ) && ! empty( $arguments ) ) {
				$has_sections = false;

				foreach ( $this->arguments as $key => $args ) {
					if ( isset( $args['group'] ) ) {
						$has_sections = true;
						break;
					}
				}

				if ( ! $has_sections ) {
					return $tabs;
				}

				$new_tabs = array();

				foreach ( $tabs as $tab_key => $tab ) {
					$new_groups = array();

					if ( ! empty( $tab['groups'] ) && is_array( $tab['groups'] ) ) {
						foreach ( $tab['groups'] as $group ) {
							if ( isset( $arguments[ $group ] ) ) {
								$new_groups[] = $group;
							}
						}
					}

					if ( ! empty( $new_groups ) ) {
						$tab['groups'] = $new_groups;

						$new_tabs[ $tab_key ] = $tab;
					}
				}

				$tabs = $new_tabs;
			}

			return $tabs;
		}

		/**
		 * Output the JS for building the dynamic Guntenberg block.
		 *
		 * @return mixed
		 *@since 1.0.9 Save numbers as numbers and not strings.
		 * @since 1.1.0 Font Awesome classes can be used for icons.
		 * @since 1.0.4 Added block_wrap property which will set the block wrapping output element ie: div, span, p or empty for no wrap.
		 */
		public function block() {
			global $sd_is_js_functions_loaded, $aui_bs5;

			$show_advanced = $this->block_show_advanced();

			ob_start();
			?>
			<script>
			<?php
			if ( ! $sd_is_js_functions_loaded ) {
				$sd_is_js_functions_loaded = true;
			?>
function sd_show_view_options($this){
	if(jQuery($this).html().length){
		jQuery($this).html('');
	}else{
		jQuery($this).html('<div class="position-absolute d-flex flex-column bg-white p-1 rounded border shadow-lg " style="top:-80px;left:-5px;"><div class="dashicons dashicons-desktop mb-1" onclick="sd_set_view_type(\'Desktop\');"></div><div class="dashicons dashicons-tablet mb-1" onclick="sd_set_view_type(\'Tablet\');"></div><div class="dashicons dashicons-smartphone" onclick="sd_set_view_type(\'Mobile\');"></div></div>');
	}
}

function sd_set_view_type($device){
    const wpVersion = '<?php global $wp_version; echo esc_attr($wp_version); ?>';
    if (parseFloat(wpVersion) < 6.5) {
        wp.data.dispatch('core/edit-site') ? wp.data.dispatch('core/edit-site').__experimentalSetPreviewDeviceType($device) : wp.data.dispatch('core/edit-post').__experimentalSetPreviewDeviceType($device);
    } else {
        const editorDispatch = wp.data.dispatch('core/editor');
        if (editorDispatch) {
            editorDispatch.setDeviceType($device);
        }
    }
}

jQuery(function(){
	sd_block_visibility_init();
});
function sd_block_visibility_init() {
	jQuery(document).off('change', '.bs-vc-modal-form').on('change', '.bs-vc-modal-form', function() {
		try {
			aui_conditional_fields('.bs-vc-modal-form');
		} catch(err) {
			console.log(err.message);
		}
	});

	jQuery(document).off('click', '.bs-vc-save').on('click', '.bs-vc-save', function() {
		var $bsvcModal = jQuery(this).closest('.bs-vc-modal'), $bsvcForm = $bsvcModal.find('.bs-vc-modal-form'), vOutput = jQuery('#bsvc_output', $bsvcForm).val(), vOutputN = jQuery('#bsvc_output_n', $bsvcForm).val(), rawValue = '', oVal = {}, oOut = {}, oOutN = {}, iRule = 0;
		jQuery(this).addClass('disabled');
		jQuery('.bs-vc-modal-form .bs-vc-rule-sets .bs-vc-rule').each(function(){
			vRule = jQuery(this).find('.bsvc_rule').val(), oRule = {};
			if (vRule == 'logged_in' || vRule == 'logged_out' || vRule == 'post_author') {
				oRule.type = vRule;
			} else if (vRule == 'user_roles') {
				oRule.type = vRule;
				if (jQuery(this).find('.bsvc_user_roles:checked').length) {
					var user_roles = jQuery(this).find('.bsvc_user_roles:checked').map(function() {
						return jQuery(this).val();
					}).get();
					if (user_roles && user_roles.length) {
						oRule.user_roles = user_roles.join(",");
					}
				}
			} else if (vRule == 'gd_field') {
				if (jQuery(this).find('.bsvc_gd_field ').val() && jQuery(this).find('.bsvc_gd_field_condition').val()) {
					oRule.type = vRule;
					oRule.field = jQuery(this).find('.bsvc_gd_field ').val();
					oRule.condition = jQuery(this).find('.bsvc_gd_field_condition').val();
					if (oRule.condition != 'is_empty' && oRule.condition != 'is_not_empty') {
						oRule.search = jQuery(this).find('.bsvc_gd_field_search').val();
					}
				}
			} else {
                oRule = jQuery(document).triggerHandler('sd_block_visibility_init', [vRule, oRule, jQuery(this)]);
            }

			if (Object.keys(oRule).length > 0) {
				iRule++;
				oVal['rule'+iRule] = oRule;
			}
		});
		if (vOutput == 'hide') {
			oOut.type = vOutput;
		} else if (vOutput == 'message') {
			if (jQuery('#bsvc_message', $bsvcForm).val()) {
				oOut.type = vOutput;
				oOut.message = jQuery('#bsvc_message', $bsvcForm).val();
				if (jQuery('#bsvc_message_type', $bsvcForm).val()) {
					oOut.message_type = jQuery('#bsvc_message_type', $bsvcForm).val();
				}
			}
		} else if (vOutput == 'page') {
			if (jQuery('#bsvc_page', $bsvcForm).val()) {
				oOut.type = vOutput;
				oOut.page = jQuery('#bsvc_page', $bsvcForm).val();
			}
		} else if (vOutput == 'template_part') {
			if (jQuery('#bsvc_tmpl_part', $bsvcForm).val()) {
				oOut.type = vOutput;
				oOut.template_part = jQuery('#bsvc_tmpl_part', $bsvcForm).val();
			}
		}
		if (Object.keys(oOut).length > 0) {
			oVal.output = oOut;
		}
		if (vOutputN == 'hide') {
			oOutN.type = vOutputN;
		} else if (vOutputN == 'message') {
			if (jQuery('#bsvc_message_n', $bsvcForm).val()) {
				oOutN.type = vOutputN;
				oOutN.message = jQuery('#bsvc_message_n', $bsvcForm).val();
				if (jQuery('#bsvc_message_type_n', $bsvcForm).val()) {
					oOutN.message_type = jQuery('#bsvc_message_type_n', $bsvcForm).val();
				}
			}
		} else if (vOutputN == 'page') {
			if (jQuery('#bsvc_page_n', $bsvcForm).val()) {
				oOutN.type = vOutputN;
				oOutN.page = jQuery('#bsvc_page_n', $bsvcForm).val();
			}
		} else if (vOutputN == 'template_part') {
			if (jQuery('#bsvc_tmpl_part_n', $bsvcForm).val()) {
				oOutN.type = vOutputN;
				oOutN.template_part = jQuery('#bsvc_tmpl_part_n', $bsvcForm).val();
			}
		}
		if (Object.keys(oOutN).length > 0) {
			oVal.outputN = oOutN;
		}
		if (Object.keys(oVal).length > 0) {
			rawValue = JSON.stringify(oVal);
		}
		$bsvcModal.find('[name="bsvc_raw_value"]').val(rawValue).trigger('change');
		$bsvcModal.find('.bs-vc-close').trigger('click');
	});
	jQuery(document).off('click', '.bs-vc-add-rule').on('click', '.bs-vc-add-rule', function() {
		var bsvcTmpl = jQuery('.bs-vc-rule-template').html();
		var c = parseInt(jQuery('.bs-vc-modal-form .bs-vc-rule-sets .bs-vc-rule:last').data('bs-index'));
		if (c > 0) {
			c++;
		} else {
			c = 1;
		}
		bsvcTmpl = bsvcTmpl.replace(/BSVCINDEX/g, c);
		jQuery('.bs-vc-modal-form .bs-vc-rule-sets').append(bsvcTmpl);
		jQuery('.bs-vc-modal-form .bs-vc-rule-sets .bs-vc-rule .bs-vc-sep-wrap').removeClass('d-none');
		jQuery('.bs-vc-modal-form .bs-vc-rule-sets .bs-vc-rule:first .bs-vc-sep-wrap').addClass('d-none');
		jQuery('.bs-vc-modal-form .bs-vc-rule-sets .bs-vc-rule:last').find('select').each(function(){
			if (!jQuery(this).hasClass('no-select2')) {
				jQuery(this).addClass('aui-select2');
			}
		});
		if (!jQuery(this).hasClass('bs-vc-rendering')) {
			if(typeof aui_init_select2 == 'function') {
				aui_init_select2();
			}
			if(typeof aui_conditional_fields == 'function') {
				aui_conditional_fields('.bs-vc-modal-form');
			}
		}
	});
	jQuery(document).off('click', '.bs-vc-remove-rule').on('click', '.bs-vc-remove-rule', function() {
		jQuery(this).closest('.bs-vc-rule').remove();
	});
}
function sd_block_visibility_render_fields(oValue) {
	if (typeof oValue == 'object' && oValue.rule1 && typeof oValue.rule1 == 'object') {
		for(k = 1; k <= Object.keys(oValue).length; k++) {
			if (oValue['rule' + k] && oValue['rule' + k].type) {
				var oRule = oValue['rule' + k];
				jQuery('.bs-vc-modal-form .bs-vc-add-rule').addClass('bs-vc-rendering').trigger('click');
				var elRule = jQuery('.bs-vc-modal-form .bs-vc-rule-sets .bs-vc-rule:last');
				jQuery('select.bsvc_rule', elRule).val(oRule.type);
				if (oRule.type == 'user_roles' && oRule.user_roles) {
					var user_roles = oRule.user_roles;
					if (typeof user_roles == 'string') {
						user_roles = user_roles.split(",");
					}
					if (user_roles.length) {
						jQuery.each(user_roles, function(i, role){
							elRule.find("input[value='" + role + "']").prop('checked', true);
						});
					}
					jQuery('select.bsvc_user_roles', elRule).val(oRule.user_roles);
				} else if (oRule.type == 'gd_field') {
					if (oRule.field) {
						jQuery('select.bsvc_gd_field', elRule).val(oRule.field);
						if (oRule.condition) {
							jQuery('select.bsvc_gd_field_condition', elRule).val(oRule.condition);
							if (typeof oRule.search != 'undefined' && oRule.condition != 'is_empty' && oRule.condition != 'is_not_empty') {
								jQuery('input.bsvc_gd_field_search', elRule).val(oRule.search);
							}
						}
					}
				} else {
                    jQuery(document).trigger('sd_block_visibility_render_fields', [oRule, elRule]);
                }

				jQuery('.bs-vc-modal-form .bs-vc-add-rule').removeClass('bs-vc-rendering');
			}
		}

		if (oValue.output && oValue.output.type) {
			jQuery('.bs-vc-modal-form #bsvc_output').val(oValue.output.type);
			if (oValue.output.type == 'message' && typeof oValue.output.message != 'undefined') {
				jQuery('.bs-vc-modal-form #bsvc_message').val(oValue.output.message);
				if (typeof oValue.output.message_type != 'undefined') {
					jQuery('.bs-vc-modal-form #bsvc_message_type').val(oValue.output.message_type);
				}
			} else if (oValue.output.type == 'page' && typeof oValue.output.page != 'undefined') {
				jQuery('.bs-vc-modal-form #bsvc_page').val(oValue.output.page);
			} else if (oValue.output.type == 'template_part' && typeof oValue.output.template_part != 'undefined') {
				jQuery('.bs-vc-modal-form #bsvc_template_part').val(oValue.output.template_part);
			}
		}

		if (oValue.outputN && oValue.outputN.type) {
			jQuery('.bs-vc-modal-form #bsvc_output_n').val(oValue.outputN.type);
			if (oValue.outputN.type == 'message' && typeof oValue.outputN.message != 'undefined') {
				jQuery('.bs-vc-modal-form #bsvc_message_n').val(oValue.outputN.message);
				if (typeof oValue.outputN.message_type != 'undefined') {
					jQuery('.bs-vc-modal-form #bsvc_message_type_n').val(oValue.outputN.message_type);
				}
			} else if (oValue.outputN.type == 'page' && typeof oValue.outputN.page != 'undefined') {
				jQuery('.bs-vc-modal-form #bsvc_page_n').val(oValue.outputN.page);
			} else if (oValue.outputN.type == 'template_part' && typeof oValue.outputN.template_part != 'undefined') {
				jQuery('.bs-vc-modal-form #bsvc_template_part_n').val(oValue.outputN.template_part);
			}
		}
	}
}
/**
 * Try to auto-recover blocks.
 */
function sd_auto_recover_blocks() {
	var recursivelyRecoverInvalidBlockList = blocks => {
		const _blocks = [...blocks]
		let recoveryCalled = false
		const recursivelyRecoverBlocks = willRecoverBlocks => {
			willRecoverBlocks.forEach(_block => {
				if (!_block.isValid) {
					recoveryCalled = true
					const newBlock = recoverBlock(_block)
					for (const key in newBlock) {
						_block[key] = newBlock[key]
					}
				}
				if (_block.innerBlocks.length) {
					recursivelyRecoverBlocks(_block.innerBlocks)
				}
			})
		}
		recursivelyRecoverBlocks(_blocks)
		return [_blocks, recoveryCalled]
	}
	var recoverBlock = ({ name, attributes, innerBlocks }) => wp.blocks.createBlock(name, attributes, innerBlocks);
	var recoverBlocks = blocks => {
		return blocks.map(_block => {
			const block = _block;
			// If the block is a reusable block, recover the Stackable blocks inside it.
			if (_block.name === 'core/block') {
				const { attributes: { ref } } = _block
				const parsedBlocks = wp.blocks.parse(wp.data.select('core').getEntityRecords('postType', 'wp_block', { include: [ref] })?.[0]?.content?.raw) || []
				const [recoveredBlocks, recoveryCalled] = recursivelyRecoverInvalidBlockList(parsedBlocks)
				if (recoveryCalled) {
					console.log('Stackable notice: block ' + block.name + ' (' + block.clientId + ') was auto-recovered, you should not see this after saving your page.');
					return { blocks: recoveredBlocks, isReusable: true, ref }
				}
			} else if (_block.name === 'core/template-part' && _block.attributes && _block.attributes.theme) {
				var tmplPart = wp.data.select('core').getEntityRecord('postType', 'wp_template_part', _block.attributes.theme + '//' + _block.attributes.slug);
				var tmplPartBlocks = block.innerBlocks && block.innerBlocks.length ? block.innerBlocks : wp.blocks.parse(tmplPart?.content?.raw) || [];
				if (tmplPartBlocks && tmplPartBlocks.length && tmplPartBlocks.some(block => !block.isValid)) {
					block.innerBlocks = tmplPartBlocks;
					block.tmplPartId = _block.attributes.theme + '//' + _block.attributes.slug;
				}
			}
			if (block.innerBlocks && block.innerBlocks.length) {
				if (block.tmplPartId) {
					console.log('Template part ' + block.tmplPartId + ' block ' + block.name + ' (' + block.clientId + ') starts');
				}
				const newInnerBlocks = recoverBlocks(block.innerBlocks)
				if (newInnerBlocks.some(block => block.recovered)) {
					block.innerBlocks = newInnerBlocks
					block.replacedClientId = block.clientId
					block.recovered = true
				}
				if (block.tmplPartId) {
					console.log('Template part ' + block.tmplPartId + ' block ' + block.name + ' (' + block.clientId + ') ends');
				}
			}
			if (!block.isValid) {
				const newBlock = recoverBlock(block)
				newBlock.replacedClientId = block.clientId
				newBlock.recovered = true
				console.log('Stackable notice: block ' + block.name + ' (' + block.clientId + ') was auto-recovered, you should not see this after saving your page.');
				return newBlock
			}
			return block
		})
	}
	// Recover all the blocks that we can find.
	var sdBlockEditor = wp.data.select('core/block-editor');
	var mainBlocks = sdBlockEditor ? recoverBlocks(sdBlockEditor.getBlocks()) : null;
	// Replace the recovered blocks with the new ones.
	if (mainBlocks) {
		mainBlocks.forEach(block => {
			if (block.isReusable && block.ref) {
				// Update the reusable blocks.
				wp.data.dispatch('core').editEntityRecord('postType', 'wp_block', block.ref, {
					content: wp.blocks.serialize(block.blocks)
				}).then(() => {
					// But don't save them, let the user do the saving themselves. Our goal is to get rid of the block error visually.
				})
			}
			if (block.recovered && block.replacedClientId) {
				wp.data.dispatch('core/block-editor').replaceBlock(block.replacedClientId, block)
			}
		})
	}
}

/**
 * Try to auto-recover OUR blocks if traditional way fails.
 */
function sd_auto_recover_blocks_fallback(editTmpl) {
	console.log('sd_auto_recover_blocks_fallback()');
	var $bsRecoverBtn = jQuery(".edit-site-visual-editor__editor-canvas").contents().find('div[class*=" wp-block-blockstrap-"] .block-editor-warning__actions  .block-editor-warning__action .components-button.is-primary').not(":contains('Keep as HTML')");
	if ($bsRecoverBtn.length) {
		if(jQuery('.edit-site-layout.is-full-canvas').length || jQuery('.edit-site-layout.is-edit-mode').length){
			$bsRecoverBtn.removeAttr('disabled').trigger('click');
		}
	}
}

<?php if( !isset( $_REQUEST['sd-block-recover-debug'] ) ){ ?>
// Wait will window is loaded before calling.
window.onload = function() {
	sd_auto_recover_blocks();
	// fire a second time incase of load delays.
	setTimeout(function() {
		sd_auto_recover_blocks();
	}, 5000);

	setTimeout(function() {
		sd_auto_recover_blocks_fallback();
	}, 6000);

	setTimeout(function() {
		sd_auto_recover_blocks_fallback();
	}, 10000);

	setTimeout(function() {
		sd_auto_recover_blocks_fallback();
	}, 15000);

	setTimeout(function() {
		sd_auto_recover_blocks_fallback();
	}, 20000);

	setTimeout(function() {
		sd_auto_recover_blocks_fallback();
	}, 30000);

	setTimeout(function() {
		sd_auto_recover_blocks_fallback();
	}, 60000);

	jQuery('.edit-site-page-panels__edit-template-button, .edit-site-visual-editor__editor-canvas').on('click', function() {
		setTimeout(function() {
			sd_auto_recover_blocks_fallback(true);
			jQuery('.edit-site-page-panels__edit-template-button, .edit-site-visual-editor__editor-canvas').addClass('bs-edit-tmpl-clicked');
		}, 100);
	});
};
<?php } ?>

// fire when URL changes also.
let lastUrl = location.href;
new MutationObserver(() => {
	const url = location.href;
	if (url !== lastUrl) {
		lastUrl = url;
		sd_auto_recover_blocks();
		// fire a second time incase of load delays.
		setTimeout(function() {
			sd_auto_recover_blocks();
			sd_auto_recover_blocks_fallback();
		}, 2000);

		setTimeout(function() {
		sd_auto_recover_blocks_fallback();
		}, 10000);

		setTimeout(function() {
		sd_auto_recover_blocks_fallback();
		}, 15000);

		setTimeout(function() {
		sd_auto_recover_blocks_fallback();
		}, 20000);

	}
}).observe(document, {
	subtree: true,
	childList: true
});


			/**
			*
* @param $args
* @returns {*|{}}
*/
			function sd_build_aui_styles($args){

				$styles = {};
				// background color
				if ( $args['bg'] !== undefined && $args['bg'] !== '' ) {
				   if( $args['bg'] == 'custom-color' ){
					   $styles['background-color']=  $args['bg_color'];
				   }else  if( $args['bg'] == 'custom-gradient' ){
					   $styles['background-image']=  $args['bg_gradient'];

						// use background on text
						 if( $args['bg_on_text'] !== undefined && $args['bg_on_text'] ){
							$styles['backgroundClip'] = "text";
							$styles['WebkitBackgroundClip'] = "text";
							$styles['text-fill-color'] = "transparent";
							$styles['WebkitTextFillColor'] = "transparent";
						 }
				   }

				}

				let $bg_image = $args['bg_image'] !== undefined && $args['bg_image'] !== '' ? $args['bg_image'] : '';

				// maybe use featured image.
				if( $args['bg_image_use_featured'] !== undefined && $args['bg_image_use_featured'] ){
					$bg_image = 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiID8+CjxzdmcgYmFzZVByb2ZpbGU9InRpbnkiIGhlaWdodD0iNDAwIiB2ZXJzaW9uPSIxLjIiIHdpZHRoPSI0MDAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6ZXY9Imh0dHA6Ly93d3cudzMub3JnLzIwMDEveG1sLWV2ZW50cyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiPjxkZWZzIC8+PHJlY3QgZmlsbD0iI2QzZDNkMyIgaGVpZ2h0PSI0MDAiIHdpZHRoPSI0MDAiIHg9IjAiIHk9IjAiIC8+PGxpbmUgc3Ryb2tlPSJ3aGl0ZSIgc3Ryb2tlLXdpZHRoPSIxMCIgeDE9IjAiIHgyPSI0MDAiIHkxPSIwIiB5Mj0iNDAwIiAvPjxsaW5lIHN0cm9rZT0id2hpdGUiIHN0cm9rZS13aWR0aD0iMTAiIHgxPSIwIiB4Mj0iNDAwIiB5MT0iNDAwIiB5Mj0iMCIgLz48cmVjdCBmaWxsPSIjZDNkM2QzIiBoZWlnaHQ9IjUwIiB3aWR0aD0iMjE4LjAiIHg9IjkxLjAiIHk9IjE3NS4wIiAvPjx0ZXh0IGZpbGw9IndoaXRlIiBmb250LXNpemU9IjMwIiBmb250LXdlaWdodD0iYm9sZCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgeD0iMjAwLjAiIHk9IjIwNy41Ij5QTEFDRUhPTERFUjwvdGV4dD48L3N2Zz4=';
				}

				if( $bg_image !== undefined && $bg_image !== '' ){
					var hasImage = true
					if($styles['background-color'] !== undefined && $args['bg'] == 'custom-color'){
						   $styles['background-image'] = "url("+$bg_image+")";
						   $styles['background-blend-mode'] =  "overlay";
					}else if($styles['background-image'] !== undefined && $args['bg'] == 'custom-gradient'){
						   $styles['background-image'] +=  ",url("+$bg_image+")";
					}else if($args['bg'] !== undefined && $args['bg'] != '' && $args['bg'] != 'transparent' ){
						   // do nothing as we already have a preset
						   hasImage = false;
					}else{
						   $styles['background-image'] = "url("+$bg_image+")";
					}

					if( hasImage){
						 $styles['background-size'] = "cover";

						 if( $args['bg_image_fixed'] !== undefined && $args['bg_image_fixed'] ){
							 $styles['background-attachment'] = "fixed";
						 }
					}

					if( hasImage && $args['bg_image_xy'].x !== undefined && $args['bg_image_xy'].x >=0 ){
						  $styles['background-position'] =  ($args['bg_image_xy'].x * 100 ) + "% " + ( $args['bg_image_xy'].y * 100) + "%";
					}
				}



				// sticky offset top
				if( $args['sticky_offset_top'] !== undefined && $args['sticky_offset_top'] !== '' ){
					$styles['top'] =  $args['sticky_offset_top'];
				}

				// sticky offset bottom
				if( $args['sticky_offset_bottom'] !== undefined && $args['sticky_offset_bottom'] !== '' ){
					$styles['bottom'] =  $args['sticky_offset_bottom'];
				}

				// font size
				if( $args['font_size'] === undefined || $args['font_size'] === 'custom' ){
					if( $args['font_size_custom'] !== undefined && $args['font_size_custom'] !== '' ){
						$styles['fontSize'] =  $args['font_size_custom'] + "rem";
					}
				}

				// font color
				if( $args['text_color'] === undefined || $args['text_color'] === 'custom' ){
					if( $args['text_color_custom'] !== undefined && $args['text_color_custom'] !== '' ){
						$styles['color'] =  $args['text_color_custom'];
					}
				}

				// font line height
				if( $args['font_line_height'] !== undefined && $args['font_line_height'] !== '' ){
					$styles['lineHeight'] =  $args['font_line_height'];
				}

				// max height
				if( $args['max_height'] !== undefined && $args['max_height'] !== '' ){
					$styles['maxHeight'] =  $args['max_height'];
				}

				return $styles;

			}

			function sd_build_aui_class($args){

				$classes = [];

				<?php
				if($aui_bs5){
					?>
				$aui_bs5 = true;
				$p_ml = 'ms-';
				$p_mr = 'me-';

				$p_pl = 'ps-';
				$p_pr = 'pe-';
					<?php
				}else{
						?>
				$aui_bs5 = false;
				$p_ml = 'ml-';
				$p_mr = 'mr-';

				$p_pl = 'pl-';
				$p_pr = 'pr-';
					<?php
				}
				?>

				// margins
				if ( $args['mt'] !== undefined && $args['mt'] !== '' ) { $classes.push( "mt-" + $args['mt'] );  $mt = $args['mt']; }else{$mt = null;}
				if ( $args['mr'] !== undefined && $args['mr'] !== '' ) { $classes.push( $p_mr + $args['mr'] );  $mr = $args['mr']; }else{$mr = null;}
				if ( $args['mb'] !== undefined && $args['mb'] !== '' ) { $classes.push( "mb-" + $args['mb'] );  $mb = $args['mb']; }else{$mb = null;}
				if ( $args['ml'] !== undefined && $args['ml'] !== '' ) { $classes.push( $p_ml + $args['ml'] );  $ml = $args['ml']; }else{$ml = null;}

				// margins tablet
				if ( $args['mt_md'] !== undefined && $args['mt_md'] !== '' ) { $classes.push( "mt-md-" + $args['mt_md'] );  $mt_md = $args['mt_md']; }else{$mt_md = null;}
				if ( $args['mr_md'] !== undefined && $args['mr_md'] !== '' ) { $classes.push( $p_mr + "md-" + $args['mr_md'] );  $mt_md = $args['mr_md']; }else{$mr_md = null;}
				if ( $args['mb_md'] !== undefined && $args['mb_md'] !== '' ) { $classes.push( "mb-md-" + $args['mb_md'] );  $mt_md = $args['mb_md']; }else{$mb_md = null;}
				if ( $args['ml_md'] !== undefined && $args['ml_md'] !== '' ) { $classes.push( $p_ml + "md-" + $args['ml_md'] );  $mt_md = $args['ml_md']; }else{$ml_md = null;}

				// margins desktop
				if ( $args['mt_lg'] !== undefined && $args['mt_lg'] !== '' ) { if($mt == null && $mt_md == null){ $classes.push( "mt-" + $args['mt_lg'] ); }else{$classes.push( "mt-lg-" + $args['mt_lg'] ); } }
				if ( $args['mr_lg'] !== undefined && $args['mr_lg'] !== '' ) { if($mr == null && $mr_md == null){ $classes.push( $p_mr + $args['mr_lg'] ); }else{$classes.push( $p_mr + "lg-" + $args['mr_lg'] ); } }
				if ( $args['mb_lg'] !== undefined && $args['mb_lg'] !== '' ) { if($mb == null && $mb_md == null){ $classes.push( "mb-" + $args['mb_lg'] ); }else{$classes.push( "mb-lg-" + $args['mb_lg'] ); } }
				if ( $args['ml_lg'] !== undefined && $args['ml_lg'] !== '' ) { if($ml == null && $ml_md == null){ $classes.push( $p_ml + $args['ml_lg'] ); }else{$classes.push( $p_ml + "lg-" + $args['ml_lg'] ); } }

				// padding
				if ( $args['pt'] !== undefined && $args['pt'] !== '' ) { $classes.push( "pt-" + $args['pt'] ); $pt = $args['pt']; }else{$pt = null;}
				if ( $args['pr'] !== undefined && $args['pr'] !== '' ) { $classes.push( $p_pr + $args['pr'] ); $pr = $args['pt']; }else{$pr = null;}
				if ( $args['pb'] !== undefined && $args['pb'] !== '' ) { $classes.push( "pb-" + $args['pb'] ); $pb = $args['pt']; }else{$pb = null;}
				if ( $args['pl'] !== undefined && $args['pl'] !== '' ) { $classes.push( $p_pl + $args['pl'] ); $pl = $args['pt']; }else{$pl = null;}

				// padding tablet
				if ( $args['pt_md'] !== undefined && $args['pt_md'] !== '' ) { $classes.push( "pt-md-" + $args['pt_md'] ); $pt_md = $args['pt_md']; }else{$pt_md = null;}
				if ( $args['pr_md'] !== undefined && $args['pr_md'] !== '' ) { $classes.push( $p_pr + "md-" + $args['pr_md'] ); $pr_md = $args['pt_md']; }else{$pr_md = null;}
				if ( $args['pb_md'] !== undefined && $args['pb_md'] !== '' ) { $classes.push( "pb-md-" + $args['pb_md'] ); $pb_md = $args['pt_md']; }else{$pb_md = null;}
				if ( $args['pl_md'] !== undefined && $args['pl_md'] !== '' ) { $classes.push( $p_pl + "md-" + $args['pl_md'] ); $pl_md = $args['pt_md']; }else{$pl_md = null;}

				// padding desktop
				if ( $args['pt_lg'] !== undefined && $args['pt_lg'] !== '' ) { if($pt == null && $pt_md == null){ $classes.push( "pt-" + $args['pt_lg'] ); }else{$classes.push( "pt-lg-" + $args['pt_lg'] ); } }
				if ( $args['pr_lg'] !== undefined && $args['pr_lg'] !== '' ) { if($pr == null && $pr_md == null){ $classes.push( $p_pr + $args['pr_lg'] ); }else{$classes.push( $p_pr + "lg-" + $args['pr_lg'] ); } }
				if ( $args['pb_lg'] !== undefined && $args['pb_lg'] !== '' ) { if($pb == null && $pb_md == null){ $classes.push( "pb-" + $args['pb_lg'] ); }else{$classes.push( "pb-lg-" + $args['pb_lg'] ); } }
				if ( $args['pl_lg'] !== undefined && $args['pl_lg'] !== '' ) { if($pl == null && $pl_md == null){ $classes.push( $p_pl + $args['pl_lg'] ); }else{$classes.push( $p_pl + "lg-" + $args['pl_lg'] ); } }

				// row cols, mobile, tablet, desktop
				if ( $args['row_cols'] !== undefined && $args['row_cols'] !== '' ) { $classes.push( "row-cols-" + $args['row_cols'] );  $row_cols = $args['row_cols']; }else{$row_cols = null;}
				if ( $args['row_cols_md'] !== undefined && $args['row_cols_md'] !== '' ) { $classes.push( "row-cols-md-" + $args['row_cols_md'] );  $row_cols_md = $args['row_cols_md']; }else{$row_cols_md = null;}
				if ( $args['row_cols_lg'] !== undefined && $args['row_cols_lg'] !== '' ) { if($row_cols == null && $row_cols_md == null){ $classes.push( "row-cols-" + $args['row_cols_lg'] ); }else{$classes.push( "row-cols-lg-" + $args['row_cols_lg'] ); } }

				// columns , mobile, tablet, desktop
				if ( $args['col'] !== undefined && $args['col'] !== '' ) { $classes.push( "col-" + $args['col'] );  $col = $args['col']; }else{$col = null;}
				if ( $args['col_md'] !== undefined && $args['col_md'] !== '' ) { $classes.push( "col-md-" + $args['col_md'] );  $col_md = $args['col_md']; }else{$col_md = null;}
				if ( $args['col_lg'] !== undefined && $args['col_lg'] !== '' ) { if($col == null && $col_md == null){ $classes.push( "col-" + $args['col_lg'] ); }else{$classes.push( "col-lg-" + $args['col_lg'] ); } }


				// border
				if ( $args['border'] === undefined || $args['border']=='')  { }
				else if ( $args['border'] !== undefined && ( $args['border']=='none' || $args['border']==='0') ) { $classes.push( "border-0" ); }
				else if ( $args['border'] !== undefined ) {
					if($aui_bs5 && $args['border_type'] !== undefined){
						$args['border_type'] = $args['border_type'].replace('-left','-start').replace('-right','-end');
					}
					$border_class = 'border';
					if ( $args['border_type'] !== undefined && ! $args['border_type'].includes( '-0' )  ) {
						$border_class = '';
					}
					$classes.push( $border_class + " border-" + $args['border'] );
				}

				// border radius type
			  //  if ( $args['rounded'] !== undefined && $args['rounded'] !== '' ) { $classes.push($args['rounded']); }

				// border radius size
				if( $args['rounded_size'] !== undefined && ( $args['rounded_size']==='sm' || $args['rounded_size']==='lg' ) ){
					if ( $args['rounded_size'] !== undefined && $args['rounded_size'] !== '' ) {
						$classes.push("rounded-" + $args['rounded_size']);
						// if we set a size then we need to remove "rounded" if set
						var index = $classes.indexOf("rounded");
						if (index !== -1) {
						  $classes.splice(index, 1);
						}
					}
				}else{
					// rounded_size , mobile, tablet, desktop
					if ( $args['rounded_size'] !== undefined && $args['rounded_size'] !== '' ) { $classes.push( "rounded-" + $args['rounded_size'] );  $rounded_size = $args['rounded_size']; }else{$rounded_size = null;}
					if ( $args['rounded_size_md'] !== undefined && $args['rounded_size_md'] !== '' ) { $classes.push( "rounded-md-" + $args['rounded_size_md'] );  $rounded_size_md = $args['rounded_size_md']; }else{$rounded_size_md = null;}
					if ( $args['rounded_size_lg'] !== undefined && $args['rounded_size_lg'] !== '' ) { if($rounded_size == null && $rounded_size_md == null){ $classes.push( "rounded-" + $args['rounded_size_lg'] ); }else{$classes.push( "rounded-lg-" + $args['rounded_size_lg'] ); } }
				}


				// shadow
			   // if ( $args['shadow'] !== undefined && $args['shadow'] !== '' ) { $classes.push($args['shadow']); }

				// background
				if ( $args['bg'] !== undefined  && $args['bg'] !== '' ) { $classes.push("bg-" + $args['bg']); }

                // background image fixed bg_image_fixed
                if ( $args['bg_image_fixed'] !== undefined  && $args['bg_image_fixed'] !== '' ) { $classes.push("bg-image-fixed"); }

				// text_color
				if ( $args['text_color'] !== undefined && $args['text_color'] !== '' ) { $classes.push( "text-" + $args['text_color']); }

				// text_align
				if ( $args['text_justify'] !== undefined && $args['text_justify'] ) { $classes.push('text-justify'); }
				else{
					if ( $args['text_align'] !== undefined && $args['text_align'] !== '' ) {
						if($aui_bs5){ $args['text_align'] = $args['text_align'].replace('-left','-start').replace('-right','-end'); }
						$classes.push($args['text_align']); $text_align = $args['text_align'];
					}else{$text_align = null;}
					if ( $args['text_align_md'] !== undefined && $args['text_align_md'] !== '' ) {
						if($aui_bs5){ $args['text_align_md'] = $args['text_align_md'].replace('-left','-start').replace('-right','-end'); }
						$classes.push($args['text_align_md']); $text_align_md = $args['text_align_md'];
					}else{$text_align_md = null;}
					if ( $args['text_align_lg'] !== undefined && $args['text_align_lg'] !== '' ) {
						if($aui_bs5){ $args['text_align_lg'] = $args['text_align_lg'].replace('-left','-start').replace('-right','-end'); }
						if($text_align  == null && $text_align_md == null){ $classes.push($args['text_align_lg'].replace("-lg", ""));
						}else{$classes.push($args['text_align_lg']);} }
				}

				// display
				  if ( $args['display'] !== undefined && $args['display'] !== '' ) { $classes.push($args['display']); $display = $args['display']; }else{$display = null;}
				if ( $args['display_md'] !== undefined && $args['display_md'] !== '' ) { $classes.push($args['display_md']); $display_md = $args['display_md']; }else{$display_md = null;}
				if ( $args['display_lg'] !== undefined && $args['display_lg'] !== '' ) { if($display  == null && $display_md == null){ $classes.push($args['display_lg'].replace("-lg", "")); }else{$classes.push($args['display_lg']);} }

				// bgtus - background transparent until scroll
				if ( $args['bgtus'] !== undefined && $args['bgtus'] ) { $classes.push("bg-transparent-until-scroll"); }

				// cscos - change color scheme on scroll
				if ( $args['bgtus'] !== undefined && $args['bgtus'] && $args['cscos'] !== undefined && $args['cscos'] ) { $classes.push("color-scheme-flip-on-scroll"); }

				// hover animations
				if ( $args['hover_animations'] !== undefined && $args['hover_animations'] ) { $classes.push($args['hover_animations'].toString().replace(',',' ')); }

				// absolute_position
				if ( $args['absolute_position'] !== undefined ) {
					if ( 'top-left' === $args['absolute_position'] ) {
						$classes.push('start-0 top-0');
					} else if ( 'top-center' === $args['absolute_position'] ) {
						$classes.push('start-50 top-0 translate-middle');
					} else if ( 'top-right' === $args['absolute_position'] ) {
						$classes.push('end-0 top-0');
					} else if ( 'center-left' === $args['absolute_position'] ) {
						$classes.push('start-0 bottom-50');
					} else if ( 'center' === $args['absolute_position'] ) {
						$classes.push('start-50 top-50 translate-middle');
					} else if ( 'center-right' === $args['absolute_position'] ) {
						$classes.push('end-0 top-50');
					} else if ( 'bottom-left' === $args['absolute_position'] ) {
						$classes.push('start-0 bottom-0');
					} else if ( 'bottom-center' === $args['absolute_position'] ) {
						$classes.push('start-50 bottom-0 translate-middle');
					} else if ( 'bottom-right' === $args['absolute_position'] ) {
						$classes.push('end-0 bottom-0');
					}
				}

				// build classes from build keys
				$build_keys = sd_get_class_build_keys();
				if ( $build_keys.length ) {
					$build_keys.forEach($key => {

						if($key.endsWith("-MTD")){

							$k = $key.replace("-MTD","");

							// Mobile, Tablet, Desktop
							if ( $args[$k] !== undefined && $args[$k] !== '' ) { $classes.push( $args[$k] );  $v = $args[$k]; }else{$v = null;}
							if ( $args[$k + '_md'] !== undefined && $args[$k + '_md'] !== '' ) { $classes.push( $args[$k + '_md'] );  $v_md = $args[$k + '_md']; }else{$v_md = null;}
							if ( $args[$k + '_lg'] !== undefined && $args[$k + '_lg'] !== '' ) { if($v == null && $v_md == null){ $classes.push( $args[$k + '_lg'].replace('-lg','') ); }else{$classes.push( $args[$k + '_lg'] ); } }

						}else{
							if ( $key == 'font_size' && ( $args[ $key ] == 'custom' || $args[ $key ] === '0' ) ) {
							 return;
							}
							if ( $args[$key] !== undefined && $args[$key] !== '' ) { $classes.push($args[$key]); }
						}

					});
				}

				return $classes.join(" ");
			}

			function sd_get_class_build_keys(){
				return <?php echo json_encode(sd_get_class_build_keys());?>;
			}

			<?php


			}

			if(method_exists($this,'block_global_js')){
					echo $this->block_global_js();
			}
			?>

jQuery(function() {

				/**
				 * BLOCK: Basic
				 *
				 * Registering a basic block with Gutenberg.
				 * Simple block, renders and saves the same content without any interactivity.
				 *
				 * Styles:
				 *        editor.css — Editor styles for the block.
				 *        style.css  — Editor & Front end styles for the block.
				 */
				(function (blocksx, elementx, blockEditor) {
					if (typeof blockEditor === 'undefined') {
						return;<?php /* Yoast SEO load blocks.js without block-editor.js on post edit pages */ ?>
					}
					var __ = wp.i18n.__; // The __() for internationalization.
					var el = wp.element.createElement; // The wp.element.createElement() function to create elements.
					var editable = wp.blocks.Editable;
					var blocks = wp.blocks;
					var registerBlockType = wp.blocks.registerBlockType; // The registerBlockType() to register blocks.
					var is_fetching = false;
					var prev_attributes = [];

					var InnerBlocks = blockEditor.InnerBlocks;

					var term_query_type = '';
					var post_type_rest_slugs = <?php if(! empty( $this->arguments ) && isset($this->arguments['post_type']['onchange_rest']['values'])){echo "[".json_encode($this->arguments['post_type']['onchange_rest']['values'])."]";}else{echo "[]";} ?>;
					const taxonomies_<?php echo str_replace("-","_", $this->id);?> = [{label: "Please wait", value: 0}];
					const sort_by_<?php echo str_replace("-","_", $this->id);?> = [{label: "Please wait", value: 0}];
					const MediaUpload = wp.blockEditor.MediaUpload;

					/**
					 * Register Basic Block.
					 *
					 * Registers a new block provided a unique name and an object defining its
					 * behavior. Once registered, the block is made available as an option to any
					 * editor interface where blocks are implemented.
					 *
					 * @param  {string}   name     Block name.
					 * @param  {Object}   settings Block settings.
					 * @return {?WPBlock}          The block, if it has been successfully
					 *                             registered; otherwise `undefined`.
					 */
					registerBlockType('<?php echo str_replace( "_", "-", sanitize_title_with_dashes( $this->options['textdomain'] ) . '/' . sanitize_title_with_dashes( $this->options['class_name'] ) );  ?>', { // Block name. Block names must be string that contains a namespace prefix. Example: my-plugin/my-custom-block.
						apiVersion: <?php echo isset($this->options['block-api-version']) ? absint($this->options['block-api-version']) : 2 ; ?>,
						title: '<?php echo addslashes( $this->options['name'] ); ?>', // Block title.
						description: '<?php echo addslashes( $this->options['widget_ops']['description'] )?>', // Block title.
						icon: <?php echo $this->get_block_icon( $this->options['block-icon'] );?>,//'<?php echo isset( $this->options['block-icon'] ) ? esc_attr( $this->options['block-icon'] ) : 'shield-alt';?>', // Block icon from Dashicons → https://developer.wordpress.org/resource/dashicons/.
						supports: {
							<?php
							if(!isset($this->options['block-supports']['renaming'])){
								$this->options['block-supports']['renaming'] = false;
							}
							if ( isset( $this->options['block-supports'] ) ) {
								echo $this->array_to_attributes( $this->options['block-supports'] );
							}
							?>
						},
						__experimentalLabel( attributes, { context } ) {
							var visibility_html = attributes && attributes.visibility_conditions ? ' &#128065;' : '';
							var metadata_name = attributes && attributes.metadata && attributes.metadata.name ? attributes.metadata.name : '';
							var label_name = <?php echo !empty($this->options['block-label']) ? $this->options['block-label'] : "'" . esc_attr( addslashes( $this->options['name'] ) ) . "'"; ?>;
							return metadata_name ? metadata_name + visibility_html  : label_name + visibility_html;
						},
						category: '<?php echo isset( $this->options['block-category'] ) ? esc_attr( $this->options['block-category'] ) : 'common';?>', // Block category — Group blocks together based on common traits E.g. common, formatting, layout widgets, embed.
						<?php if ( isset( $this->options['block-keywords'] ) ) {
						echo "keywords : " . $this->options['block-keywords'] . ",";
						}


						// block hover preview.
						$example_args = array();
						if(!empty($this->arguments)){
							foreach($this->arguments as $key => $a_args){
								if(isset($a_args['example'])){
									$example_args[$key] = $a_args['example'];
								}
							}
						}
						$viewport_width = isset($this->options['example']['viewportWidth']) ? 'viewportWidth: '.absint($this->options['example']['viewportWidth']) : '';
						$example_inner_blocks = !empty($this->options['example']['innerBlocks']) && is_array($this->options['example']['innerBlocks']) ? 'innerBlocks: ' . wp_json_encode($this->options['example']['innerBlocks']) : '';
						if( isset( $this->options['example'] ) && $this->options['example'] === false ){
							// no preview if set to false
						}elseif( !empty( $example_args ) ){
							echo "example : {attributes:{".$this->array_to_attributes( $example_args )."},$viewport_width},";
						}elseif( !empty( $this->options['example'] ) ){
							unset($this->options['example']['viewportWidth']);
							unset($this->options['example']['innerBlocks']);
							$example_atts = $this->array_to_attributes( $this->options['example'] );
							$example_parts = array();
							if($example_atts){
								$example_parts[] = rtrim($example_atts,",");
							}
							if($viewport_width){
								$example_parts[] = $viewport_width;
							}
							if($example_inner_blocks){
								$example_parts[] = $example_inner_blocks;
							}
							if(!empty($example_parts)){
								echo "example : {".implode(',', $example_parts)."},";
							}
						}else{
							echo 'example : {viewportWidth: 500},';
						}



						// limit to parent
						if( !empty( $this->options['parent'] ) ){
							echo "parent : " . wp_json_encode( $this->options['parent'] ) . ",";
						}

						// limit allowed blocks
						if( !empty( $this->options['allowed-blocks'] ) ){
							echo "allowedBlocks : " . wp_json_encode( $this->options['allowed-blocks'] ) . ",";
						}

						// maybe set no_wrap
						$no_wrap = isset( $this->options['no_wrap'] ) && $this->options['no_wrap'] ? true : false;
						if ( isset( $this->arguments['no_wrap'] ) && $this->arguments['no_wrap'] ) {
							$no_wrap = true;
						}
						if ( $no_wrap ) {
							$this->options['block-wrap'] = '';
						}

						// Maybe load the drag/drop functions.
						$img_drag_drop = false;
						$show_alignment = false;
	
							echo "attributes : {";

							if ( $show_advanced ) {
								echo "show_advanced: {";
								echo "  type: 'boolean',";
								echo "  default: false";
								echo "},";
							}

							// Block wrap element
							if ( ! empty( $this->options['block-wrap'] ) ) { //@todo we should validate this?
								echo "block_wrap: {";
								echo "  type: 'string',";
								echo "  default: '" . esc_attr( $this->options['block-wrap'] ) . "'";
								echo "},";
							}

							if ( ! empty( $this->arguments ) ) {
								foreach ( $this->arguments as $key => $args ) {
									if ( $args['type'] == 'image' ||  $args['type'] == 'images' ) {
										$img_drag_drop = true;
									}

									// Set if we should show alignment.
									if ( $key == 'alignment' ) {
										$show_alignment = true;
									}

									$extra = '';
									$_default = isset( $args['default'] ) && ! is_null( $args['default'] ) ? $args['default'] : '';

									if ( ! empty( $_default ) ) {
										$_default = wp_slash( $_default );
									}

									if ( $args['type'] == 'notice' ||  $args['type'] == 'tab' ) {
										continue;
									} else if ( $args['type'] == 'checkbox' ) {
										$type    = 'boolean';
										$default = $_default ? 'true' : 'false';
									} else if ( $args['type'] == 'number' ) {
										$type    = 'number';
										$default = "'" . $_default . "'";
									} else if ( $args['type'] == 'select' && ! empty( $args['multiple'] ) ) {
										$type = 'array';
										if ( isset( $args['default'] ) && is_array( $args['default'] ) ) {
											$default = ! empty( $_default ) ? "['" . implode( "','", $_default ) . "']" : "[]";
										} else {
											$default = "'" . $_default . "'";
										}
									} else if ( $args['type'] == 'tagselect' ) {
										$type    = 'array';
										$default = "'" . $_default . "'";
									} else if ( $args['type'] == 'multiselect' ) {
										$type    = 'array';
										$default = "'" . $_default . "'";
									} else if ( $args['type'] == 'image_xy' ) {
										$type    = 'object';
										$default = "'" . $_default . "'";
									} else if ( $args['type'] == 'image' ) {
										$type    = 'string';
										$default = "'" . $_default . "'";
									} else {
										$type    = ! empty( $args['hidden_type'] ) ? esc_attr( $args['hidden_type'] ) : 'string';
										$default = "'" . $_default . "'";
									}

									echo $key . " : {";
									echo "type : '$type',";
									echo "default : $default";
									echo "},";
								}
							}

							echo "content : {type : 'string',default: 'Please select the attributes in the block settings'},";
							echo "sd_shortcode : {type : 'string',default: ''},";

							if ( ! empty( $this->options['nested-block'] ) || ! empty( $this->arguments['html'] ) ) {
								echo "sd_shortcode_close : {type : 'string',default: ''},";
							}

							echo "className: { type: 'string', default: '' }";
							echo "},";
						?>
						// The "edit" property must be a valid function.
						edit: function (props) {
							const selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
<?php
// only include the drag/drop functions if required.
if ( $img_drag_drop ) {
?>

function enableDragSort(listClass) {
	setTimeout(function(){
		 const sortableLists = document.getElementsByClassName(listClass);
		 Array.prototype.map.call(sortableLists, (list) => {enableDragList(list)});
	}, 300);
}

function enableDragList(list) {
  Array.prototype.map.call(list.children, (item) => {enableDragItem(item)});
}

function enableDragItem(item) {
  item.setAttribute('draggable', true)
  item.ondrag = handleDrag;
  item.ondragend = handleDrop;
}

function handleDrag(item) {
  const selectedItem = item.target,
		list = selectedItem.parentNode,
		x = event.clientX,
		y = event.clientY;

  selectedItem.classList.add('drag-sort-active');
  let swapItem = document.elementFromPoint(x, y) === null ? selectedItem : document.elementFromPoint(x, y);

  if (list === swapItem.parentNode) {
	swapItem = swapItem !== selectedItem.nextSibling ? swapItem : swapItem.nextSibling;
	list.insertBefore(selectedItem, swapItem);
  }
}

function handleDrop(item) {

	item.target.classList.remove('drag-sort-active');

	const newOrder = [];
	let $parent = item.target.parentNode;
	let $field = $parent.dataset.field;
	let $imgs = JSON.parse('[' + props.attributes[$field] + ']');
	item.target.parentNode.classList.add('xxx');
	$children = $parent.children;

	Object.keys($children).forEach(function(key) {
	  let $nKey = $children[key].dataset.index
	  newOrder.push($imgs[$nKey]);
	});

	// @todo find out why we need to empty the value first otherwise the order is wrong.
	props.setAttributes({ [$field]: '' });
	setTimeout(function(){
		props.setAttributes({ [$field]: JSON.stringify(newOrder).replace('[','').replace(']','') });
	}, 100);

}
<?php } ?>

							if (typeof(props.attributes.styleid) !== 'undefined'){
								if(props.attributes.styleid==''){ props.setAttributes({ 'styleid': 'block-'+(Math.random() + 1).toString(36).substring(2) } ); }
							}

							<?php
							if(!empty($this->options['block-edit-raw'])) {
								echo $this->options['block-edit-raw']; // strings have to be in single quotes, may cause issues
							}else{
							?>

function hasSelectedInnerBlock(props) {
	const select = wp.data.select('core/editor');
	const selected = select.getBlockSelectionStart();
	const inner = select.getBlock(props.clientId).innerBlocks;
	for (let i = 0; i < inner.length; i++) {
		if (inner[i].clientId === selected || inner[i].innerBlocks.length && hasSelectedInnerBlock(inner[i])) {
			return true;
		}
	}
	return false;
};

const parentBlocksIDs = wp.data.select( 'core/block-editor' ).getBlockParents(props.clientId);
const parentBlocks = wp.data.select('core/block-editor').getBlocksByClientId(parentBlocksIDs);
// const isParentOfSelectedBlock = useSelect( ( select ) => wp.data.select( 'core/block-editor' ).hasSelectedInnerBlock( props.clientId, true ) ):
	const block = wp.data.select('core/block-editor').getBlocksByClientId(props.clientId);//.[0].innerBlocks;
	const childBlocks = block[0] == null ? '' : block[0].innerBlocks;

	var $value = '';
	<?php
	// if we have a post_type and a category then link them
	if( isset($this->arguments['post_type']) && isset($this->arguments['category']) && !empty($this->arguments['category']['post_type_linked']) ){
	?>
	if(typeof(prev_attributes[props.clientId]) != 'undefined' && selectedBlock && selectedBlock.clientId === props.clientId){
		$pt = props.attributes.post_type;
		if(post_type_rest_slugs.length){
			$value = post_type_rest_slugs[0][$pt];
		}
		var run = false;

		if($pt != term_query_type){
			run = true;
			term_query_type = $pt;
		}
<?php
	$cat_path = '';
	if ( ! empty( $this->arguments['post_type']['onchange_rest']['path'] ) ) {
		$cat_path = esc_js( strip_tags( $this->arguments['post_type']['onchange_rest']['path'] ) );
		$cat_path = str_replace( array( '&quot;', '&#039;' ), array( '"', "'" ), $cat_path );
	}
?>
		/* taxonomies */
		if($value && 'post_type' in prev_attributes[props.clientId] && 'category' in prev_attributes[props.clientId] && run){
			if (!window.gdCPTCats) {
				window.gdCPTCats = [];
			}
			var gdCatPath = "<?php echo ( ! empty( $cat_path ) ? $cat_path : "/wp/v2/" + $value + "/categories/?per_page=100" ); ?>";
			if (window.gdCPTCats[gdCatPath]) {
				terms = window.gdCPTCats[gdCatPath];
				while (taxonomies_<?php echo str_replace("-","_", $this->id);?>.length) {
					taxonomies_<?php echo str_replace("-","_", $this->id);?>.pop();
				}
				taxonomies_<?php echo str_replace("-","_", $this->id);?>.push({label: "All", value: 0});
				jQuery.each( terms, function( key, val ) {
					taxonomies_<?php echo str_replace("-","_", $this->id);?>.push({label: val.name, value: val.id});
				});

				/* Setting the value back and fourth fixes the no update issue that sometimes happens where it won't update the options. */
				var $old_cat_value = props.attributes.category
				props.setAttributes({category: [0] });
				props.setAttributes({category: $old_cat_value });
			} else {
				wp.apiFetch({path: gdCatPath}).then(terms => {
					window.gdCPTCats[gdCatPath] = terms;
					while (taxonomies_<?php echo str_replace("-","_", $this->id);?>.length) {
						taxonomies_<?php echo str_replace("-","_", $this->id);?>.pop();
					}
					taxonomies_<?php echo str_replace("-","_", $this->id);?>.push({label: "All", value: 0});
					jQuery.each( terms, function( key, val ) {
						taxonomies_<?php echo str_replace("-","_", $this->id);?>.push({label: val.name, value: val.id});
					});

					/* Setting the value back and fourth fixes the no update issue that sometimes happens where it won't update the options. */
					var $old_cat_value = props.attributes.category
					props.setAttributes({category: [0] });
					props.setAttributes({category: $old_cat_value });

					return taxonomies_<?php echo str_replace("-","_", $this->id);?>;
				});
			}
		}

		/* sort_by */
		if($value && 'post_type' in prev_attributes[props.clientId] && 'sort_by' in prev_attributes[props.clientId] && run){
			if (!window.gdCPTSort) {
				window.gdCPTSort = [];
			}
			if (window.gdCPTSort[$pt]) {
				response = window.gdCPTSort[$pt];
				while (sort_by_<?php echo str_replace("-","_", $this->id);?>.length) {
					sort_by_<?php echo str_replace("-","_", $this->id);?>.pop();
				}

				jQuery.each( response, function( key, val ) {
					sort_by_<?php echo str_replace("-","_", $this->id);?>.push({label: val, value: key});
				});

				// setting the value back and fourth fixes the no update issue that sometimes happens where it won't update the options.
				var $old_sort_by_value = props.attributes.sort_by
				props.setAttributes({sort_by: [0] });
				props.setAttributes({sort_by: $old_sort_by_value });
			} else {
				var data = {
					'action': 'geodir_get_sort_options',
					'post_type': $pt
				};
				jQuery.post(ajaxurl, data, function(response) {
					response = JSON.parse(response);
					window.gdCPTSort[$pt] = response;
					while (sort_by_<?php echo str_replace("-","_", $this->id);?>.length) {
						sort_by_<?php echo str_replace("-","_", $this->id);?>.pop();
					}

					jQuery.each( response, function( key, val ) {
						sort_by_<?php echo str_replace("-","_", $this->id);?>.push({label: val, value: key});
					});

					// setting the value back and fourth fixes the no update issue that sometimes happens where it won't update the options.
					var $old_sort_by_value = props.attributes.sort_by
					props.setAttributes({sort_by: [0] });
					props.setAttributes({sort_by: $old_sort_by_value });

					return sort_by_<?php echo str_replace("-","_", $this->id);?>;
				});
			}
		}
	}
	<?php } ?>
<?php
$current_screen = function_exists('get_current_screen') ? get_current_screen() : '';
if(!empty($current_screen->base) && $current_screen->base==='widgets'){
	echo 'const { deviceType } = "";';
}else{
?>
/** Get device type const. */
const wpVersion = '<?php global $wp_version; echo esc_attr($wp_version); ?>';
const { deviceType } = typeof wp.data.useSelect !== 'undefined' ? wp.data.useSelect(select => {
    if (parseFloat(wpVersion) < 6.5) {
        const { __experimentalGetPreviewDeviceType } = select('core/edit-site') ? select('core/edit-site') : select('core/edit-post') ? select('core/edit-post') : '';
        return {
            deviceType: __experimentalGetPreviewDeviceType(),
        };
    } else {
        const editorSelect = select('core/editor');
        if (editorSelect) {
            return {
                deviceType: editorSelect.getDeviceType(),
            };
        } else {
            return {
                deviceType: 'Desktop', // Default value if device type is not available
            };
        }
    }
}, []) : '';
<?php } ?>
							var content = props.attributes.content, shortcode = '';

							function onChangeContent($type) {
								$refresh = false;
								// Set the old content the same as the new one so we only compare all other attributes
								if(typeof(prev_attributes[props.clientId]) != 'undefined'){
									prev_attributes[props.clientId].content = props.attributes.content;

									// don't compare the sd_shortcode as it is changed later
									prev_attributes[props.clientId].sd_shortcode = props.attributes.sd_shortcode;
								}else if(props.attributes.content === ""){
									// if first load and content empty then refresh
									$refresh = true;
								} else {
									// if not new and has content then set it so we dont go fetch it
									if(props.attributes.content && props.attributes.content !== 'Please select the attributes in the block settings'){
										prev_attributes[props.clientId] = props.attributes;
									}
								}

								if ( ( !is_fetching &&  JSON.stringify(prev_attributes[props.clientId]) != JSON.stringify(props.attributes) ) || $refresh  ) {
									is_fetching = true;

									var data = {
										'action': 'super_duper_output_shortcode',
										'shortcode': '<?php echo $this->options['base_id'];?>',
										'attributes': props.attributes,
										'block_parent_name': parentBlocks.length ? parentBlocks[parentBlocks.length - 1].name : '',
										'post_id': <?php global $post; if ( isset( $post->ID ) ) {
										echo $post->ID;
									}else{echo '0';}?>,
										'_ajax_nonce': '<?php echo wp_create_nonce( 'super_duper_output_shortcode' );?>'
									};

									jQuery.post(ajaxurl, data, function (response) {
										return response;
									}).then(function (env) {
										// if the content is empty then we place some placeholder text
										if (env == '') {
											env = "<div style='background:#0185ba33;padding: 10px;border: 4px #ccc dashed;'>" + "<?php _e( 'Placeholder for:', 'ayecode-connect' );?> " + props.name + "</div>";
										}

										 <?php
										if(!empty($this->options['nested-block'])){
											?>
											// props.setAttributes({content: env});
										is_fetching = false;
										prev_attributes[props.clientId] = props.attributes;
											 <?php
										}else{
										?>
										props.setAttributes({content: env});
										is_fetching = false;
										prev_attributes[props.clientId] = props.attributes;
										<?php
										}
										?>

										// if AUI is active call the js init function
										if (typeof aui_init === "function") {
											aui_init();
										}
									});
								}

								return props.attributes.content;
							}

							<?php
							if(!empty($this->options['block-edit-js'])) {
								echo  $this->options['block-edit-js'] ; // strings have to be in single quotes, may cause issues
							}

							if(empty($this->options['block-save-return'])){
							?>
								///////////////////////////////////////////////////////////////////////

								// build the shortcode.
								shortcode = "[<?php echo $this->options['base_id'];?>";
								<?php

								if(! empty( $this->arguments )){

								foreach($this->arguments as $key => $args){
								   // if($args['type']=='tabs'){continue;}

								   // don't add metadata arguments
								   if (substr($key, 0, 9 ) === 'metadata_') {
									   continue;
								   }
								?>
								if (props.attributes.hasOwnProperty("<?php echo esc_attr( $key );?>")) {
									if ('<?php echo esc_attr( $key );?>' == 'html') {
									} else if ('<?php echo esc_attr( $args['type'] );?>' == 'image_xy') {
										shortcode += props.attributes.<?php echo esc_attr( $key );?>.length && ( props.attributes.<?php echo esc_attr( $key );?>.x.length || props.attributes.<?php echo esc_attr( $key );?>.y.length ) ? " <?php echo esc_attr( $key );?>='{x:" + props.attributes.<?php echo esc_attr( $key );?>.x + ",y:"+props.attributes.<?php echo esc_attr( $key );?>.y +"}' " : "";
									} else {
										//shortcode += props.attributes.<?php echo esc_attr( $key );?>.length ? " <?php echo esc_attr( $key );?>='" + props.attributes.<?php echo esc_attr( $key );?>.toString().replace('\'','&#39;') + "' " : "";
										shortcode +=  " <?php echo esc_attr( $key );?>='" + props.attributes.<?php echo esc_attr( $key );?>.toString().replace('\'','&#39;') + "' ";
									}
								}
								<?php
								}
								}

								?>
								shortcode += "]";

								if(shortcode){

									// can cause a react exception when selecting multile blocks of the same type when the settings are not the same
									if (props.attributes.sd_shortcode !== shortcode) {
									  props.setAttributes({ sd_shortcode: shortcode });
									}


									<?php
									if(!empty($this->options['nested-block']) || !empty($this->arguments['html']) ){
										echo "props.setAttributes({sd_shortcode_close: '[/".esc_attr( $this->options['base_id'] )."]'});";
									}
									?>
								}


							///////////////////////////////////////////////////////////////////////
							<?php
							} // end nested block check
							?>

							return [

								el(wp.blockEditor.BlockControls, {key: 'controls'},

									<?php if($show_alignment){?>
									el(
										wp.blockEditor.AlignmentToolbar,
										{
											value: props.attributes.alignment,
											onChange: function (alignment) {
												props.setAttributes({alignment: alignment})
											}
										}
									)
									<?php }?>

								),

								el(wp.blockEditor.InspectorControls, {key: 'inspector'},

									<?php

									if(! empty( $this->arguments )){

									if ( $show_advanced ) {
									?>
									el('div', {
											style: {'padding-left': '16px','padding-right': '16px'}
										},
										el(
											wp.components.ToggleControl,
											{
												label: 'Show Advanced Settings?',
												checked: props.attributes.show_advanced,
												onChange: function (show_advanced) {
													props.setAttributes({show_advanced: !props.attributes.show_advanced})
												}
											}
										)
									)
									,
									<?php
									}

									$arguments = $this->group_arguments( $this->arguments );
									$block_group_tabs = ! empty( $this->options['block_group_tabs'] ) ? $this->group_block_tabs( $this->options['block_group_tabs'], $arguments ) : array();

									// Do we have sections?
									$has_sections = $arguments == $this->arguments ? false : true;

									if($has_sections){
									$panel_count = 0;
									$open_tab = '';

									$open_tab_groups = array();
									$used_tabs = array();

									foreach ( $arguments as $key => $args ) {
										$close_tab = false;
										$close_tabs = false;

										 if ( ! empty( $block_group_tabs ) ) {
											foreach ( $block_group_tabs as $tab_name => $tab_args ) {
												if ( in_array( $key, $tab_args['groups'] ) ) {
													$open_tab_groups[] = $key;

													if ( $open_tab != $tab_name ) {
														$tab_args['tab']['tabs_open'] = $open_tab == '' ? true : false;
														$tab_args['tab']['open'] = true;

														$this->block_tab_start( '', $tab_args );
														$open_tab = $tab_name;
														$used_tabs[] = $tab_name;
													}

													if ( $open_tab_groups == $tab_args['groups'] ) {
														$close_tab = true;
														$open_tab_groups = array();

														if ( $used_tabs == array_keys( $block_group_tabs ) ) {
															$close_tabs = true;
														}
													}
												}
											}
										}
										?>
										el(wp.components.PanelBody, {
												title: '<?php esc_attr_e( $key ); ?>',
												initialOpen: <?php if ( $panel_count ) {
												echo "false";
											} else {
												echo "true";
											}?>
											},
											<?php
											foreach ( $args as $k => $a ) {
												$this->block_tab_start( $k, $a );
												$this->block_row_start( $k, $a );
												$this->build_block_arguments( $k, $a );
												$this->block_row_end( $k, $a );
												$this->block_tab_end( $k, $a );
											}
											?>
										),
										<?php
										$panel_count ++;

										if($close_tab || $close_tabs){
											$tab_args = array(
												'tab'	=> array(
													'tabs_close' => $close_tabs,
												'close' => true,
												)

											);
											$this->block_tab_end( '', $tab_args );
//											echo '###close'; print_r($tab_args);
											$panel_count = 0;
										}
//

									}
									}else {
									?>
									el(wp.components.PanelBody, {
											title: '<?php esc_attr_e( "Settings", 'ayecode-connect' ); ?>',
											initialOpen: true
										},
										<?php
										foreach ( $this->arguments as $key => $args ) {
											$this->block_row_start( $key, $args );
											$this->build_block_arguments( $key, $args );
											$this->block_row_end( $key, $args );
										}
										?>
									),
									<?php
									}

									}
									?>

								),

								<?php
								// If the user sets block-output array then build it
								if ( ! empty( $this->options['block-output'] ) ) {
								$this->block_element( $this->options['block-output'] );
							}elseif(!empty($this->options['block-edit-return'])){
								   echo $this->options['block-edit-return'];
							}else{
								// if no block-output is set then we try and get the shortcode html output via ajax.
								$block_edit_wrap_tag = !empty($this->options['block_edit_wrap_tag']) ? esc_attr($this->options['block_edit_wrap_tag']) : 'div';
								?>
								el('<?php echo esc_attr($block_edit_wrap_tag); ?>', wp.blockEditor.useBlockProps({
									dangerouslySetInnerHTML: {__html: onChangeContent()},
									className: props.className,
									<?php //if(isset($this->arguments['visibility_conditions'])){ echo 'dataVisibilityConditionSD: props.visibility_conditions ? true : false,';} //@todo we need to implement this in the other outputs also ?>
									style: {'minHeight': '30px'}
								}))
								<?php
								}
								?>
							]; // end return

							<?php
							} // end block-edit-raw else
							?>
						},

						// The "save" property must be specified and must be a valid function.
						save: function (props) {

							var attr = props.attributes;
							var align = '';

							// build the shortcode.
							var content = "[<?php echo $this->options['base_id'];?>";
							$html = '';
							<?php

							if(! empty( $this->arguments )){

							foreach($this->arguments as $key => $args){
							   // if($args['type']=='tabs'){continue;}

							   // don't add metadata arguments
							   if (substr($key, 0, 9 ) === 'metadata_') {
								   continue;
							   }
							?>
							if (attr.hasOwnProperty("<?php echo esc_attr( $key );?>")) {
								if ('<?php echo esc_attr( $key );?>' == 'html') {
									$html = attr.<?php echo esc_attr( $key );?>;
								} else if ('<?php echo esc_attr( $args['type'] );?>' == 'image_xy') {
									content += " <?php echo esc_attr( $key );?>='{x:" + attr.<?php echo esc_attr( $key );?>.x + ",y:"+attr.<?php echo esc_attr( $key );?>.y +"}' ";
								} else {
									content += " <?php echo esc_attr( $key );?>='" + attr.<?php echo esc_attr( $key );?>.toString().replace('\'','&#39;') + "' ";
								}
							}
							<?php
							}
							}

							?>
							content += "]";
							 content = '';

							<?php
//                            if(!empty($this->options['nested-block'])){
//                                ?>
//                                $html = 'el( InnerBlocks.Content )';
//                                <?php
//                            }
							?>
							// if has html element
							if ($html) {
								//content += $html + "[/<?php echo $this->options['base_id'];?>]";
							}

							// @todo should we add inline style here or just css classes?
							if (attr.alignment) {
								if (attr.alignment == 'left') {
									align = 'alignleft';
								}
								if (attr.alignment == 'center') {
									align = 'aligncenter';
								}
								if (attr.alignment == 'right') {
									align = 'alignright';
								}
							}

							<?php
//							if(!empty($this->options['nested-block'])){
//                                ?x>
//                              return el(
//                                    'div',
//                                    { className: props.className,
//                                        style: {'minHeight': '300px','position':'relative','overflow':'hidden','backgroundImage': 'url(https://s.w.org/images/core/5.5/don-quixote-06.jpg)'}
//                                    },
//                                    el( InnerBlocks.Content ),
//                                    el('div', {dangerouslySetInnerHTML: {__html: content}, className: align})
//                                );
//                                <x?php
//							}else

							if(!empty($this->options['block-output'])){
//                               echo "return";
//                               $this->block_element( $this->options['block-output'], true );
//                               echo ";";

							   ?>
							  return el(
								   '',
								   {},
								  // el('', {dangerouslySetInnerHTML: {__html: content}}),
								   <?php $this->block_element( $this->options['block-output'], true ); ?>
								  // el('', {dangerouslySetInnerHTML: {__html: "[/<?php echo $this->options['base_id'];?>]"}})
							   );
								<?php

							}elseif(!empty($this->options['block-save-return'])){
								   echo 'return ' . $this->options['block-save-return'];
							}elseif(!empty($this->options['nested-block'])){
								?>
							  return el(
								   '',
								   {},
								   el('', {dangerouslySetInnerHTML: {__html: content+"\n"}}),
								   InnerBlocks.Content ? el( InnerBlocks.Content ) : '', // @todo i think we need a comma here
								 //  el('', {dangerouslySetInnerHTML: {__html: "[/<?php echo $this->options['base_id'];?>]"}})
							   );
								<?php
							}elseif(!empty( $this->options['block-save-return'] ) ){
								echo "return ". $this->options['block-edit-return'].";";
							}elseif(isset( $this->options['block-wrap'] ) && $this->options['block-wrap'] == ''){
							?>
							return content;
							<?php
							}else{
							?>
							var block_wrap = 'div';
							if (attr.hasOwnProperty("block_wrap")) {
								block_wrap = attr.block_wrap;
							}
							return el(block_wrap, wp.blockEditor.useBlockProps.save( {dangerouslySetInnerHTML: {__html: content}, className: align} ));
							<?php
							}
							?>


						}
					});
				})(
					window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor
				);

				});
			</script>
			<?php
			$output = ob_get_clean();

			/*
			 * We only add the <script> tags for code highlighting, so we strip them from the output.
			 */

			return str_replace( array(
				'<script>',
				'</script>'
			), '', $output );
		}



		public function block_row_start($key, $args){

			// check for row
			if(!empty($args['row'])){

				if(!empty($args['row']['open'])){

				// element require
				$element_require = ! empty( $args['element_require'] ) ? $this->block_props_replace( $args['element_require'], true ) . " && " : "";
				$device_type = ! empty( $args['device_type'] ) ? esc_attr($args['device_type']) : '';
				$device_type_require = ! empty( $args['device_type'] ) ? " deviceType == '" . esc_attr($device_type) . "' && " : '';
				$device_type_icon = '';
				if($device_type=='Desktop'){
					$device_type_icon = '<span class="dashicons dashicons-desktop" style="font-size: 18px;" onclick="sd_show_view_options(this);"></span>';
				}elseif($device_type=='Tablet'){
					$device_type_icon = '<span class="dashicons dashicons-tablet" style="font-size: 18px;" onclick="sd_show_view_options(this);"></span>';
				}elseif($device_type=='Mobile'){
					$device_type_icon = '<span class="dashicons dashicons-smartphone" style="font-size: 18px;" onclick="sd_show_view_options(this);"></span>';
				}
				echo $element_require;
				echo $device_type_require;

					if(false){?><script><?php }?>
						el('div', {
								className: 'bsui components-base-control',
							},
							<?php if(!empty($args['row']['title'])){ ?>
							el('label', {
									className: 'components-base-control__label position-relative',
									style: {width:"100%"}
								},
								el('span',{dangerouslySetInnerHTML: {__html: '<?php echo addslashes( $args['row']['title'] ) ?>'}}),
								<?php if($device_type_icon){ ?>
									deviceType == '<?php echo $device_type;?>' && el('span',{dangerouslySetInnerHTML: {__html: '<?php echo $device_type_icon; ?>'},title: deviceType + ": Set preview mode to change",style: {right:"0",position:"absolute",color:"var(--wp-admin-theme-color)"}})
								<?php
								}
								?>


							),
							<?php }?>
							<?php if(!empty($args['row']['desc'])){ ?>
							el('p', {
									className: 'components-base-control__help mb-0',
								},
								'<?php echo addslashes( $args['row']['desc'] ); ?>'
							),
							<?php }?>
							el(
								'div',
								{
									className: 'row mb-n2 <?php if(!empty($args['row']['class'])){ echo esc_attr($args['row']['class']);} ?>',
								},
								el(
									'div',
									{
										className: 'col pr-2 pe-2',
									},

					<?php
					if(false){?></script><?php }
				}elseif(!empty($args['row']['close'])){
					if(false){?><script><?php }?>
						el(
							'div',
							{
								className: 'col pl-0 ps-0',
							},
					<?php
					if(false){?></script><?php }
				}else{
					if(false){?><script><?php }?>
						el(
							'div',
							{
								className: 'col pl-0 ps-0 pr-2 pe-2',
							},
					<?php
					if(false){?></script><?php }
				}

			}

		}

		public function block_row_end($key, $args){

			if(!empty($args['row'])){
				// maybe close
				if(!empty($args['row']['close'])){
					echo "))";
				}

				echo "),";
			}
		}

		public function block_tab_start($key, $args){

			// check for row
			if(!empty($args['tab'])){

				if(!empty($args['tab']['tabs_open'])){

					if(false){?><script><?php }?>

el('div',{className: 'bsui'},

						el('hr', {className: 'm-0'}), el(
									wp.components.TabPanel,
									{
										activeClass: 'is-active',
										className: 'btn-groupx',
										initialTabName: '<?php echo addslashes( esc_attr( $args['tab']['key']) ); ?>',
										tabs: [

					<?php
					if(false){?></script><?php }
				}

				if(!empty($args['tab']['open'])){

					if(false){?><script><?php }?>
							{
												name: '<?php echo addslashes( esc_attr( $args['tab']['key']) ); ?>',
												title: el('div', {dangerouslySetInnerHTML: {__html: '<?php echo addslashes( esc_attr( $args['tab']['title']) ); ?>'}}),
												className: '<?php echo addslashes( esc_attr( $args['tab']['class']) ); ?>',
												content: el('div',{}, <?php if(!empty($args['tab']['desc'])){ ?>el('p', {
									className: 'components-base-control__help mb-0',
									dangerouslySetInnerHTML: {__html:'<?php echo addslashes( $args['tab']['desc'] ); ?>'}
								}),<?php }
					if(false){?></script><?php }
				}

			}

		}

		public function block_tab_end($key, $args){

			if(!empty($args['tab'])){
				// maybe close
				if(!empty($args['tab']['close'])){
					echo ")}, /* tab close */";
				}

				if(!empty($args['tab']['tabs_close'])){
					if(false){?><script><?php }?>
						]}, ( tab ) => {
								return tab.content;
							}
						)), /* tabs close */
					<?php if(false){ ?></script><?php }
				}
			}
		}

		public function build_block_arguments( $key, $args ) {
			$custom_attributes = ! empty( $args['custom_attributes'] ) ? $this->array_to_attributes( $args['custom_attributes'] ) : '';
			$options           = '';
			$extra             = '';
			$require           = '';
			$inside_elements   = '';
			$after_elements	   = '';

			// `content` is a protected and special argument
			if ( $key == 'content' ) {
				return;
			}

			$device_type = ! empty( $args['device_type'] ) ? esc_attr($args['device_type']) : '';
			$device_type_require = ! empty( $args['device_type'] ) ? " deviceType == '" . esc_attr($device_type) . "' && " : '';
			$device_type_icon = '';
			if($device_type=='Desktop'){
				$device_type_icon = '<span class="dashicons dashicons-desktop" style="font-size: 18px;" onclick="sd_show_view_options(this);"></span>';
			}elseif($device_type=='Tablet'){
				$device_type_icon = '<span class="dashicons dashicons-tablet" style="font-size: 18px;" onclick="sd_show_view_options(this);"></span>';
			}elseif($device_type=='Mobile'){
				$device_type_icon = '<span class="dashicons dashicons-smartphone" style="font-size: 18px;" onclick="sd_show_view_options(this);"></span>';
			}

			// icon
			$icon = '';
			if( !empty( $args['icon'] ) ){
				$icon .= "el('div', {";
									$icon .= "dangerouslySetInnerHTML: {__html: '".self::get_widget_icon( esc_attr($args['icon']))."'},";
									$icon .= "className: 'text-center',";
									$icon .= "title: '".addslashes( $args['title'] )."',";
								$icon .= "}),";

				// blank title as its added to the icon.
				$args['title'] = '';
			}

			// require advanced
			$require_advanced = ! empty( $args['advanced'] ) ? "props.attributes.show_advanced && " : "";

			// element require
			$element_require = ! empty( $args['element_require'] ) ? $this->block_props_replace( $args['element_require'], true ) . " && " : "";


			$onchange  = "props.setAttributes({ $key: $key } )";
			$onchangecomplete  = "";
			$value     = "props.attributes.$key";
			$text_type = array( 'text', 'password', 'number', 'email', 'tel', 'url', 'colorx','range' );
			if ( in_array( $args['type'], $text_type ) ) {
				$type = 'TextControl';
				// Save numbers as numbers and not strings
				if ( $args['type'] == 'number' ) {
					$onchange = "props.setAttributes({ $key: $key ? Number($key) : '' } )";
				}

				if (substr($key, 0, 9 ) === 'metadata_') {
					$real_key = str_replace('metadata_','', $key );
					$onchange = "props.setAttributes({ metadata: { $real_key: $key } } )";
					$value     = "props.attributes.metadata && props.attributes.metadata.$real_key ? props.attributes.metadata.$real_key : ''";
				}
			}
//			else if ( $args['type'] == 'popup' ) {
//				$type = 'TextControl';
//				$args['type'] == 'text';
//				$after_elements .= "el( wp.components.Button, {
//                          className: 'components-button components-circular-option-picker__clear is-primary is-smallx',
//                          onClick: function(){
//							  aui_modal('','<input id=\'zzz\' value= />');
//							  const source = document.getElementById('zzz');
//							  source.value = props.attributes.$key;
//							  source.addEventListener('input', function(e){props.setAttributes({ $key: e.target.value });});
//                          }
//                        },
//                        'test'
//                        ),";
//
//				$value     = "props.attributes.$key ? props.attributes.$key : ''";
//			}
			else if ( $args['type'] == 'styleid' ) {
				$type = 'TextControl';
				$args['type'] == 'text';
				// Save numbers as numbers and not strings
				$value     = "props.attributes.$key ? props.attributes.$key : ''";
			}else if ( $args['type'] == 'notice' ) {

				$notice_message = !empty($args['desc']) ? addslashes($args['desc']) : '';
				$notice_status = !empty($args['status']) ? esc_attr($args['status']) : 'info';

				$notice = "el('div',{className:'bsui'},el(wp.components.Notice, {status: '$notice_status',isDismissible: false,className: 'm-0 pr-0 pe-0 mb-3'},el('div',{dangerouslySetInnerHTML: {__html: '$notice_message'}}))),";
				echo $notice_message ? $element_require . $notice : '';
				return;
			}
			/*
			 * https://www.wptricks.com/question/set-current-tab-on-a-gutenberg-tabpanel-component-from-outside-that-component/ es5 layout
						elseif($args['type']=='tabs'){
							?>
								el(
									wp.components.TabPanel,
									{
										activeClass: 'active-tab',
										initialTabName: deviceType,
										tabs: [
											{
												name: 'Desktop',
												title: el('div', {dangerouslySetInnerHTML: {__html: '<i class="fas fa-desktop"></i>'}}),
												className: 'tab-one' + deviceType == 'Desktop' ? ' active-tab' : '',
												content: el('div', {dangerouslySetInnerHTML: {__html: 'ddd'}})
											},
											{
												name: 'Tablet',
												title: el('div', {dangerouslySetInnerHTML: {__html: '<i class="fas fa-tablet-alt"></i>'}}),
												className: 'tab-two' + deviceType == 'Tablet' ? ' active-tab' : '',
												content: el('div', {dangerouslySetInnerHTML: {__html: 'ttt'}})
											},
											{
												name: 'Mobile',
												title: el('div', {dangerouslySetInnerHTML: {__html: '<i class="fas fa-mobile-alt"></i>'}}),
												className: 'tab-two' + deviceType == 'Mobile' ? ' active-tab' : '',
												content: el('div', {dangerouslySetInnerHTML: {__html: 'mmm'}})
											},
										],
									},
									( tab ) => {

// @todo https://github.com/WordPress/gutenberg/issues/39248
									if(tab.name=='Desktop'){
									wp.data.dispatch('core/edit-post').__experimentalSetPreviewDeviceType('Desktop');
wp.data.select('core/edit-post').__experimentalGetPreviewDeviceType();
									}else if(tab.name=='Tablet'){
									wp.data.dispatch('core/edit-post').__experimentalSetPreviewDeviceType('Tablet');
wp.data.select('core/edit-post').__experimentalGetPreviewDeviceType();
									}else if(tab.name=='Mobile'){
									wp.data.dispatch('core/edit-post').__experimentalSetPreviewDeviceType('Mobile');
wp.data.select('core/edit-post').__experimentalGetPreviewDeviceType();
									}

									return tab.content;

								}
								),

							<?php
							return;
						}
*/
			elseif ( $args['type'] == 'color' ) {
				$type = 'ColorPicker';
				$onchange = "";
				$extra = "color: $value,";
				if(!empty($args['disable_alpha'])){
					$extra .= "disableAlpha: true,";
				}
				$onchangecomplete = "onChangeComplete: function($key) {
				value =  $key.rgb.a && $key.rgb.a < 1 ? \"rgba(\"+$key.rgb.r+\",\"+$key.rgb.g+\",\"+$key.rgb.b+\",\"+$key.rgb.a+\")\" : $key.hex;
						props.setAttributes({
							$key: value
						});
					},";
			}elseif ( $args['type'] == 'gradient' ) {
				$type = 'GradientPicker';
				$extra .= "gradients: [{
			name: 'Vivid cyan blue to vivid purple',
			gradient:
				'linear-gradient(135deg,rgba(6,147,227,1) 0%,rgb(155,81,224) 100%)',
			slug: 'vivid-cyan-blue-to-vivid-purple',
		},
		{
			name: 'Light green cyan to vivid green cyan',
			gradient:
				'linear-gradient(135deg,rgb(122,220,180) 0%,rgb(0,208,130) 100%)',
			slug: 'light-green-cyan-to-vivid-green-cyan',
		},
		{
			name: 'Luminous vivid amber to luminous vivid orange',
			gradient:
				'linear-gradient(135deg,rgba(252,185,0,1) 0%,rgba(255,105,0,1) 100%)',
			slug: 'luminous-vivid-amber-to-luminous-vivid-orange',
		},
		{
			name: 'Luminous vivid orange to vivid red',
			gradient:
				'linear-gradient(135deg,rgba(255,105,0,1) 0%,rgb(207,46,46) 100%)',
			slug: 'luminous-vivid-orange-to-vivid-red',
		},
		{
			name: 'Very light gray to cyan bluish gray',
			gradient:
				'linear-gradient(135deg,rgb(238,238,238) 0%,rgb(169,184,195) 100%)',
			slug: 'very-light-gray-to-cyan-bluish-gray',
		},
		{
			name: 'Cool to warm spectrum',
			gradient:
				'linear-gradient(135deg,rgb(74,234,220) 0%,rgb(151,120,209) 20%,rgb(207,42,186) 40%,rgb(238,44,130) 60%,rgb(251,105,98) 80%,rgb(254,248,76) 100%)',
			slug: 'cool-to-warm-spectrum',
		}],";

			}elseif ( $args['type'] == 'image' ) {
//                print_r($args);

				$img_preview = isset($args['focalpoint']) && !$args['focalpoint'] ? " props.attributes.$key && el('img', { src: props.attributes.$key,style: {maxWidth:'100%',background: '#ccc'}})," : " ( props.attributes.$key ||  props.attributes.{$key}_use_featured ) && el(wp.components.FocalPointPicker,{
							url:  props.attributes.{$key}_use_featured === true ? 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiID8+CjxzdmcgYmFzZVByb2ZpbGU9InRpbnkiIGhlaWdodD0iNDAwIiB2ZXJzaW9uPSIxLjIiIHdpZHRoPSI0MDAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6ZXY9Imh0dHA6Ly93d3cudzMub3JnLzIwMDEveG1sLWV2ZW50cyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiPjxkZWZzIC8+PHJlY3QgZmlsbD0iI2QzZDNkMyIgaGVpZ2h0PSI0MDAiIHdpZHRoPSI0MDAiIHg9IjAiIHk9IjAiIC8+PGxpbmUgc3Ryb2tlPSJ3aGl0ZSIgc3Ryb2tlLXdpZHRoPSIxMCIgeDE9IjAiIHgyPSI0MDAiIHkxPSIwIiB5Mj0iNDAwIiAvPjxsaW5lIHN0cm9rZT0id2hpdGUiIHN0cm9rZS13aWR0aD0iMTAiIHgxPSIwIiB4Mj0iNDAwIiB5MT0iNDAwIiB5Mj0iMCIgLz48cmVjdCBmaWxsPSIjZDNkM2QzIiBoZWlnaHQ9IjUwIiB3aWR0aD0iMjE4LjAiIHg9IjkxLjAiIHk9IjE3NS4wIiAvPjx0ZXh0IGZpbGw9IndoaXRlIiBmb250LXNpemU9IjMwIiBmb250LXdlaWdodD0iYm9sZCIgdGV4dC1hbmNob3I9Im1pZGRsZSIgeD0iMjAwLjAiIHk9IjIwNy41Ij5QTEFDRUhPTERFUjwvdGV4dD48L3N2Zz4='  : props.attributes.$key,
							value: props.attributes.{$key}_xy.x !== undefined && props.attributes.{$key}_xy.x >= 0 ? props.attributes.{$key}_xy  : {x: 0.5,y: 0.5,},
//                            value: props.attributes.{$key}_xy,
							onChange: function(focalPoint){
							console.log(props.attributes);
											  return props.setAttributes({
												  {$key}_xy: focalPoint
												});
									},
									// @todo for some reason this does not work as expected.
//                         onDrag: function(focalPointTemp){
//                                  return props.setAttributes({
//                                      {$key}_xy: focalPointTemp
//                                    });
//                        }


						}), ";


				$value = '""';
				$type = 'MediaUpload';
				$extra .= "onSelect: function(media){
					  return props.setAttributes({
						  $key: media.url,
						  {$key}_id: media.id
						});
					  },";
				   $extra .= "type: 'image',";
				   $extra .= "render: function (obj) {
						return el( 'div',{},
						( !props.attributes.$key && !props.attributes.{$key}_use_featured ) && el( wp.components.Button, {
						  className: 'components-button components-circular-option-picker__clear is-primary is-smallx',
						  onClick: obj.open
						},
						'Upload Image'
						),
					   $img_preview
						props.attributes.$key && el( wp.components.Button, {
									  className: 'components-button components-circular-option-picker__clear is-secondary is-small',
									  style: {margin:'8px 0',display: 'block'},
									  onClick: function(){
											  return props.setAttributes({
												  $key: '',
												  {$key}_id: ''
												});
									}
									},
									props.attributes.$key? 'Clear' : ''
							)
					   )



					  }";
				$onchange = "";

				//$inside_elements = ",el('div',{},'file upload')";
			} else if ( $args['type'] == 'images' ) {
				$img_preview = "props.attributes.$key && (function() {
	let uploads = JSON.parse('['+props.attributes.$key+']');
	let images = [];
	uploads.map((upload, index) => (
		images.push( el('div',{ className: 'col p-2', draggable: 'true', 'data-index': index },
			el('img', {
				src: (upload.sizes && upload.sizes.thumbnail ? upload.sizes.thumbnail.url : upload.url),
				style: { maxWidth:'100%', background: '#ccc', pointerEvents:'none' }
			}),
			el('i',{
				className: 'fas fa-times-circle text-danger position-absolute  ml-n2 mt-n1 bg-white rounded-circle c-pointer',
				onClick: function() {
					aui_confirm('".esc_attr__('Are you sure?')."', '".esc_attr__('Delete')."', '".esc_attr__('Cancel')."', true).then(function(confirmed) {
						if (confirmed) {
							let new_uploads = JSON.parse('['+props.attributes.$key+']');
							new_uploads.splice(index, 1);
								return props.setAttributes({ {$key}: JSON.stringify( new_uploads ).replace('[','').replace(']','') });
							}
					});
				}},
			'')
		))
	));
	return images;
})(),";


				$value = '""';
				$type = 'MediaUpload';
				$extra .= "onSelect: function(media){
	let slim_images = props.attributes.$key ? JSON.parse('['+props.attributes.$key+']') : [];
	if(media.length){
		for (var i=0; i < media.length; i++) {
			slim_images.push({id: media[i].id, caption: media[i].caption, description: media[i].description,title: media[i].title,alt: media[i].alt,sizes: media[i].sizes, url: media[i].url});
		}
	}
	var slimImagesV = JSON.stringify(slim_images);
	if (slimImagesV) {
		slimImagesV = slimImagesV.replace('[','').replace(']','').replace(/'/g, '&#39;');
	}
	return props.setAttributes({ $key: slimImagesV});
},";
				$extra .= "type: 'image',";
				$extra .= "multiple: true,";
				$extra .= "render: function (obj) {
	/* Init the sort */
	enableDragSort('sd-sortable');
	return el( 'div',{},
		el( wp.components.Button, {
				className: 'components-button components-circular-option-picker__clear is-primary is-smallx',
				onClick: obj.open
			},
			'Upload Images'
		),
		el('div',{
				className: 'row row-cols-3 px-2 sd-sortable',
				'data-field':'$key'
			},
			$img_preview
		),
		props.attributes.$key && el( wp.components.Button, {
				className: 'components-button components-circular-option-picker__clear is-secondary is-small',
				style: {margin:'8px 0'},
				onClick: function(){
					return props.setAttributes({ $key: '' });
				}
			},
			props.attributes.$key ? 'Clear All' : ''
		)
	)
}";
				$onchange = "";

				//$inside_elements = ",el('div',{},'file upload')";
			}
			elseif ( $args['type'] == 'checkbox' ) {
				$type = 'CheckboxControl';
				$extra .= "checked: props.attributes.$key,";
				$onchange = "props.setAttributes({ $key: ! props.attributes.$key } )";
			} elseif ( $args['type'] == 'textarea' ) {
				$type = 'TextareaControl';

			} elseif ( $args['type'] == 'select' || $args['type'] == 'multiselect' ) {
				$type = 'SelectControl';

				if($args['name'] == 'category' && !empty($args['post_type_linked'])){
					$options .= "options: taxonomies_".str_replace("-","_", $this->id).",";
				}elseif($args['name'] == 'sort_by' && !empty($args['post_type_linked'])){
					$options .= "options: sort_by_".str_replace("-","_", $this->id).",";
				}else {

					if ( ! empty( $args['options'] ) ) {
						$options .= "options: [";
						foreach ( $args['options'] as $option_val => $option_label ) {
							$options .= "{ value: '" . esc_attr( $option_val ) . "', label: '" . esc_js( addslashes( $option_label ) ) . "' },";
						}
						$options .= "],";
					}
				}
				if ( isset( $args['multiple'] ) && $args['multiple'] ) { //@todo multiselect does not work at the moment: https://github.com/WordPress/gutenberg/issues/5550
					$extra .= ' multiple:true,style:{height:"auto",paddingRight:"8px","overflow-y":"auto"}, ';
				}

				if($args['type'] == 'multiselect' ||  ( isset( $args['multiple'] ) && $args['multiple'] ) ){
					$after_elements	 .= "props.attributes.$key && el( wp.components.Button, {
									  className: 'components-button components-circular-option-picker__clear is-secondary is-small',
									  style: {margin:'-8px 0 8px 0',display: 'block'},
									  onClick: function(){
											  return props.setAttributes({
												  $key: '',
												});
									}
									},
									'Clear'
							),";
				}
			} elseif ( $args['type'] == 'tagselect' ) {
//				$type = 'FormTokenField';
//
//				if ( ! empty( $args['options'] ) ) {
//						$options .= "suggestions: [";
//						foreach ( $args['options'] as $option_val => $option_label ) {
//							$options .= "{ value: '" . esc_attr( $option_val ) . "', title: '" . addslashes( $option_label ) . "' },";
////							$options .= "'" . esc_attr( $option_val ) . "':'" . addslashes( $option_label ) . "',";
//						}
//						$options .= "],";
//				}
//
//				$onchangex  = "{ ( selectedItems ) => {
//						// Build array of selected posts.
//						let selectedPostsArray = [];
//						selectedPosts.map(
//							( postName ) => {
//								const matchingPost = posts.find( ( post ) => {
//									return post.title.raw === postName;
//
//								} );
//								if ( matchingPost !== undefined ) {
//									selectedPostsArray.push( matchingPost.id );
//								}
//							}
//						)
//
//						setAttributes( { selectedPosts: selectedPostsArray } );
//					} } ";
//				$onchange  = '';// "props.setAttributes({ $key: [ props.attributes.$key ] } )";
//
////				$options  = "";
//				$value     = "[]";
//				$extra .= ' __experimentalExpandOnFocus: true,';

			} else if ( $args['type'] == 'alignment' ) {
				$type = 'AlignmentToolbar'; // @todo this does not seem to work but cant find a example
			} else if ( $args['type'] == 'margins' ) {

			} else if ( $args['type'] == 'visibility_conditions' && ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
				$type = 'TextControl';
				$value = "(props.attributes.$key ? props.attributes.$key : '')";
				$args['type'] = 'text';
				$options .= 'disabled:true,';
				$bsvc_title = esc_attr( addslashes( $args['title'] ) );
				$bsvc_body = $this->block_visibility_fields( $args );
				// @TODO reset button
				$bsvc_footer = '<button type="button" class="btn btn-danger d-none">' . __( 'Reset', 'ayecode-connect' ) . '</button><button type="button" class="btn btn-secondary bs-vc-close text-white" data-bs-dismiss="modal">' . __( 'Close', 'ayecode-connect' ) . '</button><button type="button" class="btn btn-primary bs-vc-save">' . __( 'Save Rules', 'ayecode-connect' ) . '</button>';
				$after_elements .= "el('div', {className: 'components-base-control bs-vc-button-wrap'}, el(wp.components.Button, {
						className: 'components-button components-circular-option-picker__clear is-primary is-smallx',
						onClick: function() {
							var sValue = props.attributes." . $key . ";
							var oValue;
							try {oValue = JSON.parse(sValue);} catch(err) {}
							jQuery(document).off('show.bs.modal', '.bs-vc-modal').on('show.bs.modal', '.bs-vc-modal', function (e) {
								if (e.target && jQuery(e.target).hasClass('bs-vc-modal')) {
									sd_block_visibility_render_fields(oValue);
									if (!jQuery('.bs-vc-modal-form .bs-vc-rule-sets .bs-vc-rule').length) {
										jQuery('.bs-vc-modal-form .bs-vc-add-rule').trigger('click');
									}
									if(typeof aui_init_select2 == 'function') {
										aui_init_select2();
									}
									jQuery('.bs-vc-modal-form').trigger('change');
								}
							});
							aui_modal('" . $bsvc_title . "', '" . addslashes( $bsvc_body ) . "', '" . $bsvc_footer . "', true, 'bs-vc-modal', 'modal-lg', '');
							jQuery(document).off('change', '#bsvc_raw_value').on('change', '#bsvc_raw_value', function(e) {
								props.setAttributes({" . $key . ": e.target.value});
							});
						}
					},
					'" . addslashes( ! empty( $args['button_title'] ) ? $args['button_title'] : $args['title'] ) . "'
				) ),";
			} else {
				return;// if we have not implemented the control then don't break the JS.
			}

			// color input does not show the labels so we add them
			if($args['type']=='color'){
				// add show only if advanced
				echo $require_advanced;
				// add setting require if defined
				echo $element_require;
				echo "el('div', {style: {'marginBottom': '8px'}}, '".addslashes( $args['title'] )."'),";
			}

			// add show only if advanced
			echo $require_advanced;
			// add setting require if defined
			echo $element_require;
			echo $device_type_require;

			// icon
			echo $icon;
			?>
			el( <?php echo $args['type'] == 'image' || $args['type'] == 'images' ? $type  : "wp.components.".$type; ?>, {
			label: <?php if ( empty( $args['title'] ) ) { echo "''"; } else if ( empty( $args['row'] ) && ! empty( $args['device_type'] ) ) { ?>el('label',{className:'components-base-control__label',style:{width:"100%"}},el('span',{dangerouslySetInnerHTML: {__html: '<?php echo addslashes( $args['title'] ) ?>'}}),<?php if ( $device_type_icon ) { ?>deviceType == '<?php echo $device_type;?>' && el('span',{dangerouslySetInnerHTML: {__html: '<?php echo $device_type_icon; ?>'},title: deviceType + ": Set preview mode to change",style: {right:"0",position:"absolute",color:"var(--wp-admin-theme-color)"}})<?php } ?>)<?php
			} else { ?>'<?php echo addslashes( trim( esc_html( $args['title'] ) ) ); ?>'<?php } ?>,
			help: <?php echo ( isset( $args['desc'] ) ? "el('span', {dangerouslySetInnerHTML: {__html: '" . trim( wp_kses_post( addslashes( $args['desc'] ) ) ) . "'}})" : "''" ); ?>,
			value: <?php echo $value; ?>,
			<?php if ( $type == 'TextControl' && $args['type'] != 'text' ) {
				echo "type: '" . addslashes( $args['type'] ) . "',";
			} ?>
			<?php if ( ! empty( $args['placeholder'] ) ) {
				echo "placeholder: '" . esc_js( addslashes( trim( esc_html( $args['placeholder'] ) ) ) ) . "',";
			} ?>
			<?php echo $options; ?>
			<?php echo $extra; ?>
			<?php echo $custom_attributes; ?>
			<?php echo $onchangecomplete; ?>
			<?php if ( $onchange ) { ?>
			onChange: function ( <?php echo $key; ?> ) {
				<?php echo $onchange; ?>
			}
			<?php } ?>
		} <?php echo $inside_elements; ?> ),
			<?php
			echo $after_elements;
		}

		/**
		 * Convert an array of attributes to block string.
		 *
		 * @param $custom_attributes
		 *
		 * @return string
		 *@todo there is prob a faster way to do this, also we could add some validation here.
		 *
		 */
		public function array_to_attributes( $custom_attributes, $html = false ) {
			$attributes = '';
			if ( ! empty( $custom_attributes ) ) {

				foreach ( $custom_attributes as $key => $val ) {
					if(is_array($val)){
						$attributes .= $key.': {'.$this->array_to_attributes( $val, $html ).'},';
					}else{
						$attributes .= $html ?  " $key='$val' " : "'$key': '$val',";
					}
				}

			}

			return $attributes;
		}



		/**
		 * A self looping function to create the output for JS block elements.
		 *
		 * This is what is output in the WP Editor visual view.
		 *
		 * @param $args
		 */
		public function block_element( $args, $save = false ) {

//            print_r($args);echo '###';exit;

			if ( ! empty( $args ) ) {
				foreach ( $args as $element => $new_args ) {

					if ( is_array( $new_args ) ) { // its an element


						if ( isset( $new_args['element'] ) ) {

							if ( isset( $new_args['element_require'] ) ) {
								echo str_replace( array(
										"'+",
										"+'"
									), '', $this->block_props_replace( $new_args['element_require'] ) ) . " &&  ";
								unset( $new_args['element_require'] );
							}

							if($new_args['element']=='InnerBlocks'){
								echo "\n el( InnerBlocks, {";
							}elseif($new_args['element']=='innerBlocksProps'){
								$element = isset($new_args['inner_element']) ? esc_attr($new_args['inner_element']) : 'div';
							  //  echo "\n el( 'section', wp.blockEditor.useInnerBlocksProps( blockProps, {";
//                                echo $save ? "\n el( '$element', wp.blockEditor.useInnerBlocksProps.save( " : "\n el( '$element', wp.blockEditor.useInnerBlocksProps( ";
								echo $save ? "\n el( '$element', wp.blockEditor.useInnerBlocksProps.save( " : "\n el( '$element', wp.blockEditor.useInnerBlocksProps( ";
								echo $save ? "wp.blockEditor.useBlockProps.save( {" : "wp.blockEditor.useBlockProps( {";
								echo !empty($new_args['blockProps']) ? $this->block_element( $new_args['blockProps'],$save ) : '';

								echo "} ), {";
								echo !empty($new_args['innerBlocksProps']) && !$save ? $this->block_element( $new_args['innerBlocksProps'],$save ) : '';
							//    echo '###';

							  //  echo '###';
							}elseif($new_args['element']=='BlocksProps'){

								if ( isset($new_args['if_inner_element']) ) {
									$element = $new_args['if_inner_element'];
								}else {
									$element = isset($new_args['inner_element']) ? "'".esc_attr($new_args['inner_element'])."'" : "'div'";
								}

								unset($new_args['inner_element']);
								echo $save ? "\n el( $element, wp.blockEditor.useBlockProps.save( {" : "\n el( $element, wp.blockEditor.useBlockProps( {";
								echo !empty($new_args['blockProps']) ? $this->block_element( $new_args['blockProps'],$save ) : '';


							   // echo "} ),";

							}else{
								echo "\n el( '" . $new_args['element'] . "', {";
							}


							// get the attributes
							foreach ( $new_args as $new_key => $new_value ) {


								if ( $new_key == 'element' || $new_key == 'content'|| $new_key == 'if_content' || $new_key == 'element_require' || $new_key == 'element_repeat' || is_array( $new_value ) ) {
									// do nothing
								} else {
									echo $this->block_element( array( $new_key => $new_value ),$save );
								}
							}

							echo $new_args['element']=='BlocksProps' ? '} ),' : "},";// end attributes

							// get the content
							$first_item = 0;
							foreach ( $new_args as $new_key => $new_value ) {
								if ( $new_key === 'content' || $new_key === 'if_content' || is_array( $new_value ) ) {

									if ( $new_key === 'content' ) {
										echo "'" . $this->block_props_replace( wp_slash( $new_value ) ) . "'";
									}else if ( $new_key === 'if_content' ) {
										echo  $this->block_props_replace(  $new_value  );
									}

									if ( is_array( $new_value ) ) {

										if ( isset( $new_value['element_require'] ) ) {
											echo str_replace( array(
													"'+",
													"+'"
												), '', $this->block_props_replace( $new_value['element_require'] ) ) . " &&  ";
											unset( $new_value['element_require'] );
										}

										if ( isset( $new_value['element_repeat'] ) ) {
											$x = 1;
											while ( $x <= absint( $new_value['element_repeat'] ) ) {
												$this->block_element( array( '' => $new_value ),$save );
												$x ++;
											}
										} else {
											$this->block_element( array( '' => $new_value ),$save );
										}
									}
									$first_item ++;
								}
							}

							if($new_args['element']=='innerBlocksProps' || $new_args['element']=='xBlocksProps'){
								echo "))";// end content
							}else{
								echo ")";// end content
							}


							echo ", \n";

						}
					} else {

						if ( substr( $element, 0, 3 ) === "if_" ) {
							$extra = '';
							if( strpos($new_args, '[%WrapClass%]') !== false ){
								$new_args = str_replace('[%WrapClass%]"','" + sd_build_aui_class(props.attributes)',$new_args);
								$new_args = str_replace('[%WrapClass%]','+ sd_build_aui_class(props.attributes)',$new_args);
							}
							echo str_replace( "if_", "", $element ) . ": " . $this->block_props_replace( $new_args, true ) . ",";
						} elseif ( $element == 'style' &&  strpos($new_args, '[%WrapStyle%]') !== false ) {
							$new_args = str_replace('[%WrapStyle%]','',$new_args);
							echo $element . ": {..." . $this->block_props_replace( $new_args ) . " , ...sd_build_aui_styles(props.attributes) },";
//                            echo $element . ": " . $this->block_props_replace( $new_args ) . ",";
						} elseif ( $element == 'style' ) {
							echo $element . ": " . $this->block_props_replace( $new_args ) . ",";
						} elseif ( ( $element == 'class' || $element == 'className'  ) &&  strpos($new_args, '[%WrapClass%]') !== false ) {
							$new_args = str_replace('[%WrapClass%]','',$new_args);
							echo $element . ": '" . $this->block_props_replace( $new_args ) . "' + sd_build_aui_class(props.attributes),";
						} elseif ( $element == 'template' && $new_args ) {
							echo $element . ": $new_args,";
						} else {
							echo $element . ": '" . $this->block_props_replace( $new_args ) . "',";
						}

					}
				}
			}
		}

		/**
		 * Replace block attributes placeholders with the proper naming.
		 *
		 * @param $string
		 *
		 * @return mixed
		 */
		public function block_props_replace( $string, $no_wrap = false ) {
			if ( $no_wrap ) {
				$string = str_replace( array( "[%", "%]", "%:checked]" ), array( "props.attributes.", "", "" ), $string );
			} else {
				$string = str_replace( array( "![%", "[%", "%]", "%:checked]" ), array( "'+!props.attributes.", "'+props.attributes.", "+'", "+'" ), $string );
			}

			return $string;
		}

		/**
		 * Outputs the content of the widget
		 *
		 * @param array $args
		 * @param array $instance
		 */
		public function widget( $args, $instance ) {
			if ( ! is_array( $args ) ) {
				$args = array();
			}

			// Get the filtered values
			$argument_values = $this->argument_values( $instance );
			$argument_values = $this->string_to_bool( $argument_values );
			$output          = $this->output( $argument_values, $args );

			$no_wrap = false;
			if ( isset( $argument_values['no_wrap'] ) && $argument_values['no_wrap'] ) {
				$no_wrap = true;
			}

			ob_start();
			if ( $output && ! $no_wrap ) {

				$class_original = $this->options['widget_ops']['classname'];
				$class = $this->options['widget_ops']['classname']." sdel-".$this->get_instance_hash();

				// Before widget
				$before_widget = ! empty( $args['before_widget'] ) ? $args['before_widget'] : '';
				$before_widget = $before_widget ? str_replace( $class_original, $class, $before_widget ) : $before_widget;
				$before_widget = apply_filters( 'wp_super_duper_before_widget', $before_widget, $args, $instance, $this );
				$before_widget = apply_filters( 'wp_super_duper_before_widget_' . $this->base_id, $before_widget, $args, $instance, $this );

				// After widget
				$after_widget = ! empty( $args['after_widget'] ) ? $args['after_widget'] : '';
				$after_widget = apply_filters( 'wp_super_duper_after_widget', $after_widget, $args, $instance, $this );
				$after_widget = apply_filters( 'wp_super_duper_after_widget_' . $this->base_id, $after_widget, $args, $instance, $this );

				echo $before_widget;
				// elementor strips the widget wrapping div so we check for and add it back if needed
				if ( $this->is_elementor_widget_output() ) {
					// Filter class & attrs for elementor widget output.
					$class = apply_filters( 'wp_super_duper_div_classname', $class, $args, $this );
					$class = apply_filters( 'wp_super_duper_div_classname_' . $this->base_id, $class, $args, $this );

					$attrs = apply_filters( 'wp_super_duper_div_attrs', '', $args, $this );
					$attrs = apply_filters( 'wp_super_duper_div_attrs_' . $this->base_id, '', $args, $this );

					echo "<span class='" . esc_attr( $class  ) . "' " . $attrs . ">";
				}
				echo $this->output_title( $args, $instance );
				echo $output;
				if ( $this->is_elementor_widget_output() ) {
					echo "</span>";
				}
				echo $after_widget;
			} elseif ( $this->is_preview() && $output == '' ) {// if preview show a placeholder if empty
				$output = $this->preview_placeholder_text( "{{" . $this->base_id . "}}" );
				echo $output;
			} elseif ( $output && $no_wrap ) {
				echo $output;
			}
			$output = ob_get_clean();

			$output = apply_filters( 'wp_super_duper_widget_output', $output, $instance, $args, $this );

			echo $output;
		}

		/**
		 * Tests if the current output is inside a elementor container.
		 *
		 * @return bool
		 *@since 1.0.4
		 */
		public function is_elementor_widget_output() {
			$result = false;
			if ( defined( 'ELEMENTOR_VERSION' ) && isset( $this->number ) && $this->number == 'REPLACE_TO_ID' ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Tests if the current output is inside a elementor preview.
		 *
		 * @return bool
		 *@since 1.0.4
		 */
		public function is_elementor_preview() {
			$result = false;
			if ( isset( $_REQUEST['elementor-preview'] ) || ( is_admin() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'elementor' ) || ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'elementor_ajax' ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Tests if the current output is inside a Divi preview.
		 *
		 * @return bool
		 *@since 1.0.6
		 */
		public function is_divi_preview() {
			$result = false;
			if ( isset( $_REQUEST['et_fb'] ) || isset( $_REQUEST['et_pb_preview'] ) || ( is_admin() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'elementor' ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Tests if the current output is inside a Beaver builder preview.
		 *
		 * @return bool
		 *@since 1.0.6
		 */
		public function is_beaver_preview() {
			$result = false;
			if ( isset( $_REQUEST['fl_builder'] ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Tests if the current output is inside a siteorigin builder preview.
		 *
		 * @return bool
		 *@since 1.0.6
		 */
		public function is_siteorigin_preview() {
			$result = false;
			if ( ! empty( $_REQUEST['siteorigin_panels_live_editor'] ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Tests if the current output is inside a cornerstone builder preview.
		 *
		 * @return bool
		 *@since 1.0.8
		 */
		public function is_cornerstone_preview() {
			$result = false;
			if ( ! empty( $_REQUEST['cornerstone_preview'] ) || basename( $_SERVER['REQUEST_URI'] ) == 'cornerstone-endpoint' ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Tests if the current output is inside a fusion builder preview.
		 *
		 * @return bool
		 *@since 1.1.0
		 */
		public function is_fusion_preview() {
			$result = false;
			if ( ! empty( $_REQUEST['fb-edit'] ) || ! empty( $_REQUEST['fusion_load_nonce'] ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Tests if the current output is inside a Oxygen builder preview.
		 *
		 * @return bool
		 *@since 1.0.18
		 */
		public function is_oxygen_preview() {
			$result = false;
			if ( ! empty( $_REQUEST['ct_builder'] ) || ( ! empty( $_REQUEST['action'] ) && ( substr( $_REQUEST['action'], 0, 11 ) === "oxy_render_" || substr( $_REQUEST['action'], 0, 10 ) === "ct_render_" ) ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Check for Kallyas theme Zion builder preview.
		 *
		 * @since 1.1.22
		 *
		 * @return bool True when preview page otherwise false.
		 */
		public function is_kallyas_zion_preview() {
			$result = false;

			if ( function_exists( 'znhg_kallyas_theme_config' ) && ! empty( $_REQUEST['zn_pb_edit'] ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Check for Bricks theme builder preview.
		 *
		 * @since 1.1.31
		 *
		 * @return bool True when preview page otherwise false.
		 */
		public function is_bricks_preview() {
			$result = false;

			if ( function_exists( 'bricks_is_builder' ) && ( bricks_is_builder() || bricks_is_builder_call() ) ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * General function to check if we are in a preview situation.
		 *
		 * @return bool
		 *@since 1.0.6
		 */
		public function is_preview() {
			$preview = false;
			if ( $this->is_divi_preview() ) {
				$preview = true;
			} elseif ( $this->is_elementor_preview() ) {
				$preview = true;
			} elseif ( $this->is_beaver_preview() ) {
				$preview = true;
			} elseif ( $this->is_siteorigin_preview() ) {
				$preview = true;
			} elseif ( $this->is_cornerstone_preview() ) {
				$preview = true;
			} elseif ( $this->is_fusion_preview() ) {
				$preview = true;
			} elseif ( $this->is_oxygen_preview() ) {
				$preview = true;
			} elseif( $this->is_kallyas_zion_preview() ) {
				$preview = true;
			} elseif( $this->is_block_content_call() ) {
				$preview = true;
			} elseif( $this->is_bricks_preview() ) {
				$preview = true;
			}

			return $preview;
		}

		/**
		 * Output the super title.
		 *
		 * @param $args
		 * @param array $instance
		 *
		 * @return string
		 */
		public function output_title( $args, $instance = array() ) {
			$output = '';
			if ( ! empty( $instance['title'] ) ) {
				/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
				$title  = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );

				if ( empty( $instance['widget_title_tag'] ) ) {
					if ( ! isset( $args['before_title'] ) ) {
						$args['before_title'] = '';
					}

					if ( ! isset( $args['after_title'] ) ) {
						$args['after_title'] = '';
					}

					$output = $args['before_title'] . $title . $args['after_title'];
				} else {
					$tag 			= esc_attr( $instance['widget_title_tag'] );
					$allowed_tags 	= array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'div', 'p' );
					$title_tag      = in_array( $tag, $allowed_tags, true ) ? esc_attr( $tag ) : 'h2';

					// classes
					$title_classes = array();
					$title_classes[] = !empty( $instance['widget_title_size_class'] ) ? sanitize_html_class( $instance['widget_title_size_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_align_class'] ) ? sanitize_html_class( $instance['widget_title_align_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_color_class'] ) ? "text-".sanitize_html_class( $instance['widget_title_color_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_border_class'] ) ? sanitize_html_class( $instance['widget_title_border_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_border_color_class'] ) ? "border-".sanitize_html_class( $instance['widget_title_border_color_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_mt_class'] ) ? "mt-".absint( $instance['widget_title_mt_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_mr_class'] ) ? "mr-".absint( $instance['widget_title_mr_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_mb_class'] ) ? "mb-".absint( $instance['widget_title_mb_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_ml_class'] ) ? "ml-".absint( $instance['widget_title_ml_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_pt_class'] ) ? "pt-".absint( $instance['widget_title_pt_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_pr_class'] ) ? "pr-".absint( $instance['widget_title_pr_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_pb_class'] ) ? "pb-".absint( $instance['widget_title_pb_class'] ) : '';
					$title_classes[] = !empty( $instance['widget_title_pl_class'] ) ? "pl-".absint( $instance['widget_title_pl_class'] ) : '';

					$class = !empty( $title_classes ) ? implode(" ",$title_classes) : '';
					$output = "<$title_tag class='$class' >$title</$title_tag>";
				}

			}

			return $output;
		}

		/**
		 * Outputs the options form inputs for the widget.
		 *
		 * @param array $instance The widget options.
		 */
		public function form( $instance ) {

			// set widget instance
			$this->instance = $instance;

			// set it as a SD widget
			echo $this->widget_advanced_toggle();

			echo "<p>" . esc_attr( $this->options['widget_ops']['description'] ) . "</p>";
			$arguments_raw = $this->get_arguments();

			if ( is_array( $arguments_raw ) ) {

				$arguments = $this->group_arguments( $arguments_raw );

				// Do we have sections?
				$has_sections = $arguments == $arguments_raw ? false : true;


				if ( $has_sections ) {
					$panel_count = 0;
					foreach ( $arguments as $key => $args ) {

						?>
						<script>
							//							jQuery(this).find("i").toggleClass("fas fa-chevron-up fas fa-chevron-down");jQuery(this).next().toggle();
						</script>
						<?php

						$hide       = $panel_count ? ' style="display:none;" ' : '';
						$icon_class = $panel_count ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
						echo "<button onclick='jQuery(this).find(\"i\").toggleClass(\"fas fa-chevron-up fas fa-chevron-down\");jQuery(this).next().slideToggle();' type='button' class='sd-toggle-group-button sd-input-group-toggle" . sanitize_title_with_dashes( $key ) . "'>" . esc_attr( $key ) . " <i style='float:right;' class='" . $icon_class . "'></i></button>";
						echo "<div class='sd-toggle-group sd-input-group-" . sanitize_title_with_dashes( $key ) . "' $hide>";

						foreach ( $args as $k => $a ) {

							$this->widget_inputs_row_start($k, $a);
							$this->widget_inputs( $a, $instance );
							$this->widget_inputs_row_end($k, $a);

						}

						echo "</div>";

						$panel_count ++;

					}
				} else {
					foreach ( $arguments as $key => $args ) {
						$this->widget_inputs_row_start($key, $args);
						$this->widget_inputs( $args, $instance );
						$this->widget_inputs_row_end($key, $args);
					}
				}

			}
		}

		public function widget_inputs_row_start( $key, $args ) {
			if ( ! empty( $args['row'] ) ) {
				// Maybe open
				if ( ! empty( $args['row']['open'] ) ) {
					?>
					<div class='bsui sd-argument' data-argument='<?php echo esc_attr( $args['row']['key'] ); ?>' data-element_require='<?php echo ( ! empty( $args['row']['element_require'] ) ? $this->convert_element_require( $args['row']['element_require'] ) : '' ); ?>'>
					<?php if ( ! empty( $args['row']['title'] ) ) { ?>
					<?php
						if ( isset( $args['row']['icon'] ) ) {
							$args['row']['icon'] = '';
						}

						if ( ! isset( $args['row']['device_type'] ) && isset( $args['device_type'] ) ) {
							$args['row']['device_type'] = $args['device_type'];
						}
					?>
					<label class="mb-0"><?php echo $this->widget_field_title( $args['row'] ); ?><?php echo $this->widget_field_desc( $args['row'] ); ?></label>
					<?php } ?>
					<div class='row<?php echo ( ! empty( $args['row']['class'] ) ? ' ' . esc_attr( $args['row']['class'] ) : '' ); ?>'>
					<div class='col pr-2'>
					<?php
				} else if ( ! empty( $args['row']['close'] ) ) {
					echo "<div class='col pl-0 ps-0'>";
				} else {
					echo "<div class='col pl-0 ps-0 pr-2 pe-2'>";
				}
			}
		}

		public function widget_inputs_row_end( $key, $args ) {
			if ( ! empty( $args['row'] ) ) {
				// Maybe close
				if ( ! empty( $args['row']['close'] ) ) {
					echo "</div></div>";
				}
				echo "</div>";
			}
		}

		/**
		 * Get the hidden input that when added makes the advanced button show on widget settings.
		 *
		 * @return string
		 */
		public function widget_advanced_toggle() {

			$output = '';
			if ( $this->block_show_advanced() ) {
				$val = 1;
			} else {
				$val = 0;
			}

			$output .= "<input type='hidden'  class='sd-show-advanced' value='$val' />";

			return $output;
		}

		/**
		 * Convert require element.
		 *
		 * @param string $input Input element.
		 *
		 * @return string $output
		 *@since 1.0.0
		 *
		 */
		public function convert_element_require( $input ) {
			$input = str_replace( "'", '"', $input );// we only want double quotes

			$output = esc_attr( str_replace( array( "[%", "%]", "%:checked]" ), array(
				"jQuery(form).find('[data-argument=\"",
				"\"]').find('input,select,textarea').val()",
				"\"]').find('input:checked').val()"
			), $input ) );

			return $output;
		}

		/**
		 * Builds the inputs for the widget options.
		 *
		 * @param $args
		 * @param $instance
		 */
		public function widget_inputs( $args, $instance ) {

			$class             = "";
			$element_require   = "";
			$custom_attributes = "";

			// get value
			if ( isset( $instance[ $args['name'] ] ) ) {
				$value = $instance[ $args['name'] ];
			} elseif ( ! isset( $instance[ $args['name'] ] ) && ! empty( $args['default'] ) ) {
				$value = is_array( $args['default'] ) ? array_map( "esc_html", $args['default'] ) : esc_html( $args['default'] );
			} else {
				$value = '';
			}

			// get placeholder
			if ( ! empty( $args['placeholder'] ) ) {
				$placeholder = "placeholder='" . esc_html( $args['placeholder'] ) . "'";
			} else {
				$placeholder = '';
			}

			// get if advanced
			if ( isset( $args['advanced'] ) && $args['advanced'] ) {
				$class .= " sd-advanced-setting ";
			}

			// element_require
			if ( isset( $args['element_require'] ) && $args['element_require'] ) {
				$element_require = $args['element_require'];
			}

			// custom_attributes
			if ( isset( $args['custom_attributes'] ) && $args['custom_attributes'] ) {
				$custom_attributes = $this->array_to_attributes( $args['custom_attributes'], true );
			}

			// before wrapper
			?>
			<p class="sd-argument <?php echo esc_attr( $class ); ?>" data-argument='<?php echo esc_attr( $args['name'] ); ?>' data-element_require='<?php if ( $element_require ) { echo $this->convert_element_require( $element_require );} ?>'>
			<?php
			switch ( $args['type'] ) {
				//array('text','password','number','email','tel','url','color')
				case "text":
				case "password":
				case "number":
				case "email":
				case "tel":
				case "url":
				case "color":
					?>
					<label for="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>"><?php echo $this->widget_field_title( $args );?><?php echo $this->widget_field_desc( $args ); ?></label>
					<input <?php echo $placeholder; ?> class="widefat" <?php echo $custom_attributes; ?> id="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $args['name'] ) ); ?>" type="<?php echo esc_attr( $args['type'] ); ?>" value="<?php echo esc_attr( $value ); ?>">
					<?php

					break;
				case "select":
					$multiple = isset( $args['multiple'] ) && $args['multiple'] ? true : false;
					if ( $multiple ) {
						if ( empty( $value ) ) {
							$value = array();
						}
					}
					?>
					<label for="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>"><?php echo $this->widget_field_title( $args ); ?><?php echo $this->widget_field_desc( $args ); ?></label>
					<select <?php echo $placeholder; ?> class="widefat" <?php echo $custom_attributes; ?> id="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $args['name'] ) ); if ( $multiple ) { echo "[]"; } ?>"
						<?php if ( $multiple ) {
							echo "multiple";
						} //@todo not implemented yet due to gutenberg not supporting it
						?>>
						<?php

						if ( ! empty( $args['options'] ) ) {
							foreach ( $args['options'] as $val => $label ) {
								if ( $multiple ) {
									$selected = in_array( $val, $value ) ? 'selected="selected"' : '';
								} else {
									$selected = selected( $value, $val, false );
								}
								echo "<option value='$val' " . $selected . ">$label</option>";
							}
						}
						?>
					</select>
					<?php
					break;
				case "checkbox":
					?>
					<input <?php echo $placeholder; ?> <?php checked( 1, $value, true ) ?> <?php echo $custom_attributes; ?> class="widefat" id="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $args['name'] ) ); ?>" type="checkbox" value="1">
					<label for="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>"><?php echo $this->widget_field_title( $args );?><?php echo $this->widget_field_desc( $args ); ?></label>
					<?php
					break;
				case "textarea":
					?>
					<label for="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>"><?php echo $this->widget_field_title( $args ); ?><?php echo $this->widget_field_desc( $args ); ?></label>
					<textarea <?php echo $placeholder; ?> class="widefat" <?php echo $custom_attributes; ?> id="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $args['name'] ) ); ?>"><?php echo esc_attr( $value ); ?></textarea>
					<?php

					break;
				case "hidden":
					?>
					<input id="<?php echo esc_attr( $this->get_field_id( $args['name'] ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $args['name'] ) ); ?>" type="hidden" value="<?php echo esc_attr( $value ); ?>">
					<?php
					break;
				default:
					echo "No input type found!"; // @todo we need to add more input types.
			}
			// after wrapper
			?></p><?php
		}

		public function get_widget_icon($icon = 'box-top', $title = ''){
			if($icon=='box-top'){
				return '<svg title="'.esc_attr($title).'" width="20px" height="20px" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd" clip-rule="evenodd" stroke-linejoin="round" stroke-miterlimit="1.414" role="img" aria-hidden="true" focusable="false"><rect x="2.714" y="5.492" width="1.048" height="9.017" fill="#555D66"></rect><rect x="16.265" y="5.498" width="1.023" height="9.003" fill="#555D66"></rect><rect x="5.518" y="2.186" width="8.964" height="2.482" fill="#272B2F"></rect><rect x="5.487" y="16.261" width="9.026" height="1.037" fill="#555D66"></rect></svg>';
			}elseif($icon=='box-right'){
				return '<svg title="'.esc_attr($title).'" width="20px" height="20px" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd" clip-rule="evenodd" stroke-linejoin="round" stroke-miterlimit="1.414" role="img" aria-hidden="true" focusable="false"><rect x="2.714" y="5.492" width="1.046" height="9.017" fill="#555D66"></rect><rect x="15.244" y="5.498" width="2.518" height="9.003" fill="#272B2F"></rect><rect x="5.518" y="2.719" width="8.964" height="0.954" fill="#555D66"></rect><rect x="5.487" y="16.308" width="9.026" height="0.99" fill="#555D66"></rect></svg>';
			}elseif($icon=='box-bottom'){
				return '<svg title="'.esc_attr($title).'" width="20px" height="20px" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd" clip-rule="evenodd" stroke-linejoin="round" stroke-miterlimit="1.414" role="img" aria-hidden="true" focusable="false"><rect x="2.714" y="5.492" width="1" height="9.017" fill="#555D66"></rect><rect x="16.261" y="5.498" width="1.027" height="9.003" fill="#555D66"></rect><rect x="5.518" y="2.719" width="8.964" height="0.968" fill="#555D66"></rect><rect x="5.487" y="15.28" width="9.026" height="2.499" fill="#272B2F"></rect></svg>';
			}elseif($icon=='box-left'){
				return '<svg title="'.esc_attr($title).'" width="20px" height="20px" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd" clip-rule="evenodd" stroke-linejoin="round" stroke-miterlimit="1.414" role="img" aria-hidden="true" focusable="false"><rect x="2.202" y="5.492" width="2.503" height="9.017" fill="#272B2F"></rect><rect x="16.276" y="5.498" width="1.012" height="9.003" fill="#555D66"></rect><rect x="5.518" y="2.719" width="8.964" height="0.966" fill="#555D66"></rect><rect x="5.487" y="16.303" width="9.026" height="0.995" fill="#555D66"></rect></svg>';
			}
		}

		/**
		 * Get the widget input description html.
		 *
		 * @param $args
		 *
		 * @return string
		 * @todo, need to make its own tooltip script
		 */
		public function widget_field_desc( $args ) {

			$description = '';
			if ( isset( $args['desc'] ) && $args['desc'] ) {
				if ( isset( $args['desc_tip'] ) && $args['desc_tip'] ) {
					$description = $this->desc_tip( $args['desc'] );
				} else {
					$description = '<span class="description">' . wp_kses_post( $args['desc'] ) . '</span>';
				}
			}

			return $description;
		}

		/**
		 * Get the widget input title html.
		 *
		 * @param $args
		 *
		 * @return string
		 */
		public function widget_field_title( $args ) {
			$title = '';

			if ( isset( $args['title'] ) && $args['title'] ) {
				if ( ! empty( $args['device_type'] ) ) {
					$args['title'] .= ' (' . $args['device_type'] . ')'; // Append device type to title.
				}

				if ( isset( $args['icon'] ) && $args['icon'] ) {
					$title = self::get_widget_icon( $args['icon'], $args['title']  );
				} else {
					$title = esc_attr( $args['title'] );
				}
			}

			return $title;
		}

		/**
		 * Get the tool tip html.
		 *
		 * @param $tip
		 * @param bool $allow_html
		 *
		 * @return string
		 */
		function desc_tip( $tip, $allow_html = false ) {
			if ( $allow_html ) {
				$tip = $this->sanitize_tooltip( $tip );
			} else {
				$tip = esc_attr( $tip );
			}

			return '<span class="gd-help-tip dashicons dashicons-editor-help" title="' . $tip . '"></span>';
		}

		/**
		 * Sanitize a string destined to be a tooltip.
		 *
		 * @param string $var
		 *
		 * @return string
		 */
		public function sanitize_tooltip( $var ) {
			return htmlspecialchars( wp_kses( html_entity_decode( $var ), array(
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'small'  => array(),
				'span'   => array(),
				'ul'     => array(),
				'li'     => array(),
				'ol'     => array(),
				'p'      => array(),
			) ) );
		}

		/**
		 * Processing widget options on save
		 *
		 * @param array $new_instance The new options
		 * @param array $old_instance The previous options
		 *
		 * @return array
		 * @todo we should add some sanitation here.
		 */
		public function update( $new_instance, $old_instance ) {

			//save the widget
			$instance = array_merge( (array) $old_instance, (array) $new_instance );

			// set widget instance
			$this->instance = $instance;

			if ( empty( $this->arguments ) ) {
				$this->get_arguments();
			}

			// check for checkboxes
			if ( ! empty( $this->arguments ) ) {
				foreach ( $this->arguments as $argument ) {
					if ( isset( $argument['type'] ) && $argument['type'] == 'checkbox' && ! isset( $new_instance[ $argument['name'] ] ) ) {
						$instance[ $argument['name'] ] = '0';
					}
				}
			}

            // maybe sanitize widget title
            if(!empty($instance['title'])) {
                $instance['title'] = wp_kses_post( $instance['title'] );
            }

			return $instance;
		}

		/**
		 * Checks if the current call is a ajax call to get the block content.
		 *
		 * This can be used in your widget to return different content as the block content.
		 *
		 * @return bool
		 *@since 1.0.3
		 */
		public function is_block_content_call() {
			$result = false;
			if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'super_duper_output_shortcode' ) {
				$result = true;
			}

			return $result;
		}

		/**
		 * Get an instance hash that will be unique to the type and settings.
		 *
		 * @return string
		 *@since 1.0.20
		 */
		public function get_instance_hash(){
			$instance_string = $this->base_id.serialize($this->instance);
			return hash('crc32b',$instance_string);
		}

		/**
		 * Generate and return inline styles from CSS rules that will match the unique class of the instance.
		 *
		 * @param array $rules
		 *
		 * @return string
		 *@since 1.0.20
		 */
		public function get_instance_style($rules = array()){
			$css = '';

			if(!empty($rules)){
				$rules = array_unique($rules);
				$instance_hash = $this->get_instance_hash();
				$css .= "<style>";
				foreach($rules as $rule){
					$css .= ".sdel-$instance_hash $rule";
				}
				$css .= "</style>";
			}

			return $css;
		}

		/**
		 * Encode shortcodes tags.
		 *
		 * @param string $content Content to search for shortcode tags.
		 *
*@return string Content with shortcode tags removed.
		 *@since 1.0.28
		 *
		 */
		public function encode_shortcodes( $content ) {
			// Avoids existing encoded tags.
			$trans   = array(
				'&#91;' => '&#091;',
				'&#93;' => '&#093;',
				'&amp;#91;' => '&#091;',
				'&amp;#93;' => '&#093;',
				'&lt;' => '&0lt;',
				'&gt;' => '&0gt;',
				'&amp;lt;' => '&0lt;',
				'&amp;gt;' => '&0gt;',
			);

			$content = strtr( $content, $trans );

			$trans   = array(
				'[' => '&#91;',
				']' => '&#93;',
				'<' => '&lt;',
				'>' => '&gt;',
				'"' => '&quot;',
				"'" => '&#39;',
			);

			$content = strtr( $content, $trans );

			return $content;
		}

		/**
		 * Remove encoded shortcod tags.
		 *
		 * @param string $content Content to search for shortcode tags.
		 *
*@return string Content with decoded shortcode tags.
		 *@since 1.0.28
		 *
		 */
		public function decode_shortcodes( $content ) {
			$trans   = array(
				'&#91;' => '[',
				'&#93;' => ']',
				'&amp;#91;' => '[',
				'&amp;#93;' => ']',
				'&lt;' => '<',
				'&gt;' => '>',
				'&amp;lt;' => '<',
				'&amp;gt;' => '>',
				'&quot;' => '"',
				'&apos;' => "'",
			);

			$content = strtr( $content, $trans );

			$trans   = array(
				'&#091;' => '&#91;',
				'&#093;' => '&#93;',
				'&amp;#091;' => '&#91;',
				'&amp;#093;' => '&#93;',
				'&0lt;' => '&lt;',
				'&0gt;' => '&gt;',
				'&amp;0lt;' => '&lt;',
				'&amp;0gt;' => '&gt;',
			);

			$content = strtr( $content, $trans );

			return $content;
		}

		public function block_visibility_fields( $args ) {
			$value = ! empty( $args['value'] ) ? esc_attr( $args['value'] ) : '';
			$content = '<div class="bs-vc-rule-template d-none">';
				$content .= '<div class="p-3 pb-0 mb-3 border border-1 rounded-1 position-relative bs-vc-rule" data-bs-index="BSVCINDEX" >';
					$content .= '<div class="row">';
						$content .= '<div class="col-sm-12">';
							$content .= '<div class="bs-rule-action position-absolute top-0 end-0 p-2 zindex-5"><span class="text-danger c-pointer bs-vc-remove-rule" title="' . esc_attr__( 'Remove Rule', 'ayecode-connect' ) . '"><i class="fas fa-circle-minus fs-6"></i></span></div>';
							$content .= aui()->select(
								array(
									'id'          => 'bsvc_rule_BSVCINDEX',
									'name'        => 'bsvc_rule_BSVCINDEX',
									'label'       => __( 'Rule', 'ayecode-connect' ),
									'placeholder' => __( 'Select Rule...', 'ayecode-connect' ),
									'class'       => 'bsvc_rule form-select-sm no-select2 mw-100',
									'options'     => sd_visibility_rules_options(),
									'default'     => '',
									'value'       => '',
									'label_type'  => '',
									'select2'     => false,
									'input_group_left' => __( 'Rule:', 'ayecode-connect' ),
									'extra_attributes' => array(
										'data-minimum-results-for-search' => '-1'
									)
								)
							);

						$content .= '</div>';

						if ( class_exists( 'GeoDirectory' ) ) {
							$content .= '<div class="col-md-7 col-sm-12">';

								$content .= aui()->select(
									array(
										'id'          => 'bsvc_gd_field_BSVCINDEX',
										'name'        => 'bsvc_gd_field_BSVCINDEX',
										'label'       => __( 'FIELD', 'ayecode-connect' ),
										'placeholder' => __( 'FIELD', 'ayecode-connect' ),
										'class'       => 'bsvc_gd_field form-select-sm no-select2 mw-100',
										'options'     => sd_visibility_gd_field_options(),
										'default'     => '',
										'value'       => '',
										'label_type'  => '',
										'select2'     => false,
										'element_require'  => '[%bsvc_rule_BSVCINDEX%]=="gd_field"',
										'extra_attributes' => array(
											'data-minimum-results-for-search' => '-1'
										)
									)
								);

							$content .= '</div>';
							$content .= '<div class="col-md-5 col-sm-12">';

								$content .= aui()->select(
									array(
										'id'          => 'bsvc_gd_field_condition_BSVCINDEX',
										'name'        => 'bsvc_gd_field_condition_BSVCINDEX',
										'label'       => __( 'CONDITION', 'ayecode-connect' ),
										'placeholder' => __( 'CONDITION', 'ayecode-connect' ),
										'class'       => 'bsvc_gd_field_condition form-select-sm no-select2 mw-100',
										'options'     => sd_visibility_field_condition_options(),
										'default'     => '',
										'value'       => '',
										'label_type'  => '',
										'select2'     => false,
										'element_require'  => '[%bsvc_rule_BSVCINDEX%]=="gd_field"',
										'extra_attributes' => array(
											'data-minimum-results-for-search' => '-1'
										)
									)
								);

							$content .= '</div>';
							$content .= '<div class="col-sm-12">';

								$content .= aui()->input(
									array(
										'type'            => 'text',
										'id'              => 'bsvc_gd_field_search_BSVCINDEX',
										'name'            => 'bsvc_gd_field_search_BSVCINDEX',
										'label'           => __( 'VALUE TO MATCH', 'ayecode-connect' ),
										'class'           => 'bsvc_gd_field_search form-control-sm',
										'placeholder'     => __( 'VALUE TO MATCH', 'ayecode-connect' ),
										'label_type'      => '',
										'value'           => '',
										'element_require' => '([%bsvc_rule_BSVCINDEX%]=="gd_field" && [%bsvc_gd_field_condition_BSVCINDEX%] && [%bsvc_gd_field_condition_BSVCINDEX%]!="is_empty" && [%bsvc_gd_field_condition_BSVCINDEX%]!="is_not_empty")'
									)
								);

							$content .= '</div>';
						}

                        $content .= apply_filters( 'sd_block_visibility_fields', '', $args );

					$content .= '</div>';

					$content .= '<div class="row aui-conditional-field" data-element-require="jQuery(form).find(\'[name=bsvc_rule_BSVCINDEX]\').val()==\'user_roles\'" data-argument="bsvc_user_roles_BSVCINDEX_1"><label for="bsvc_user_roles_BSVCINDEX_1" class="form-label mb-3">' . __( 'Select User Roles:', 'ayecode-connect' ) . '</label>';
						$role_options = sd_user_roles_options();

						$role_option_i = 0;
						foreach ( $role_options as $role_option_key => $role_option_name ) {
							$role_option_i++;

							$content .= '<div class="col-sm-6">';
							$content .= aui()->input(
								array(
									'id'               => 'bsvc_user_roles_BSVCINDEX_' . $role_option_i,
									'name'             => 'bsvc_user_roles_BSVCINDEX[]',
									'type'             => 'checkbox',
									'label'            => $role_option_name,
									'label_type'       => 'hidden',
									'class'            => 'bsvc_user_roles',
									'value'            => $role_option_key,
									'switch'           => 'md',
									'no_wrap'          => true
								)
							);
							$content .= '</div>';
						}
					$content .= '</div>';
					$content .= '<div class="bs-vc-sep-wrap text-center position-absolute top-0 mt-n3"><div class="bs-vc-sep-cond d-inline-block badge text-dark bg-gray mt-1">' . esc_html__( 'AND', 'ayecode-connect' ) . '</div></div>';
				$content .= '</div>';
			$content .= '</div>';
			$content .= '<form id="bs-vc-modal-form" class="bs-vc-modal-form">';
			$content .= '<div class="bs-vc-rule-sets"></div>';
			$content .= '<div class="row"><div class="col-sm-12 text-center pt-1 pb-4"><button type="button" class="btn btn-sm btn-primary d-block w-100 bs-vc-add-rule"><i class="fas fa-plus"></i> ' . __( 'Add Rule', 'ayecode-connect' ) . '</button></div></div>';
			$content .= '<div class="row"><div class="col-md-6 col-sm-12">';
			$content .= aui()->select(
				array(
					'id'          => 'bsvc_output',
					'name'        => 'bsvc_output',
					'label'       => __( 'What should happen if rules met.', 'ayecode-connect' ),
					'placeholder' => __( 'Show Block', 'ayecode-connect' ),
					'class'       => 'bsvc_output form-select-sm no-select2 mw-100',
					'options'     => sd_visibility_output_options(),
					'default'     => '',
					'value'       => '',
					'label_type'  => 'top',
					'select2'     => false,
					'extra_attributes' => array(
						'data-minimum-results-for-search' => '-1'
					)
				)
			);

			$content .= '</div><div class="col-md-6 col-sm-12">';

			$content .= aui()->select(
				array(
					'id'              => 'bsvc_page',
					'name'            => 'bsvc_page',
					'label'           => __( 'Page Content', 'ayecode-connect' ),
					'placeholder'     => __( 'Select Page ID...', 'ayecode-connect' ),
					'class'           => 'bsvc_page form-select-sm no-select2 mw-100',
					'options'         => sd_template_page_options(),
					'default'         => '',
					'value'           => '',
					'label_type'      => 'top',
					'select2'         => false,
					'element_require' => '[%bsvc_output%]=="page"'
				)
			);

			$content .= aui()->select(
				array(
					'id'          => 'bsvc_tmpl_part',
					'name'        => 'bsvc_tmpl_part',
					'label'       => __( 'Template Part', 'ayecode-connect' ),
					'placeholder' => __( 'Select Template Part...', 'ayecode-connect' ),
					'class'       => 'bsvc_tmpl_part form-select-sm no-select2 mw-100',
					'options'     => sd_template_part_options(),
					'default'     => '',
					'value'       => '',
					'label_type'  => 'top',
					'select2'     => false,
					'element_require'  => '[%bsvc_output%]=="template_part"',
					'extra_attributes' => array(
						'data-minimum-results-for-search' => '-1'
					)
				)
			);

			$content .= aui()->select(
				array(
					'id'               => 'bsvc_message_type',
					'name'             => 'bsvc_message_type',
					'label'            => __( 'Custom Message Type', 'ayecode-connect' ),
					'placeholder'      => __( 'Default (none)', 'ayecode-connect' ),
					'class'            => 'bsvc_message_type form-select-sm no-select2 mw-100',
					'options'          => sd_aui_colors(),
					'default'          => '',
					'value'            => '',
					'label_type'       => 'top',
					'select2'          => false,
					'element_require'  => '[%bsvc_output%]=="message"',
					'extra_attributes' => array(
						'data-minimum-results-for-search' => '-1'
					)
				)
			);

			$content .= '</div><div class="col-sm-12">';

			$content .= aui()->input(
				array(
					'type'            => 'text',
					'id'              => 'bsvc_message',
					'name'            => 'bsvc_message',
					'label'           => '',
					'class'           => 'bsvc_message form-control-sm mb-3',
					'placeholder'     => __( 'CUSTOM MESSAGE TO SHOW', 'ayecode-connect' ),
					'label_type'      => '',
					'value'           => '',
					'form_group_class' => ' ',
					'element_require' => '[%bsvc_output%]=="message"',
				)
			);

			$content .= '</div></div><div class="row"><div class="col col-12"><div class="pt-3 mt-1 border-top"></div></div><div class="col-md-6 col-sm-12">';
			$content .= aui()->select(
				array(
					'id'          => 'bsvc_output_n',
					'name'        => 'bsvc_output_n',
					'label'       => __( 'What should happen if rules NOT met.', 'ayecode-connect' ),
					'placeholder' => __( 'Show Block', 'ayecode-connect' ),
					'class'       => 'bsvc_output_n form-select-sm no-select2 mw-100',
					'options'     => sd_visibility_output_options(),
					'default'     => '',
					'value'       => '',
					'label_type'  => 'top',
					'select2'     => false,
					'extra_attributes' => array(
						'data-minimum-results-for-search' => '-1'
					)
				)
			);

			$content .= '</div><div class="col-md-6 col-sm-12">';

			$content .= aui()->select(
				array(
					'id'              => 'bsvc_page_n',
					'name'            => 'bsvc_page_n',
					'label'           => __( 'Page Content', 'ayecode-connect' ),
					'placeholder'     => __( 'Select Page ID...', 'ayecode-connect' ),
					'class'           => 'bsvc_page_n form-select-sm no-select2 mw-100',
					'options'         => sd_template_page_options(),
					'default'         => '',
					'value'           => '',
					'label_type'      => 'top',
					'select2'         => false,
					'element_require' => '[%bsvc_output_n%]=="page"'
				)
			);

			$content .= aui()->select(
				array(
					'id'          => 'bsvc_tmpl_part_n',
					'name'        => 'bsvc_tmpl_part_n',
					'label'       => __( 'Template Part', 'ayecode-connect' ),
					'placeholder' => __( 'Select Template Part...', 'ayecode-connect' ),
					'class'       => 'bsvc_tmpl_part_n form-select-sm no-select2 mw-100',
					'options'     => sd_template_part_options(),
					'default'     => '',
					'value'       => '',
					'label_type'  => 'top',
					'select2'     => false,
					'element_require'  => '[%bsvc_output_n%]=="template_part"',
					'extra_attributes' => array(
						'data-minimum-results-for-search' => '-1'
					)
				)
			);

			$content .= aui()->select(
				array(
					'id'               => 'bsvc_message_type_n',
					'name'             => 'bsvc_message_type_n',
					'label'            => __( 'Custom Message Type', 'ayecode-connect' ),
					'placeholder'      => __( 'Default (none)', 'ayecode-connect' ),
					'class'            => 'bsvc_message_type_n form-select-sm no-select2 mw-100',
					'options'          => sd_aui_colors(),
					'default'          => '',
					'value'            => '',
					'label_type'       => 'top',
					'select2'          => false,
					'element_require'  => '[%bsvc_output_n%]=="message"',
					'extra_attributes' => array(
						'data-minimum-results-for-search' => '-1'
					)
				)
			);

			$content .= '</div><div class="col-sm-12">';

			$content .= aui()->input(
				array(
					'type'            => 'text',
					'id'              => 'bsvc_message_n',
					'name'            => 'bsvc_message_n',
					'label'           => '',
					'class'           => 'bsvc_message_n form-control-sm',
					'placeholder'     => __( 'CUSTOM MESSAGE TO SHOW', 'ayecode-connect' ),
					'label_type'      => '',
					'value'           => '',
					'form_group_class' => ' ',
					'element_require' => '[%bsvc_output_n%]=="message"',
				)
			);

			$content .= '</div></div></form><input type="hidden" id="bsvc_raw_value" name="bsvc_raw_value" value="' . $value . '">';

			return $content;
		}

		/**
		 * Handle media_buttons hook.
		 *
		 * @since 1.2.7
		 */
		public function wp_media_buttons() {
			global $shortcode_insert_button_once;

			// Fix conflicts with UpSolution Core in header template edit element.
			if ( defined( 'US_CORE_DIR' ) && ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] == 'us_ajax_hb_get_ebuilder_html' ) {
				$shortcode_insert_button_once = true;
			}
		}
	}
}
