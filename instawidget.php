<?php

/**
 * @package Instawidget
 * @version 1.6
 */
/*
Plugin Name: Instawidget
Plugin URI: http://wordpress.org/plugins/instawidget
Description: Instagram shit
Author: Michael G. Jensen <michaeljensendk(at)hotmail.com>
Version: 1.0
Author URI: http://mjay.me
*/


# Hack the memcache into php
$GLOBALS['memcached-sets'] = array (
    '_' => array (
            array('localhost', 11211)
    )
);

define('DEFAULT_MEMCACHED_SET', '_');

function mcache( $persistent_id=DEFAULT_MEMCACHED_SET ) {

        // one instantiation per-connection per-request
        static $memcached_instances = array();

        if( array_key_exists($persistent_id, $memcached_instances)) {
            $instance = $memcached_instances[$persistent_id];
        }else{
            $instance = new Memcached($persistent_id);
            $instance->setOption(Memcached::OPT_PREFIX_KEY, $persistent_id);
            $instance->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true); // advisable option

            // Add servers if no connections listed. Get server set by $persistent_id or use default set.
            // In a production environment with multiple server sets you may wish to prevent typos from silently adding data 
            // to the default pool, in which case return an error on no match instead of defaulting
            if( !count($instance->getServerList()) ) {
                $servers = array_key_exists($persistent_id, $GLOBALS['memcached-sets'])
                    ? $GLOBALS['memcached-sets'][$persistent_id]
                    : $GLOBALS['memcached-sets'][DEFAULT_MEMCACHED_SET];
                $instance->addServers($servers);
            }

            $memcached_instances[$persistent_id] = $instance;
        }

    return $instance;
}


/**
 * Adds Foo_Widget widget.
 */
class Foo_Widget extends WP_Widget {

	/**
	 * Register widget with WordPress.
	 */
	function __construct() {
		parent::__construct(
			'foo_widget', // Base ID
			__( 'Instawidget', 'text_domain' ), // Name
			array( 'description' => __( 'A widget showing images from instagram', 'text_domain' ), ) // Args
		);
	}

	function fetchData($url, $fields){


		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);

		if (count($fields) > 0) {
			//url-ify the data for the POST
			foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
			rtrim($fields_string, '&');

			curl_setopt($ch, CURLOPT_POST, count($fields));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
		}

		$result = curl_exec($ch);
		curl_close($ch);

		return $result;
	}

	// Instagram-secure-requests.
	function generate_sig($endpoint, $params, $secret) {
		$sig = $endpoint;
		ksort($params);
		foreach ($params as $key => $val) {
			$sig .= "|$key=$val";
		}
		return hash_hmac('sha256', $sig, $secret, false);
	}


	function fetchRecentMedia($tag, $token, $secret) {

		# our cache-key is the full url.
		$endpoint = sprintf("https://api.instagram.com/v1/tags/%s/media/recent?access_token=%s", $tag, $token);

		$data = mcache()->get($endpoint);

		# empty cache, create the stuff by fetching it from instagram.
		if (!is_object($data) ||  $data->cache_updated_required) {

			$params = array( );
			#$params['sig'] = $this->generate_sig($endpoint, $params, $secret);
			$data = json_decode($this->fetchData($endpoint , $params));

			# Necessary fields, to manage cache
			$tmp = array();
			for ($i = 0 ; $i < 60; $i++) {
				$tmp[$i] = 0;
			}
			$data->cache_accessed = $tmp;

		} else {

			$slot = time() % 60; # Create a cache-window of 60 seconds.

			$accessed = $data->cache_accessed[$slot];

			$data->cache_accessed[$slot] = ++ $accessed;

			# Only, when a single slot has more than 10 hits, will we fetch new.
			if ($data->cache_accessed[$slot] > 10) {
				$data->cache_updated_required = true;
			}
		}

		# Move cache-window ahead. If more than a hour has passed, it will have expired.
		while(  FALSE === (mcache()->set($endpoint, $data, 3600))  );

		return $data;
			
	}


	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		wp_enqueue_style( 'mywidget_style', plugins_url('mystyle.css', __FILE__) );

		echo $args['before_widget'];

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}

		$obj =  $this->fetchRecentMedia($instance['tag'],$instance['token'], $instance['secret']) ;

		$elements = $instance['elements'];
		if (empty($elements)) {
			$elements = 10;
		}

		foreach( $obj->data as $row) {
			if ($elements-- <= 0) break;
			print '<div class="image">';

			$matches = array();

			if( 0 == preg_match("/(\d)+.?kr/", $row->caption->text, $matches)) {
				printf('<a href="%s"><image src="%s"><h2><span># %s</span></h2></a>', $row->link, $row->images->low_resolution->url, $row->tags[rand(0,count($row->tags)-1)]);
			} else {
				printf('<a href="%s"><image src="%s"><h2><span>Sælges: %s</span></h2></a>', $row->link, $row->images->low_resolution->url, $matches[0]);
			}

			print '</div>';
		}


	        echo __( esc_attr('API Service courtesy of Instagram 2016'), 'text_domain' );

		echo $args['after_widget'];
	}

	/**
	 * Back-end widget form.
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'New title', 'text_domain' );
		$token = ! empty( $instance['token'] ) ? $instance['token'] : __( 'The client token', 'text_domain' );
		$secret = ! empty( $instance['secret'] ) ? $instance['secret'] : __( 'The client secret', 'text_domain' );
		$tag = ! empty( $instance['tag'] ) ? $instance['tag'] : __( 'The client tag', 'text_domain' );
		$elements = ! empty( $instance['elements'] ) ? $instance['elements'] : __( 'The number of elements to show', 'text_domain' );
		?>
		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( esc_attr( 'Title:' ) ); ?></label> 
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>

		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'token' ) ); ?>"><?php _e( esc_attr( 'Token:' ) ); ?></label> 
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'token' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'token' ) ); ?>" type="text" value="<?php echo esc_attr( $token ); ?>">
		</p>

		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'secret' ) ); ?>"><?php _e( esc_attr( 'Secret:' ) ); ?></label> 
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'secret' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'secret' ) ); ?>" type="text" value="<?php echo esc_attr( $secret ); ?>">
		</p>

		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'tag' ) ); ?>"><?php _e( esc_attr( 'Tag:' ) ); ?></label> 
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'tag' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'tag' ) ); ?>" type="text" value="<?php echo esc_attr( $tag ); ?>">
		</p>

		<p>
		<label for="<?php echo esc_attr( $this->get_field_id( 'elements' ) ); ?>"><?php _e( esc_attr( 'Num elements:' ) ); ?></label> 
		<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'elements' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'elements' ) ); ?>" type="number" min="1" max="10" value="<?php echo esc_attr( $elements ); ?>">
		</p>

		<?php 
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
		$instance['token']=(! empty( $new_instance['token'] ) ) ? strip_tags( $new_instance['token'] ) : '';
		$instance['secret']=(! empty( $new_instance['secret'] ) ) ? strip_tags( $new_instance['secret'] ) : '';
		$instance['tag']=(! empty( $new_instance['tag'] ) ) ? strip_tags( $new_instance['tag'] ) : '';
		$instance['elements']=(! empty( $new_instance['elements'] ) ) ? strip_tags( $new_instance['elements'] ) : '';

		return $instance;
	}

} // class Foo_Widget

// register Foo_Widget widget
function register_foo_widget() {
    register_widget( 'Foo_Widget' );
}
add_action( 'widgets_init', 'register_foo_widget' );
