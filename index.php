<?php

/*
  Plugin Name: Older/Newer Posts Widget
  Version: 1.0.1
  Plugin URI: http://wpclever.net
  Description:  Get and show older/newer posts of the current post.
  Author: WPclever
  License: GPL2
 */

if ( ! function_exists( "onpw_pst_get_adjacent_posts" ) ) {
	function onpw_pst_get_adjacent_posts( $post_id = null, $limit = 1, $previous = true, $in_same_term = false, $excluded_terms = '', $taxonomy = 'category' ) {
		global $wpdb;

		if ( ( ! $post = get_post( $post_id ) ) || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$current_post_date = $post->post_date;

		$join                  = '';
		$posts_in_ex_terms_sql = '';
		if ( $in_same_term || ! empty( $excluded_terms ) ) {
			$join = " INNER JOIN $wpdb->term_relationships AS tr ON p.ID = tr.object_id INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";

			if ( $in_same_term ) {
				if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
					return '';
				}
				$term_array = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! $term_array || is_wp_error( $term_array ) ) {
					return '';
				}
				$join .= $wpdb->prepare( " AND tt.taxonomy = %s AND tt.term_id IN (" . implode( ',', array_map( 'intval', $term_array ) ) . ")", $taxonomy );
			}

			$posts_in_ex_terms_sql = $wpdb->prepare( "AND tt.taxonomy = %s", $taxonomy );
			if ( ! empty( $excluded_terms ) ) {
				if ( ! is_array( $excluded_terms ) ) {
					// back-compat, $excluded_terms used to be $excluded_terms with IDs separated by " and "
					if ( false !== strpos( $excluded_terms, ' and ' ) ) {
						_deprecated_argument( __FUNCTION__, '3.3', sprintf( __( 'Use commas instead of %s to separate excluded terms.' ), "'and'" ) );
						$excluded_terms = explode( ' and ', $excluded_terms );
					} else {
						$excluded_terms = explode( ',', $excluded_terms );
					}
				}

				$excluded_terms = array_map( 'intval', $excluded_terms );

				if ( ! empty( $term_array ) ) {
					$excluded_terms        = array_diff( $excluded_terms, $term_array );
					$posts_in_ex_terms_sql = '';
				}

				if ( ! empty( $excluded_terms ) ) {
					$posts_in_ex_terms_sql = $wpdb->prepare( " AND tt.taxonomy = %s AND tt.term_id NOT IN (" . implode( $excluded_terms, ',' ) . ')', $taxonomy );
				}
			}
		}

		$adjacent = $previous ? 'previous' : 'next';
		$op       = $previous ? '<' : '>';
		$order    = $previous ? 'DESC' : 'ASC';

		/**
		 * Filter the JOIN clause in the SQL for an adjacent post query.
		 *
		 * The dynamic portion of the hook name, $adjacent, refers to the type
		 * of adjacency, 'next' or 'previous'.
		 *
		 * @since 2.5.0
		 *
		 * @param string $join The JOIN clause in the SQL.
		 * @param bool $in_same_term Whether post should be in a same taxonomy term.
		 * @param array $excluded_terms Array of excluded term IDs.
		 */
		$join = apply_filters( "get_{$adjacent}_post_join", $join, $in_same_term, $excluded_terms );

		/**
		 * Filter the WHERE clause in the SQL for an adjacent post query.
		 *
		 * The dynamic portion of the hook name, $adjacent, refers to the type
		 * of adjacency, 'next' or 'previous'.
		 *
		 * @since 2.5.0
		 *
		 * @param string $where The WHERE clause in the SQL.
		 * @param bool $in_same_term Whether post should be in a same taxonomy term.
		 * @param array $excluded_terms Array of excluded term IDs.
		 */
		$where = apply_filters( "get_{$adjacent}_post_where", $wpdb->prepare( "WHERE p.post_date $op %s AND p.post_type = %s AND p.post_status = 'publish' $posts_in_ex_terms_sql", $current_post_date, $post->post_type ), $in_same_term, $excluded_terms );

		/**
		 * Filter the ORDER BY clause in the SQL for an adjacent post query.
		 *
		 * The dynamic portion of the hook name, $adjacent, refers to the type
		 * of adjacency, 'next' or 'previous'.
		 *
		 * @since 2.5.0
		 *
		 * @param string $order_by The ORDER BY clause in the SQL.
		 */
		$sort = apply_filters( "get_{$adjacent}_post_sort", "GROUP BY p.ID ORDER BY p.post_date $order LIMIT $limit" );

		$query = "SELECT p.ID FROM $wpdb->posts AS p $join $where $sort";

		$query_key = 'adjacent_post_' . md5( $query );
		$result    = wp_cache_get( $query_key, 'counts' );
		if ( false !== $result ) {
			if ( $result ) {
				$result = array_map( 'get_post', $result );
			}

			return $result;
		}

		$result = $wpdb->get_col( $query );
		if ( null === $result ) {
			$result = '';
		}

		wp_cache_set( $query_key, $result, 'counts' );

		if ( $result ) {
			$result = array_map( 'get_post', $result );
		}

		return $result;
	}
}

