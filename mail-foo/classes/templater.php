<?php
namespace mail_foo;

if(!defined('WPINC'))
	exit('Do NOT access this file directly: '.basename(__FILE__));

class templater {

	private $plugin;

	/**
	 * Class constructor
	 */
	public function __construct() {
		$this->plugin = plugin();
	}

	/**
	 * Adds `$this->filter` to the `wp_mail` filter hook
	 */
	public function add_actions() {
		// We hook in at the last available hook so we can catch if other plugins already switch to the HTML content type
		add_filter('wp_mail', array($this, 'filter'), 1, PHP_INT_MAX);
	}

	/**
	 * `wp_mail` Filter. Templates text/plain emails and adds the tex/html Content Type
	 *
	 * @param $args
	 *
	 * @return array
	 */
	public function filter($args) {
		$new_args = array(
			'to'          => $args['to'],
			'subject'     => $args['subject'],
			'attachments' => $args['attachments']
		);

		$do_html        = TRUE;
		$headers_parsed = array();
		$headers        = array();

		if(is_array($args['headers'])) $args['headers'] = implode("\r\n", $args['headers']);

		// Check headers for a preset Content Type
		if(is_string($args['headers']) && !empty($args['headers'])) {
			$headers = $this->parse_headers($args['headers']);

			foreach($headers as $i => $h)
				if(stripos($i, 'Content-Type') === 0 && stripos(trim($h), 'text/plain') !== 0)
					$do_html = FALSE;

			// Check for filters on `wp_mail_content_type` as well
			if('text/plain' !== apply_filters('wp_mail_content_type', 'text/plain')) $do_html = FALSE;
		}

		// TODO research multipart emails
		// http://krijnhoetmer.nl/stuff/php/html-plain-text-mail/

		// If set to text/plain, we need to set the Content-Type to text/html
		if($do_html) {
			foreach($headers as $i => $h) {
				if(is_array($h))
					foreach($h as $he)
						$headers_parsed[] = $i.': '.trim($he);
				else
					$headers_parsed[] = $i.': '.trim($h);
			}

			$headers_parsed[] = 'Content-Type: text/html; charset="'.apply_filters('wp_mail_charset', get_bloginfo('charset')).'"'; // Set text/html header

			$new_args['headers'] = $headers_parsed;
		}
		else $new_args['headers'] = $args['headers']; // Otherwise, we use the old headers

		// Wrap template if text/plain
		if($do_html) {
			$opts     = $this->plugin->opts();
			$template = file_get_contents($this->plugin->tmlt_dir.'/'.$opts['template']);

			if(!$opts['parse_markdown']) $args['message'] = wpautop($args['message']);
			$message = str_replace('%%content%%', wpautop($args['message']), $template);

			$vars = array(
				'site_url'         => site_url(),
				'site_name'        => get_bloginfo('name'),
				'site_description' => get_bloginfo('description'),
				'admin_email'      => get_bloginfo('admin_email'),

			);

			if($opts['parse_shortcodes']) $message = do_shortcode($message);
			if($opts['parse_markdown']) $message = $this->do_markdown($message);
			if($opts['exec_php']) $message = $this->exec_php($message);

			$new_args['message'] = trim($message);
		}
		else $new_args['message'] = $args['message']; // Else do nothing

		return $new_args;
	}

	private function parse_headers($headers) {
		if(function_exists('http_parse_headers')) return http_parse_headers($headers);

		$func = function ($raw) { // Adopted from @url{http://php.net/manual/en/function.http-parse-headers.php#112986}
			$headers = array();
			$key     = '';

			foreach(explode("\n", $raw) as $i => $h) {
				$h = explode(':', $h, 2);

				if(isset($h[1])) {
					if(!isset($headers[$h[0]]))
						$headers[$h[0]] = trim($h[1]);
					elseif(is_array($headers[$h[0]]))
						$headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1])));
					else
						$headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1])));

					$key = $h[0];
				}
				else {
					if(substr($h[0], 0, 1) == "\t")
						$headers[$key] .= "\r\n\t".trim($h[0]);
					elseif(!$key)
						$headers[0] = trim($h[0]);
					trim($h[0]);
				}
			}

			return $headers;
		};

		return $func($headers);
	}

	private function do_markdown($str) {
		if(!class_exists('\\Michelf\\Markdown')) require($this->plugin->dir.'/md/Markdown.inc.php');

		$md = apply_filters(__NAMESPACE__.'_markdown_function', array('\\Michelf\\Markdown', 'defaultTransform'));
		return call_user_func($md, $str);
	}

	private function exec_php($code, $vars = array()) {
		if(!function_exists('eval')) return $code;

		if(is_array($vars) && !empty($vars))
			extract($vars, EXTR_PREFIX_SAME, '_extract_');

		ob_start(); // Output buffer.
		$ev = eval ("?>".trim($code));

		if($ev !== FALSE) return ob_get_clean();
		return $code;
	}
}