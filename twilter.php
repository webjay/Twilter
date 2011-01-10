<?php
/**
 * A class that makes it easy to connect to and consume the Twitter stream via the Streaming API. Authentication is OAuth only.
 * Inspired by Phirehose (http://code.google.com/p/phirehose/)
 * 
 * Note: This is beta software - Please read the following carefully before using: 
 *  - http://dev.twitter.com/pages/streaming_api
 * 
 * This work is licensed under the Creative Commons Attribution 3.0 Unported License.
 * @license http://creativecommons.org/licenses/by/3.0/
 *
 * @author Jacob Friis Saxberg <jacob@saxberg.dk>
 * @version 1.0
 */

	class twilter {

		const STREAM_URL = 'https://stream.twitter.com/1/statuses/filter.json';

		private $consumer_key = null;
		private $consumer_secret = null;

		private $tcpSleep = 1;
		private $connectFailures = 0;
		protected $connectFailuresMax = 20; 
		const TCP_BACKOFF_MAX  = 16;

		const EARTH_RADIUS_KM  = 6371;

		private $options = array(
			'http' => array(
				'method' => 'POST',
				'follow_location' => 1,
				'max_redirects' => 5,
				'protocol_version' => 1.0,
				'timeout' => 30.0,
				'ignore_errors' => false,
				'user_agent' => 'Twilter 1.0'
			)
		);
		
		private $stream;
		private $parameters = array();
		
		/**
		 * @see http://twitter.com/oauth
		 * @param string $consumer_key Your applications consumer key provided by Twitter.
		 * @param string $consumer_secret Your applications consumer secret provided by Twitter.
		 */
		final public function __construct ($consumer_key, $consumer_secret) {
			$this->consumer_key = $consumer_key;
			$this->consumer_secret = $consumer_secret;
		}

		/**
		 * Overwrites the defaults for the stream.
		 * @see http://php.net/manual/en/function.stream-context-create.php
		 * @param array $options The options for the stream.
		 */
		final public function set_options ($options) {
			$this->options = array_merge_recursive($this->options, $options);
		}

		/**
		 * @see http://dev.twitter.com/pages/streaming_api_methods#query-parameters
		 * @param array $options The parameters for the Twitter statuses/filter.
		 */
		public function set_parameters ($array) {
			$this->parameters = $array;
		}

		final private function get_parameters () {
			parse_str(http_build_query($this->parameters), $oauth_url_parameters);
			return $oauth_url_parameters;
		}
		
		/**
		 * Connect to Twitter.
		 * @param string $access_token The users access token provided by Twitter.
		 * @param string $access_secret The users access secret provided by Twitter.
		 */
		final public function connect ($access_token, $access_secret) {
			$consumer = new OAuthConsumer($this->consumer_key, $this->consumer_secret);
			$token = new OAuthConsumer($access_token, $access_secret);
			$hmac_method = new OAuthSignatureMethod_HMAC_SHA1();

			$oauth_url_parameters = $this->get_parameters();
			$acc_req = OAuthRequest::from_consumer_and_token($consumer, $token, 'POST', $this->create_url(), $oauth_url_parameters);
			$acc_req->sign_request($hmac_method, $consumer, $token);
			
			$context = stream_context_create($this->options);

			$this->stream = fopen($acc_req->to_url(), 'r', false, $context);
			if ($this->stream === false) {
				// Some sort of socket error has occured
				$lastErrorNo = is_resource($this->stream) ? socket_last_error($this->stream) : NULL;
				$lastErrorMsg = ($lastErrorNo > 0) ? socket_strerror($lastErrorNo) : 'Socket disconnected';
				if (!$lastErrorNo) {
					$error = error_get_last();
					$lastErrorMsg = $error['message'];
				}
				$this->log($lastErrorMsg);
				if ($this->sleep()) {
					$this->connect($access_token, $access_secret);
				}
				return false;
			}
			stream_set_blocking($this->stream, 1);
			$this->log(print_r(stream_get_meta_data($this->stream), true), 2);
			
			$this->connectFailures = 0;
		}
		
		/**
		 * Start receiving the stream.
		 * Output is sent to output.
		 * @see twilter::output
		 */
		final public function stream_start () {
			while (!feof($this->stream)) { 
				$this->output(stream_get_line($this->stream, 65535));
			}
			fclose($this->stream);
		}

		/**
		 * Overwrite me
		 * @param string $str Output from Twitter.
		 */
		protected function output ($str) {
			echo($str);
		}
		
		final private function create_url () {
			$urlParts = parse_url(self::STREAM_URL);
			$url = $urlParts['scheme'].'://';
			$url .= $this->get_random_ip($urlParts['host']);
			$url .= $urlParts['path'];
			return $url;
		}
		
		final private function get_random_ip ($host) {
			$streamIPs = gethostbynamel($host);
			if (count($streamIPs) == 0) {
				throw new ErrorException('Unable to resolve hostname: '.$host);
			}
			$this->log('Resolved host '.$host.' to '.implode(', ', $streamIPs));
			// Choose one randomly (if more than one)
			$streamIP = $streamIPs[rand(0, (count($streamIPs) - 1))];
			$this->log('Connecting to '.$streamIP);
			return $streamIP;
		}

		private function sleep () {
			$this->connectFailures++;
			if ($this->connectFailures > $this->connectFailuresMax) {
				$msg = 'Connection failure limit exceeded with '.$connectFailures.' failures.';
				$this->log($msg);
				throw new ErrorException($msg);
				return false;
			}
			// Increase retry/backoff up to max
			$this->tcpSleep = ($this->tcpSleep < self::TCP_BACKOFF_MAX) ? $this->tcpSleep * 2 : self::TCP_BACKOFF_MAX;
			sleep($this->tcpSleep);
			return true;
		}
		
		/**
		 * Logging. Overwrite me
		 * @param string $errorMsg debug or error message
		 * @param integer $level debug level
		 */
		protected function log ($errorMsg, $level = 1) {
			switch ($level) {
				case 1:
					echo($errorMsg.PHP_EOL);
					break;
				case 2:
					break;
			}
		}
		
	}

?>