function onpw_cut_string( $str, $length, $char = "..." ) {
	$strlen = mb_strlen( $str, "UTF-8" );
	if ( $strlen <= $length ) {
		return $str;
	}
	$substr = mb_substr( $str, 0, $length, "UTF-8" );
	if ( mb_substr( $str, $length, 1, "UTF-8" ) == " " ) {
		return $substr . $char;
	}
	$strPoint = mb_strrpos( $substr, " ", "UTF-8" );
	if ( $strPoint < $length - 20 ) {
		return $substr . $char;
	} else {
		return mb_substr( $substr, 0, $strPoint, "UTF-8" ) . $char;
	}
}

class onpw_Older_Newer_Posts extends WP_Widget {

	function onpw_Older_Newer_Posts() {
		parent::__construct( false, 'Older/Newer Posts' );
	}

	function widget( $args, $instance ) {
		$onpw_title = apply_filters( 'widget_title', $instance['onpw_title'] );
		$onpw_num   = $instance['onpw_num'];
		if ( $instance['onpw_type'] == '1' ) {
			$onpw_type = true;
		} else {
			$onpw_type = false;
		}
		$onpw_thumb = $instance['onpw_thumb'];
		if ( $instance['onpw_taxonomy'] != '' ) {
			$onpw_insameterm = true;
			$onpw_taxonomy   = $instance['onpw_taxonomy'];
		} else {
			$onpw_insameterm = false;
			$onpw_taxonomy   = 'category';
		}
		$onpw_excerpt        = $instance['onpw_excerpt'];
		$onpw_excerpt_length = $instance['onpw_excerpt_length'];
		$onpw_excerpt_end    = $instance['onpw_excerpt_end'];
		$onpw_date           = $instance['onpw_date'];
		$onpw_date_format    = $instance['onpw_date_format'];
		$onpw_author         = $instance['onpw_author'];
		if ( is_single() ) {
			$onpw_posts = onpw_pst_get_adjacent_posts( $post_id = get_the_ID(), $limit = $onpw_num, $previous = $onpw_type, $in_same_term = $onpw_insameterm, $excluded_terms = '', $taxonomy = $onpw_taxonomy );
			if ( count( $onpw_posts ) > 0 ) {
				echo $args['before_widget'];
				if ( ! empty( $onpw_title ) ) {
					echo $args['before_title'] . $onpw_title . $args['after_title'];
				} else {
					echo $args['before_title'] . '&nbsp;' . $args['after_title'];
				}
				echo '<ul class="onpw-list">';
				foreach ( $onpw_posts as $onpw_post ) {
					echo '<li class="onpw-item">';
					if ( ( $onpw_thumb == 'on' ) && has_post_thumbnail( $onpw_post->ID ) ) {
						echo '<div class="onpw-thumb"><a href="' . get_permalink( $onpw_post->ID ) . '">' . get_the_post_thumbnail( $onpw_post->ID, "thumbnail" ) . '</a></div>';
					}
					echo '<div class="onpw-content">';
					echo '<div class="onpw-title"><a href="' . get_permalink( $onpw_post->ID ) . '">' . esc_html( $onpw_post->post_title ) . '</a></div>';
					if ( $onpw_excerpt == 'on' ) {
						if ( isset( $onpw_post->post_excerpt ) && ( $onpw_post->post_excerpt != '' ) ) {
							$onpw_excerpt_text = $onpw_post->post_excerpt;
						} else {
							$onpw_excerpt_text = $onpw_post->post_content;
						}
						echo '<div class="onpw-excerpt">';
						echo onpw_cut_string( $onpw_excerpt_text, $onpw_excerpt_length, $onpw_excerpt_end );
						echo '</div>';
					}
					if ( ( $onpw_date == 'on' ) || ( $onpw_author == 'on' ) ) {
						echo '<div class="onpw-meta">';
						if ( $onpw_date == 'on' ) {
							echo '<div class="onpw-time">' . get_post_time( $onpw_date_format, true, $onpw_post->ID ) . '</div>';
						}
						if ( $onpw_author == 'on' ) {
							$onpw_author_id = $onpw_post->post_author;
							echo '<div class="onpw-author"><a href="' . get_author_posts_url( $onpw_author_id ) . '">' . get_the_author_meta( 'display_name', $onpw_author_id ) . '</a></div>';
						}
						echo '</div>';
					}
					echo '</div>';
					echo '</li>';
				}
				echo '</ul>';
				echo $args['after_widget'];
			}
		}
	}

