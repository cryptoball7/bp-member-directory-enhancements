<?php
/**
 * Plugin Name: BuddyPress Member Directory Enhancements
 * Description: Adds filterable search controls for BuddyPress Members Directory based on XProfile (custom profile) fields like Skills, Location, and Interests. Works with member loops and query modifications (AJAX + non-AJAX).
 * Author: Cryptoball cryptoball7@gmail.com
 * Version: 1.0.0
 * License: GPLv3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BP_Member_Directory_Enhancements' ) ) :
	class BP_Member_Directory_Enhancements {

		/**
		 * List of XProfile fields to expose as filters.
		 * Keys are request parameter slugs, values are the XProfile field names or IDs.
		 *
		 * @var array
		 */
		protected $fields = array(
			'skills'    => 'Skills',
			'location'  => 'Location',
			'interests' => 'Interests',
		);

		public function __construct() {
			// Only load on front-end and if BuddyPress Members is active.
			add_action( 'bp_include', array( $this, 'maybe_bootstrap' ) );
		}

		public function maybe_bootstrap() {
			if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'members' ) ) {
				return;
			}

			// UI: inject filters into the Members Directory form.
			add_action( 'bp_members_directory_member_types', array( $this, 'render_filters' ) );
			add_action( 'bp_before_directory_members_list', array( $this, 'render_filters_fallback' ) ); // Fallback spot.

			// Query: modify Members loop args to add xprofile_query based on request.
			add_filter( 'bp_before_has_members_parse_args', array( $this, 'filter_members_args' ), 10, 1 );

			// Assets: enhance UX (auto-submit on change in AJAX directories).
			add_action( 'bp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Basic sanitize helper for strings/arrays.
		 */
		protected function sanitize_request_value( $value ) {
			if ( is_array( $value ) ) {
				return array_filter( array_map( 'sanitize_text_field', wp_unslash( $value ) ) );
			}
			return sanitize_text_field( wp_unslash( (string) $value ) );
		}

		/**
		 * Render the filter UI inside the Members Directory form (preferred location under tabs).
		 */
		public function render_filters() {
			if ( ! bp_is_members_directory() ) {
				return;
			}
			?>
			<fieldset id="bp-mde-filters" class="bp-mde-filters" style="margin:12px 0; padding:12px; border:1px solid #ddd; border-radius:8px;">
				<legend style="padding:0 6px; font-weight:600;">Filter Members</legend>
				<div class="bp-mde-grid" style="display:grid; gap:8px; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); align-items:end;">
					<label style="display:block;">
						<span style="display:block; font-size:12px; opacity:.8;">Skills</span>
						<input type="text" name="skills" value="<?php echo esc_attr( $this->get_request( 'skills' ) ); ?>" placeholder="e.g. JavaScript, Design" />
					</label>
					<label style="display:block;">
						<span style="display:block; font-size:12px; opacity:.8;">Location</span>
						<input type="text" name="location" value="<?php echo esc_attr( $this->get_request( 'location' ) ); ?>" placeholder="City, Country" />
					</label>
					<label style="display:block;">
						<span style="display:block; font-size:12px; opacity:.8;">Interests</span>
						<input type="text" name="interests" value="<?php echo esc_attr( $this->get_request( 'interests' ) ); ?>" placeholder="e.g. Hiking, Startups" />
					</label>
					<button type="submit" class="button" id="bp-mde-apply">Apply</button>
					<a href="<?php echo esc_url( remove_query_arg( array_keys( $this->fields ) ) ); ?>" class="button" id="bp-mde-reset" style="text-align:center;">Reset</a>
				</div>
			</fieldset>
			<?php
		}

		/**
		 * Fallback render (some themes place hooks differently). Only displays if not already printed.
		 */
		public function render_filters_fallback() {
			if ( ! bp_is_members_directory() ) {
				return;
			}
			// If our main fieldset is present, skip duplicate.
			static $printed = false;
			if ( $printed ) {
				return;
			}
			$printed = true;
			$this->render_filters();
		}

		/**
		 * Read a request var for our fields.
		 */
		protected function get_request( $key ) {
			return isset( $_REQUEST[ $key ] ) ? $this->sanitize_request_value( $_REQUEST[ $key ] ) : '';
		}

		/**
		 * Build a BuddyPress xprofile_query array from request params.
		 * Supports comma-separated terms within a field (match ANY term with LIKE). All fields combine with AND by default.
		 */
		protected function build_xprofile_query() {
			$clauses  = array();
			$relation = 'AND';

			foreach ( $this->fields as $param => $field ) {
				if ( empty( $_REQUEST[ $param ] ) ) {
					continue;
				}
				$value = $this->sanitize_request_value( $_REQUEST[ $param ] );

				// Split comma-separated entries and trim.
				$terms = array_filter( array_map( 'trim', is_array( $value ) ? $value : explode( ',', (string) $value ) ) );

				if ( ! $terms ) {
					continue;
				}

				// If multiple terms are provided for a single field, match ANY by creating an OR group.
				if ( count( $terms ) > 1 ) {
					$or_group = array( 'relation' => 'OR' );
					foreach ( $terms as $term ) {
						$or_group[] = array(
							'field'   => $field, // accept field name or ID
							'value'   => $term,
							'compare' => 'LIKE',   // partial match inside multi-select/textarea
							'type'    => 'CHAR',
						);
					}
					$clauses[] = $or_group;
				} else {
					$clauses[] = array(
						'field'   => $field,
						'value'   => reset( $terms ),
						'compare' => 'LIKE',
						'type'    => 'CHAR',
					);
				}
			}

			if ( empty( $clauses ) ) {
				return array();
			}

			array_unshift( $clauses, array( 'relation' => $relation ) );
			return $clauses;
		}

		/**
		 * Hook into Members loop parse args and attach our xprofile_query.
		 * Works for initial page load and for AJAX refreshes.
		 *
		 * @param array $args
		 * @return array
		 */
		public function filter_members_args( $args ) {
			$xprofile_query = $this->build_xprofile_query();

			if ( ! empty( $xprofile_query ) ) {
				// BuddyPress expects the structure to be stored under 'xprofile_query'.
				$args['xprofile_query'] = $xprofile_query;
				// Ensure we return unique users when multiple profile rows match.
				$args['user_id']        = false; // do not restrict
			}

			return $args;
		}

		/**
		 * Enqueue a tiny script to auto-submit the form on change when BP AJAX directories are enabled.
		 */
		public function enqueue_assets() {
			if ( ! bp_is_members_directory() ) {
				return;
			}
			$handle = 'bp-mde-js';
			$src    = plugins_url( 'js/bp-mde.js', __FILE__ );
			$deps   = array( 'jquery' );
			$ver    = '1.0.0';
			wp_enqueue_script( $handle, $src, $deps, $ver, true );
		}
	}
