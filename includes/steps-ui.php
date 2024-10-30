<?php
/**
 * Custom Achievement Steps UI
 *
 * @package BadgeOS Custom Post Type Addon
 * @subpackage Achievements
 * @author konnektiv
 * @license http://www.gnu.org/licenses/agpl.txt GNU AGPL v3.0
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

class BadgeOS_CPT_Steps_UI {

    /**
     * @var BadgeOS_CPT_Steps_UI
     */
    private static $instance;

    /**
     * Main BadgeOS_CPT_Steps_UI Instance
     *
     * Insures that only one instance of BadgeOS_CPT_Steps_UI exists in memory at
     * any one time. Also prevents needing to define globals all over the place.
     *
     * @since BadgeOS_CPT_Steps_UI (0.0.3)
     *
     * @staticvar array $instance
     *
     * @return BadgeOS_CPT_Steps_UI
     */
    public static function instance( ) {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new BadgeOS_CPT_Steps_UI;
            self::$instance->setup_filters();
            self::$instance->setup_actions();
        }

        return self::$instance;
    }

    /**
     * A dummy constructor to prevent loading more than one instance
     *
     * @since BadgeOS_CPT_Steps_UI (0.0.1)
     */
    private function __construct() { /* Do nothing here */
    }

    /**
     * Setup the actions
     *
     * @since BadgeOS_CPT_Steps_UI (0.0.1)
     * @access private
     *
     * @uses remove_action() To remove various actions
     * @uses add_action() To add various actions
     */
    private function setup_actions() {
        add_action( 'badgeos_steps_ui_html_after_trigger_type', array( $this, 'step_category_select' ), 10, 2 );
        add_action( 'badgeos_steps_ui_html_after_trigger_type', array( $this, 'step_forum_select' ), 10, 2 );
        add_action( 'admin_footer', array( $this, 'step_js' ) );
    }

    /**
     * Setup the filters
     *
     * @since BadgeOS_CPT_Steps_UI (0.0.1)
     * @access private
     *
     * @uses remove_filter() To remove various filters
     * @uses add_filter() To add various filters
     */
    private function setup_filters() {
        add_filter( 'badgeos_save_step', array( $this, 'save_cpt_step' ), 10, 3 );
        add_filter( 'badgeos_save_step', array( $this, 'save_bbp_step' ), 10, 3 );
    }

    /**
     * Add a category selector to the Steps UI
     *
     * @since 0.0.1
     * @param integer $step_id The given step's post ID
     * @param integer $post_id The given parent post's post ID
     */
    function step_category_select( $step_id, $post_id ) {

        $post_types = BadgeOS_CPT_Rules_Engine::instance()->get_post_types();

        foreach( $post_types as $post_type ) {
            $taxonomies = get_object_taxonomies( $post_type, 'objects' );

            foreach( $taxonomies as $taxonomy_name => $taxonomy ){
                if ( ! $taxonomy->show_ui ) continue;

                $terms = get_terms( $taxonomy_name, array( 'hide_empty' => false ) );

                if ( empty( $terms ) ) continue;

                // Setup our select input
                echo '<select name="cpt_' . $post_type . '_' . $taxonomy_name . '_id" class="cpt-select-term-id cpt-select-'.$post_type.'-term-id cpt-select-term-id-' . $post_id . '" data-post-type="' . $post_type . '" data-taxonomy="' . $taxonomy_name . '">';
                echo '<option value="">' . sprintf( __( 'In any %s', 'badgeos-cpt' ), $taxonomy->labels->singular_name ) . '</option>';

                // Loop through all existing categories and include them here
                $current_selection = get_post_meta( $step_id, "_badgeos_cpt_{$taxonomy_name}_term_id", true );

                foreach ( $terms as $term ) {
                    echo '<option' . selected( $current_selection, $term->term_id, false ) . ' value="' . $term->term_id . '">' . sprintf(__( 'In the %s %s', 'badgeos-cpt' ), $taxonomy->labels->singular_name, $term->name ) . '</option>';
                }

                echo '</select>';
            }
        }

    }

    /**
     * Add a forum selector to the Steps UI
     *
     * @since 0.0.1
     * @param integer $step_id The given step's post ID
     * @param integer $post_id The given parent post's post ID
     */
    function step_forum_select( $step_id, $post_id ) {

        if ( ! function_exists( 'bbp_get_forum_post_type' ) )
            return;

        $query = new WP_Query( array(
            'post_type'      => bbp_get_forum_post_type(),
            'posts_per_page' => -1
        ) );

        if ( empty( $query->posts ) ) {
            return;
        }

        $post_type = get_post_type_object( bbp_get_forum_post_type() );
        $current_selection = get_post_meta( $step_id, '_badgeos_cpt_forum_id', true );

        // Setup our select input
        echo '<select name="forum_id" class="select-forum-id select-forum-id-' . $post_id . '">';
        echo '<option value="">' . sprintf( __( 'In any %s', 'badgeos-cpt' ), $post_type->labels->singular_name ) . '</option>';

        // Loop through all existing forums and include them here
        foreach( $query->posts as $forum ) {

            echo '<option' . selected( $current_selection, $forum->ID, false ) . ' value="' . $forum->ID . '">' . sprintf(__( 'In the %s %s', 'badgeos-cpt' ), $post_type->labels->singular_name, $forum->post_title ) . '</option>';

        }

        echo '</select>';

    }


    /**
     * AJAX Handler for saving all steps
     *
     * @since  0.0.1
     * @param  string  $title     The original title for our step
     * @param  integer $step_id   The given step's post ID
     * @param  array   $step_data Our array of all available step data
     * @return string             Our potentially updated step title
     */
    function save_cpt_step( $title, $step_id, $step_data ) {

        $trigger = $step_data['trigger_type'];
        if ( strpos( $trigger, 'badgeos_cpt_' ) !== 0 )
            return $title;

		$post_type = str_replace( array( 'badgeos_cpt_comment_', 'badgeos_cpt_publish_' ), '', $trigger );

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		$first = true;
		foreach( $taxonomies as $taxonomy_name => $taxonomy ){
			if ( ! $taxonomy->show_ui ) continue;

			$term_id = $step_data[ "cpt_{$post_type}_{$taxonomy_name}_term_id" ];

			// Store our term ID in meta
			update_post_meta( $step_id, "_badgeos_cpt_{$taxonomy_name}_term_id", $term_id );

			// Pass along our custom post title
			if ( $term_id ) {
				$term = get_term( $term_id, $taxonomy_name );
                $title = preg_replace('/1 time.$/', '', $title);
				$title .= sprintf( __( '%s in the %s %s', 'badgeos-cpt' ),
								  $first?'':' and', $taxonomy->labels->singular_name, $term->name );
				$first = false;
			}
		}

    	// Send back our custom title
    	return $title;
    }

    /**
     * AJAX Handler for saving all steps
     *
     * @since  0.0.1
     * @param  string  $title     The original title for our step
     * @param  integer $step_id   The given step's post ID
     * @param  array   $step_data Our array of all available step data
     * @return string             Our potentially updated step title
     */
    function save_bbp_step( $title, $step_id, $step_data ) {

        // If we're not working on a community trigger, bail
        if ( 'community_trigger' != $step_data['trigger_type'] )
            return $title;

        // Rewrite the step title
        $title = $step_data['community_trigger_label'];

        if ( ! in_array( $step_data['community_trigger'], array( 'bbp_new_topic', 'bbp_new_reply' ) ) )
            return $title;

        $forum_id = $step_data['forum_id'];

         // Store our forum ID in meta
        update_post_meta( $step_id, '_badgeos_cpt_forum_id', $forum_id );

        if ( ! $forum_id )
            return $title;

        $forum = get_post( $forum_id );

        if ( ! $forum )
            return $title;

        $post_type = get_post_type_object( bbp_get_forum_post_type() );

        // Pass along our custom post title
        $title = preg_replace('/1 time.$/', '', $title);
        $title .= sprintf( __( ' in the %s %s', 'badgeos-cpt' ),
            $post_type->labels->singular_name, $forum->post_title );

        // Send back our custom title
        return $title;
    }


    /**
     * Include custom JS for the BadgeOS Steps UI.
     *
     * @since 0.0.1
     */
    function step_js() {

    	?>
    	<script type="text/javascript">
    		jQuery( document ).ready( function ( $ ) {

                function stringStartsWith (string, prefix) {
                    return string.slice(0, prefix.length) == prefix;
                }

                function requirementSelected( elem ) {
                    var selected = false;

                    $(elem).siblings('.cpt-select-term-id, .select-forum-id').each( function( index, sibling ) {
                        if ( $(sibling).val() ) {
                            selected = true;
                            return false;
                        }
                    } );
                    return selected;
                }

                $( document ).on( 'change', '.select-trigger-type', function() {
                    var trigger_type = $(this),
                        trigger_name = trigger_type.val();

                    if ( stringStartsWith(trigger_name, 'badgeos_cpt_') ) {
                        var post_type = trigger_name
                            .replace('badgeos_cpt_comment_', '')
                            .replace('badgeos_cpt_publish_', '');

                        trigger_type.siblings( '.cpt-select-term-id' ).not('.cpt-select-' + post_type + '-term-id' ).hide();
                        trigger_type.siblings( '.cpt-select-' + post_type + '-term-id' ).show();

                        if ( requirementSelected( trigger_type ) ) {
                            trigger_type.siblings( '.required-count' ).val(1);
                            trigger_type.siblings( '.required-count' ).prop( 'disabled', true );
                        } else {

                        }

                    } else {
                        trigger_type.siblings( '.cpt-select-term-id' ).hide();
                    }

                    if ( trigger_name != 'community_trigger' ) {
                        trigger_type.siblings('.select-forum-id').hide();
                    }

                    if ( ! stringStartsWith(trigger_name, 'badgeos_cpt_') && trigger_name != 'community_trigger' || ! requirementSelected( trigger_type ) ) {
                        trigger_type.siblings( '.required-count' ).prop( 'disabled', false );
                    }
                });

                // Listen for our change to our trigger type selector
                $( document ).on( 'change', '.select-community-trigger', function() {

                    var trigger_type = $(this),
                        trigger_name = trigger_type.val();

                    if ( trigger_name == 'bbp_new_topic' ||
                         trigger_name == 'bbp_new_reply' ) {

                        trigger_type.siblings( '.select-forum-id' ).show();
                        trigger_type.siblings( '.required-count' ).prop( 'disabled', true );
                    } else {
                        trigger_type.siblings( '.select-forum-id' ).hide();
                        trigger_type.siblings( '.required-count' ).prop( 'disabled', false );
                    }

                });

                // Listen for our change to our trigger type selector
                $( document ).on( 'change', '.select-forum-id, .cpt-select-term-id', function() {
                    if ( $(this).val() ) {
                        $(this).siblings( '.required-count' ).prop( 'disabled', true );
                    } else if ( ! requirementSelected( this ) ) {
                        $(this).siblings( '.required-count' ).prop( 'disabled', false );
                    }
                });

                // Trigger a change so we properly show/hide our community menues
    		    $('.select-trigger-type').change();

                // Inject our custom step details into the update step action
                $(document).on( 'update_step_data', function( event, step_details, step ) {
                    $( '.cpt-select-term-id:visible', step ).each( function() {
                        var post_type = $(this).data('post-type'),
                            taxonomy = $(this).data('taxonomy');
                        step_details['cpt_' + post_type + '_' + taxonomy + '_term_id'] = $(this).val();
                    });

                    if ( $( '.select-forum-id:visible' ).length )
                        step_details['forum_id'] = $( '.select-forum-id:visible' ).val();
                });
    		});
    	</script>
    <?php
    }
}

BadgeOS_CPT_Steps_UI::instance();