	function update( $new_instance, $old_instance ) {
		$instance                        = array();
		$instance['onpw_title']          = ( ! empty( $new_instance['onpw_title'] ) ) ? esc_html( $new_instance['onpw_title'] ) : __( 'Older/Newer Posts', 'onpw' );
		$instance['onpw_num']            = ( ! empty( $new_instance['onpw_num'] ) ) ? absint( $new_instance['onpw_num'] ) : '5';
		$instance['onpw_type']           = ( ! empty( $new_instance['onpw_type'] ) ) ? strip_tags( $new_instance['onpw_type'] ) : '';
		$instance['onpw_taxonomy']       = ( ! empty( $new_instance['onpw_taxonomy'] ) ) ? strip_tags( $new_instance['onpw_taxonomy'] ) : '';
		$instance['onpw_thumb']          = ( ! empty( $new_instance['onpw_thumb'] ) ) ? strip_tags( $new_instance['onpw_thumb'] ) : '';
		$instance['onpw_excerpt']        = ( ! empty( $new_instance['onpw_excerpt'] ) ) ? strip_tags( $new_instance['onpw_excerpt'] ) : '';
		$instance['onpw_excerpt_length'] = ( ! empty( $new_instance['onpw_excerpt_length'] ) ) ? absint( $new_instance['onpw_excerpt_length'] ) : '120';
		$instance['onpw_excerpt_end']    = ( ! empty( $new_instance['onpw_excerpt_end'] ) ) ? strip_tags( $new_instance['onpw_excerpt_end'] ) : '[...]';
		$instance['onpw_date']           = ( ! empty( $new_instance['onpw_date'] ) ) ? strip_tags( $new_instance['onpw_date'] ) : '';
		$instance['onpw_date_format']    = ( ! empty( $new_instance['onpw_date_format'] ) ) ? strip_tags( $new_instance['onpw_date_format'] ) : 'd/m/Y';
		$instance['onpw_author']         = ( ! empty( $new_instance['onpw_author'] ) ) ? strip_tags( $new_instance['onpw_author'] ) : '';

		return $instance;
	}

