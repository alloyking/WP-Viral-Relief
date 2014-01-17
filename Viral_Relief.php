<?php 
/**
 * Plugin Name: Viral Relief
 * Version: 1.0
 * Author: Tim Shultis
 * License: GPL or something.  Just use it for free, I don't care.
 */

require 'vendor/autoload.php';

use Aws\Common\Aws;
use Aws\S3\Exception\S3Exception;

$viral = new Viral_Relief();


class Viral_Relief {

	const AWS_KEY = "YOURKEY";
	const AWS_SECRET = "YOURSECRET";
	const AWS_BUCKET = 'YOURBUCKET';

	const IS_POST_VIRAL_KEY = 'is_viral';
	const POST_VIRAL_S3_FILENAME = 'viral_file';
	const POST_VIRAL_S3_URL = 'viral_url';

	private $viral_post_id;

	public function __construct() { 
		//add compare button to wp-admin publish block
		add_action( 'post_submitbox_misc_actions', array($this, 'add_html_viral_button' ) );
		add_action('pre_post_update', array($this, 'prePostUpdate'));
		add_action('admin_notices', array( $this, 'viral_exists_warning' ) );
	}

	public function is_post_viral($id=null){
		if($id == null){
			global $post_id;
			$check_viral = get_post_meta( $post_id, self::IS_POST_VIRAL_KEY, true );
		} else {
			$check_viral = get_post_meta( $id, self::IS_POST_VIRAL_KEY, true );
		}
		
		if($check_viral === "true"){
			$is_viral = true;
		} else{
			$is_viral = null;
		}
		return $is_viral; 
	}

	public function viral_exists_warning(){
		global $post;
		if($this->is_post_viral()){
				echo '<div id="message" class="updated below-h2" style="margin:0px;margin-top:5px;"><p>This viral post should be added to your .htaccess rules | RewriteRule ^'.$post->post_name.'? http://s3.amazonaws.com/'.self::AWS_BUCKET.'/'.$post->post_name.' [P]</p></div>';
		}
	}

	public function add_html_viral_button(){

		if(!$this->is_post_viral()){
			?>
			<div class="misc-pub-section my-options">
				<input type="submit" class="button button-highlighted" tabindex="4" value="viral" id="make-viral" name="viral">
			</div>
			<?php
		} else {
			?>
			<div class="misc-pub-section my-options">
				<input type="submit" class="button button-highlighted" tabindex="4" value="remove viral" id="make-viral" name="viral">
			</div>
			<div class="misc-pub-section my-options">
				<input type="submit" class="button button-highlighted" tabindex="4" value="purge viral" id="make-viral" name="viral">
			</div>
			<?php
		}
	}

	public function prePostUpdate ($id) {

			// Check if this is an auto save routine. If it is we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
			return $id;

			// Check permissions
		if (!current_user_can('edit_' . ($_POST['post_type'] == 'posts' ? 'posts' : 'page'), $id )) {
			return $id;
		}

		if ($_REQUEST['viral'] == 'viral') {
			$this->viral_post_id = $id;
			$file = $this->get_page_html(get_permalink($id));
			$this->save_to_s3($file);
		}else if($_REQUEST['viral'] == 'remove viral'){
			$this->remove_from_s3();
		}else if($_REQUEST['viral'] == 'purge viral'){
			$this->viral_post_id = $id;
			$file = $this->get_page_html(get_permalink($id));
			$this->save_to_s3($file);
		} else {
			return $id;
		}

	}

	private function get_page_html($url){
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $url
			));

		$response = curl_exec($curl);

		curl_close($curl);

		return $response;
	}

	private function save_to_s3($file){
		global $post;
		// Instantiate an S3 client
		$aws = Aws::factory(array(
			'key'    => self::AWS_KEY,
			'secret' => self::AWS_SECRET
			));
		
		$s3 = $aws->get('s3');

		try {
			$result = $s3->putObject(array(
				'Bucket' => self::AWS_BUCKET,
				'Key'    => $post->post_name,
				'Body'   => $file,
				'ContentType' => 'text/html',
				'ACL'    => 'public-read',
				));

		  //strip off https because we dont want to use that.
			$url =  $result['ObjectURL'];
			$url = str_replace('https://', 'http://', $url ); 

		  //save url and file key to post_meta
			$this->set_s3_post_meta($url, 'test');

		} catch (S3Exception $e) {
			echo "There was an error uploading the file.\n";
		}
	}

	private function remove_from_s3(){
		global $post;
		$aws = Aws::factory(array(
			'key'    => self::AWS_KEY,
			'secret' => self::AWS_SECRET
			));
		
		$s3 = $aws->get('s3');

		try {
			$result = $s3->deleteObject(array(
				'Bucket' => self::AWS_BUCKET,
				'Key'    => $post->post_name,
				'Body'   => $file,
				'ContentType' => 'text/html',
				'ACL'    => 'public-read',
				));
			$this->remove_s3_viral_meta();
		} catch (S3Exception $e) {
			echo "There was an error uploading the file.\n";
		}
	}

	private function set_s3_post_meta($s3_url, $key){
		update_post_meta($this->viral_post_id, self::POST_VIRAL_S3_URL, $s3_url);
		update_post_meta($this->viral_post_id, self::POST_VIRAL_S3_FILENAME, $key);
		update_post_meta($this->viral_post_id, self::IS_POST_VIRAL_KEY, 'true');

		return $this;
	}

	private function remove_s3_viral_meta($id=null){
		if($id == null){
			global $post_id;
			update_post_meta($post_id, self::IS_POST_VIRAL_KEY, 'false');	
		} else {
			update_post_meta($id, self::IS_POST_VIRAL_KEY, 'false');	
		}
		return $this;
	}

	private function get_wp_config_path()
	{
		$base = dirname(__FILE__);
		$path = false;

		if (@file_exists(dirname(dirname($base))."/wp-config.php"))
		{
			$path = dirname(dirname($base));
		}
		else
			if (@file_exists(dirname(dirname(dirname($base)))."/wp-config.php"))
			{
				$path = dirname(dirname(dirname($base)));
			}
			else
				$path = false;

			if ($path != false)
			{
				$path = str_replace("\\", "/", $path);
			}
			return $path;
		}
	}