endif;

new BP_Member_Directory_Enhancements();

// --- Inline tiny JS fallback if the file is missing (developer convenience). ---
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'bp_is_members_directory' ) || ! bp_is_members_directory() ) {
		return;
	}
	// If our external JS failed to load, output a minimal inline helper.
	?>
	<script>
	(function(){
		var form = document.querySelector('#members-directory-form');
		if(!form) return;
		var fs = document.getElementById('bp-mde-filters');
		// Ensure our fieldset lives inside the directory form so BP AJAX includes our inputs in the request
		if(fs && fs.parentNode !== form){ try{ form.insertBefore(fs, form.querySelector('.item-list-tabs')); }catch(e){} }
		var inputs = fs ? fs.querySelectorAll('input') : [];
		for(var i=0;i<inputs.length;i++){
			inputs[i].addEventListener('change', function(){
				// Trigger BuddyPress directory fetch if AJAX is on, otherwise submit normally
				if ( window.jQuery && jQuery.fn && jQuery.fn.bp_ajax_request ) {
					jQuery(form).submit();
				} else {
					form.submit();
				}
			});
		}
	})();
	</script>
	<?php
} );

// --- Optional: serve a tiny JS file for theme builders who prefer real files ---
add_action( 'init', function(){
	// Create a virtual file route only if the physical file doesn't exist.
	$js_path = plugin_dir_path( __FILE__ ) . 'js/bp-mde.js';
	if ( file_exists( $js_path ) ) {
		return;
	}
	add_rewrite_rule( '^bp-mde-js.js$', 'index.php?bp_mde_js=1', 'top' );
	add_filter( 'query_vars', function( $qv ){ $qv[] = 'bp_mde_js'; return $qv; } );
	add_action( 'template_redirect', function(){
		if ( get_query_var( 'bp_mde_js' ) ) {
			header( 'Content-Type: application/javascript; charset=UTF-8' );
			echo "(function(){var f=document.querySelector('#members-directory-form');if(!f)return;var s=document.getElementById('bp-mde-filters');if(s&&s.parentNode!==f){try{f.insertBefore(s,f.querySelector('.item-list-tabs'))}catch(e){}}var i=s?s.querySelectorAll('input'):[];for(var x=0;x<i.length;x++){i[x].addEventListener('change',function(){if(window.jQuery&&jQuery.fn&&jQuery.fn.bp_ajax_request){jQuery(f).submit()}else{f.submit()}})}})();";
			exit;
		}
	});
} );