	function form( $instance ) {
		$onpw_title          = ( ! empty( $instance['onpw_title'] ) ) ? $instance['onpw_title'] : __( 'Older/Newer Posts', 'onpw' );
		$onpw_num            = ( ! empty( $instance['onpw_num'] ) ) ? $instance['onpw_num'] : '5';
		$onpw_type           = ( ! empty( $instance['onpw_type'] ) ) ? $instance['onpw_type'] : '';
		$onpw_thumb          = ( ! empty( $instance['onpw_thumb'] ) ) ? $instance['onpw_thumb'] : '';
		$onpw_taxonomy       = ( ! empty( $instance['onpw_taxonomy'] ) ) ? $instance['onpw_taxonomy'] : '';
		$onpw_excerpt        = ( ! empty( $instance['onpw_excerpt'] ) ) ? $instance['onpw_excerpt'] : '';
		$onpw_excerpt_length = ( ! empty( $instance['onpw_excerpt_length'] ) ) ? $instance['onpw_excerpt_length'] : '120';
		$onpw_excerpt_end    = ( ! empty( $instance['onpw_excerpt_end'] ) ) ? $instance['onpw_excerpt_end'] : '[...]';
		$onpw_date           = ( ! empty( $instance['onpw_date'] ) ) ? $instance['onpw_date'] : '';
		$onpw_date_format    = ( ! empty( $instance['onpw_date_format'] ) ) ? $instance['onpw_date_format'] : 'd/m/Y';
		$onpw_author         = ( ! empty( $instance['onpw_author'] ) ) ? $instance['onpw_author'] : '';
		?>
		<p>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_title' ) ); ?>"><?php esc_html_e( 'Title:', 'onpw' ); ?></label>
			<input class="widefat" id="<?php echo esc_html( $this->get_field_id( 'onpw_title' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_title' ) ); ?>" type="text"
			       value="<?php echo esc_html( $onpw_title ); ?>"/>
		</p>
		<p>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_num' ) ); ?>"><?php esc_html_e( 'Number of posts to show:', 'onpw' ); ?></label>
			<select name="<?php echo esc_html( $this->get_field_name( 'onpw_num' ) ); ?>">
				<?php
				for ( $i = 1; $i <= 40; $i ++ ) {
					$sl = '';
					if ( $i == $onpw_num ) {
						$sl = 'selected';
					}
					echo '<option value="' . $i . '" ' . $sl . '>' . $i . '</option>';
				}
				?>
			</select>
		</p>
		<p>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_type' ) ); ?>"><?php esc_html_e( 'Type of posts:', 'onpw' ); ?></label>
			<select name="<?php echo esc_html( $this->get_field_name( 'onpw_type' ) ); ?>">
				<option value="0" <?php if ( $onpw_type == '0' ) {
					echo 'selected';
				} ?>>Newer
				</option>
				<option value="1" <?php if ( $onpw_type == '1' ) {
					echo 'selected';
				} ?>>Older
				</option>
			</select>
		</p>
		<p>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_taxonomy' ) ); ?>"><?php esc_html_e( 'Only get posts in same:', 'onpw' ); ?></label>
			<select name="<?php echo esc_html( $this->get_field_name( 'onpw_taxonomy' ) ); ?>">
				<option value="">None</option>
				<?php
				$args       = array(
					'public' => true
				);
				$taxonomies = get_taxonomies( $args, 'objects' );
				foreach ( $taxonomies as $taxonomy ) {
					echo '<option value="' . $taxonomy->name . '" ' . ( ( $taxonomy->name == $onpw_taxonomy ) ? "selected" : "" ) . '>' . $taxonomy->labels->name . '</option>';
				}
				?>
			</select>
		</p>
		<p>
			<input class="checkbox" type="checkbox" <?php checked( $onpw_thumb, 'on' ); ?>
			       id="<?php echo esc_html( $this->get_field_id( 'onpw_thumb' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_thumb' ) ); ?>"/>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_thumb' ) ); ?>"><?php esc_html_e( 'Show thumbnail?', 'onpw' ); ?></label>
		</p>
		<p>
			<input class="checkbox" type="checkbox" <?php checked( $onpw_excerpt, 'on' ); ?>
			       id="<?php echo esc_html( $this->get_field_id( 'onpw_excerpt' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_excerpt' ) ); ?>"/>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_excerpt' ) ); ?>"><?php esc_html_e( 'Show excerpt?', 'onpw' ); ?></label>
		</p>
		<p>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_excerpt_length' ) ); ?>"><?php esc_html_e( 'Excerpt length:', 'onpw' ); ?></label>
			<input class="" id="<?php echo esc_html( $this->get_field_id( 'onpw_excerpt_length' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_excerpt_length' ) ); ?>" type="text"
			       value="<?php echo esc_html( $onpw_excerpt_length ); ?>"/>
		</p>
		<p>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_excerpt_end' ) ); ?>"><?php esc_html_e( 'Signs after excerpt:', 'onpw' ); ?></label>
			<input class="" id="<?php echo esc_html( $this->get_field_id( 'onpw_excerpt_end' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_excerpt_end' ) ); ?>" type="text"
			       value="<?php echo esc_html( $onpw_excerpt_end ); ?>"/>
		</p>
		<p>
			<input class="checkbox" type="checkbox" <?php checked( $onpw_date, 'on' ); ?>
			       id="<?php echo esc_html( $this->get_field_id( 'onpw_date' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_date' ) ); ?>"/>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_date' ) ); ?>"><?php esc_html_e( 'Show date/time?', 'onpw' ); ?></label>
		</p>
		<p>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_date_format' ) ); ?>"><?php esc_html_e( 'Date/time format:', 'onpw' ); ?></label>
			<input class="" id="<?php echo esc_html( $this->get_field_id( 'onpw_date_format' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_date_format' ) ); ?>" type="text"
			       value="<?php echo esc_html( $onpw_date_format ); ?>"/>
		</p>
		<p>
			<input class="checkbox" type="checkbox" <?php checked( $onpw_author, 'on' ); ?>
			       id="<?php echo esc_html( $this->get_field_id( 'onpw_author' ) ); ?>"
			       name="<?php echo esc_html( $this->get_field_name( 'onpw_author' ) ); ?>"/>
			<label
				for="<?php echo esc_html( $this->get_field_id( 'onpw_author' ) ); ?>"><?php esc_html_e( 'Show author?', 'onpw' ); ?></label>
		</p>
		<?php
	}

}

function onpw_widgets_init() {
	register_widget( 'onpw_Older_Newer_Posts' );
}

add_action( 'widgets_init', 'onpw_widgets_init' );


function onpw_enqueue_scripts( $hook ) {
	wp_enqueue_style( 'onpw', plugin_dir_url( __FILE__ ) . 'css/onpw.css' );
}

add_action( 'wp_enqueue_scripts', 'onpw_enqueue_scripts' );
?>