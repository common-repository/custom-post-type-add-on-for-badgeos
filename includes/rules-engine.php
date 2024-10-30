<?php

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

class BadgeOS_CPT_Rules_Engine {

    /**
     * @var BadgeOS_CPT_Rules_Engine
     */
    private static $instance;

    /**
     * Main BadgeOS_CPT_Rules_Engine Instance
     *
     * Insures that only one instance of BadgeOS_CPT_Rules_Engine exists in memory at
     * any one time. Also prevents needing to define globals all over the place.
     *
     * @since BadgeOS_CPT_Rules_Engine (0.0.3)
     *
     * @staticvar array $instance
     *
     * @return BadgeOS_CPT_Rules_Engine
     */
    public static function instance( ) {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new BadgeOS_CPT_Rules_Engine;
            self::$instance->setup_filters();
            self::$instance->setup_actions();
        }

        return self::$instance;
    }

    /**
     * A dummy constructor to prevent loading more than one instance
     *
     * @since BadgeOS_CPT_Rules_Engine (0.0.1)
     */
    private function __construct() { /* Do nothing here */
    }

    /**
     * Setup the actions
     *
     * @since BadgeOS_CPT_Rules_Engine (0.0.1)
     * @access private
     *
     * @uses remove_action() To remove various actions
     * @uses add_action() To add various actions
     */
    private function setup_actions() {
        add_action( 'transition_post_status', array( $this, 'maybe_trigger_publish_post' ), 10, 3 );
        add_action( 'comment_post', array( $this, 'maybe_trigger_publish_comment' ), 8, 2 );
        add_action( 'transition_comment_status', array( $this, 'check_comment_approved' ), 10, 3);
        add_action( 'set_object_terms', array( $this, 'object_terms_changed' ), 10, 6 );

        // execute registration of badgeos triggers late, so all post types have been registered
        add_action( 'init', array( $this, 'load_triggers' ), 99 );
    }

    /**
     * Setup the filters
     *
     * @since BadgeOS_CPT_Rules_Engine (0.0.1)
     * @access private
     *
     * @uses remove_filter() To remove various filters
     * @uses add_filter() To add various filters
     */
    private function setup_filters() {
        add_filter( 'badgeos_activity_triggers', array( $this, 'add_triggers') );
        add_filter( 'badgeos_bp_trigger_event_user_id', array( $this, 'filter_user_id' ), 10, 3 );
        add_filter( 'user_deserves_achievement', array( $this, 'user_deserves_cpt_step' ), 15, 6 );
        add_filter( 'user_deserves_achievement', array( $this, 'user_deserves_bbp_step' ), 15, 6 );
    }


    function get_post_types() {
        $skipped_types = array( 'post', 'page' );

        // bbpress post types are handled elsewhere
        foreach ( array( 'bbp_get_topic_post_type', 'bbp_get_reply_post_type', 'bbp_get_forum_post_type' ) as $func ) {
            if ( function_exists( $func ) )
                $skipped_types[] = call_user_func( $func );
        }


        // do not include badgeos achievement types
        $skipped_types = array_merge($skipped_types, badgeos_get_achievement_types_slugs() );

        return array_diff( get_post_types( array( 'public' => true ) ), $skipped_types );
    }

    function get_triggers() {
        $triggers = array();

        $post_types = $this->get_post_types();

        foreach( $post_types as $post_type ) {
            $info = get_post_type_object( $post_type );

            $triggers["badgeos_cpt_publish_$post_type"] = sprintf( __( 'Publish a new %s', 'badgeos-cpt' ), $info->labels->singular_name );
            $triggers["badgeos_cpt_comment_$post_type"] = sprintf( __( 'Comment on a %s',  'badgeos-cpt' ), $info->labels->singular_name );
        }

        return $triggers;
    }

    function add_triggers( $triggers ) {
        return array_merge( $triggers, $this->get_triggers() );
    }

    function load_triggers() {

        // Loop through each trigger and add badgeos trigger event to the hook
        foreach ( $this->get_triggers() as $trigger => $label ) {
            if ( ! has_action( $trigger, 'badgeos_trigger_event' ) )
                add_action( $trigger, 'badgeos_trigger_event', 10, 20 );
        }
    }

    function object_terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        $post = get_post( $object_id );

        // if the post does not exist, bail
        if ( !$post )
            return;

        // check if this post was just created
        $published = get_post_meta( $post->ID, '_badgeos_cpt_post_published', true );

        if ( ! $published )
            return;

        delete_post_meta( $post->ID, '_badgeos_cpt_post_published' );

        // only trigger if terms where changed and new terms are set
        if ( empty( $tt_ids ) )
            return;
        $diff = array_diff( $tt_ids, $old_tt_ids );

        if ( empty( $diff ) )
            return;

        do_action( "badgeos_cpt_publish_{$post->post_type}", $post->post_author, $post->ID );
    }

    /**
     * Trigger create post action
     *
     * @since 1.0.0
     *
     */
    function maybe_trigger_publish_post( $new_status, $old_status, $post ) {

        // If this is a revision, don't trigger
        if ( wp_is_post_revision( $post->ID ) )
            return;

        // If post is not published, don't trigger
        if ( $new_status != 'publish' || $old_status == 'publish' )
            return;

        add_post_meta( $post->ID, '_badgeos_cpt_post_published', true, true );

        do_action( "badgeos_cpt_publish_{$post->post_type}", $post->post_author, $post->ID );
    }

    /**
     * Trigger comment action when a comment is approved
     *
     * @since 1.0.0
     *
     */
    function check_comment_approved( $new_status, $old_status, $comment ) {

        // If comment is not approved, don't trigger
        if ( $new_status != 'approved' )
            return;

        $this->maybe_trigger_publish_comment( $comment->comment_ID, 1, $comment );
    }

    /**
     * Trigger comment action when a post is commented
     *
     * @since 1.0.0
     *
     */
    function maybe_trigger_publish_comment( $comment_ID, $comment_approved, $comment = null ) {

        // If comment is not approved, don't trigger
        if ( $comment_approved !== 1 )
            return;

        if ( ! $comment )
            $comment = get_comment( $comment_ID );

        // If comment is not from a registered user, don't trigger
        if ( ! $comment->user_id )
            return;

        $post = get_post( $comment->comment_post_ID );

        do_action( "badgeos_cpt_comment_{$post->post_type}", $comment->user_id, $post->ID );
    }


    function filter_user_id( $user_ID, $current_filter, $args ) {

        if ( strpos( $current_filter, 'badgeos_cpt_' ) === 0 )
    		return absint( $args[0] );
    	return $user_ID;
    }

    /**
     * Check if user deserves a "Create/comment post step" for a specific term
     *
     * @since  1.0.0
     * @param  bool    $return         Whether or not the user deserves the step
     * @param  integer $user_id        The given user's ID
     * @param  integer $achievement_id The given achievement's post ID
     * @param  string  $this_trigger   The trigger
     * @param  integer $site_id        The triggered site id
     * @param  array   $args           The triggered args
     * @return bool                    True if the user deserves the step, false otherwise
     */
    function user_deserves_cpt_step( $return, $user_id, $achievement_id, $this_trigger, $site_id, $args ) {

    	// If we're not dealing with a step, bail here
    	if ( 'step' != get_post_type( $achievement_id ) )
    		return $return;

    	// Grab our step requirements
    	$requirements = badgeos_get_step_requirements( $achievement_id );

    	// If the step is not triggered by our actions, bail
    	if ( strpos( $this_trigger, 'badgeos_cpt_') !== 0 || count( $args ) < 2 )
            return $return;

		$post_id = $args[1];
		$post_type = get_post_type( $post_id );

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		foreach( $taxonomies as $taxonomy_name => $taxonomy ){
			if ( ! $taxonomy->show_ui ) continue;

			$term_id = get_post_meta( $achievement_id, "_badgeos_cpt_{$taxonomy_name}_term_id", true );

			// if a $term is set and the post does not have the specified term, return false
			if ( $term_id && ! has_term( intval( $term_id ), $taxonomy_name, $post_id ) )
				$return = false;
		}

    	return $return;
    }

    /**
     * Check if user deserves a "Create forum topic/reply step" for a specific forum
     *
     * @since  1.0.0
     * @param  bool    $return         Whether or not the user deserves the step
     * @param  integer $user_id        The given user's ID
     * @param  integer $achievement_id The given achievement's post ID
     * @param  string  $this_trigger   The trigger
     * @param  integer $site_id        The triggered site id
     * @param  array   $args           The triggered args
     * @return bool                    True if the user deserves the step, false otherwise
     */
    function user_deserves_bbp_step( $return, $user_id, $achievement_id, $this_trigger, $site_id, $args ) {

        // If we're not dealing with a step, bail here
        if ( 'step' != get_post_type( $achievement_id ) )
            return $return;

        // Grab our step requirements
        $requirements = badgeos_get_step_requirements( $achievement_id );

        // If the step is not triggered by our actions, bail
        if ( ! in_array( $requirements['community_trigger'], array( 'bbp_new_topic', 'bbp_new_reply' ) ) )
            return $return;

        $forum_id = get_post_meta( $achievement_id, '_badgeos_cpt_forum_id', true );

        $post = get_post( $args );

        switch ( $post->post_type ) {
            case bbp_get_reply_post_type():
                $current_forum_id =  bbp_get_reply_forum_id( $post->ID );
                break;

            case bbp_get_topic_post_type():
                $current_forum_id =  bbp_get_topic_forum_id( $post->ID );
                break;

            default:
                $current_forum_id = 0;
                break;
        }

        // And the post ist in the specified forum or no forum is specified
        if ( ! $forum_id || $forum_id == $current_forum_id )
            $return = true;
        else
            $return = false;

        return $return;
    }
}

BadgeOS_CPT_Rules_Engine::instance();
