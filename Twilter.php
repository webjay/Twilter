<?php
/**
 * Twilter
 * A PHP5+ class that makes it easy to connect to and consume the Twitter stream via the Streaming API.
 * Authentication is done via OAuth.
 *
 * Requires oauth (http://code.google.com/p/oauth/)
 * Inspired by Phirehose (http://code.google.com/p/phirehose/)
 * 
 * Note: This is beta software - Please read the following carefully before using: 
 * http://dev.twitter.com/pages/streaming_api
 * 
 * @copyright This work is licensed under the Creative Commons Attribution 3.0 Unported License.
 * @license http://creativecommons.org/licenses/by/3.0/
 * @author Jacob @webjay Friis Saxberg <jacob@saxberg.dk>
 *
 * Git: https://github.com/webjay/Twilter
 * 
 * @todo Handle stream errors after connection.
 * @todo Use PCNTL for multiple connections.
 *
 */

	abstract class Twilter {

		const STREAM_URL = 'https://stream.twitter.com/1/statuses/filter.json';
		
		public $access_token;
		public $access_secret;

		private $consumer_key = null;
		private $consumer_secret = null;

		private $tcpSleep = 1;
		private $connectFailures = 0;
		protected $connectFailuresMax = 20; 
		const TCP_BACKOFF_MAX = 16;
		
		public $refresh = false;
		const REFRESH_INTERVAL = 10;
		private $refresh_checked;
		private $connect = true;

		const EARTH_RADIUS_KM = 6371;
		private $locationBoxes = array();
		private $track = array();
		public $count = 0;

		private $options = array(
			'http' => array(
				'method' => 'POST',
				'follow_location' => 1,
				'max_redirects' => 5,
				'protocol_version' => 1.0,
				'timeout' => 5.0,
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
		 * @see http://dev.twitter.com/pages/streaming_api_methods#track
		 * @param array $a Specifies keywords to track.
		 */
		public function set_track ($a) {
			$this->track = $a;
		}

		final private function get_parameters () {
			if (!empty($this->track)) {
				$this->parameters['track'] = implode(',', $this->track);
			}
			if (!empty($this->locationBoxes)) {
				$this->parameters['locations'] = implode(',', $this->locationBoxes);
			}
			//$this->parameters['count'] = $this->count;
			return $this->parameters;
		}
		
		/**
		 * Connect to Twitter.
		 * @param string $access_token The users access token provided by Twitter.
		 * @param string $access_secret The users access secret provided by Twitter.
		 * @return bool true if connected, false otherwise
		 */
		final public function connect () {
			
			$this->refresh = false;
			
			// init oauth
			$consumer = new OAuthConsumer($this->consumer_key, $this->consumer_secret);
			$token = new OAuthConsumer($this->access_token, $this->access_secret);
			$hmac_method = new OAuthSignatureMethod_HMAC_SHA1();

			// set stream options
			$context = stream_context_create($this->options);
			stream_context_set_params($context, array('notification' => array($this, 'stream_notification_callback')));

			// try connecting for as long as we may
			do {
				// make oauth url
				$req = OAuthRequest::from_consumer_and_token($consumer, $token, 'POST', $this->create_url(), $this->get_parameters());
				$req->sign_request($hmac_method, $consumer, $token);
				// connect
				$this->stream = fopen($req->to_url(), 'r', false, $context);
				// error?
				if ($this->stream === false) {
					// Some sort of socket error has occured
					$lastErrorNo = is_resource($this->stream) ? socket_last_error($this->stream) : NULL;
					$lastErrorMsg = ($lastErrorNo > 0) ? socket_strerror($lastErrorNo) : 'Socket disconnected';
					if (!$lastErrorNo) {
						$error = error_get_last();
						$lastErrorNo = $error['type'];
						$lastErrorMsg = $error['message'];
					}
					if (strpos($lastErrorMsg, 'Connection timed out')) {
						$this->log('Connection timed out');
					} else {
						$this->log($lastErrorNo.': '.$lastErrorMsg);
					}
				}
			} while ($this->connect && $this->stream === false && $this->sleep());
			
			stream_set_blocking($this->stream, 0);

			// reset connect failures
			$this->connectFailures = 0;
			$this->tcpSleep = 1;

			$this->log(print_r(stream_get_meta_data($this->stream), true), 2);
			
			return true;

		}

		/**
		 * Start receiving the stream.
		 * Output is sent to output.
		 * @see Twilter::output
		 */
		final public function stream_start () {
			$this->log('Stream started');
			while (feof($this->stream) !== true && $this->refresh === false && $this->connect) { 
				$this->output(stream_get_line($this->stream, 65535));
				if ($this->refresh_checked < time() - self::REFRESH_INTERVAL) {
					$this->refresh_checked = time();
					$this->refresh();
				}
			}
			fclose($this->stream);
			if ($this->refresh) {
				$this->connect();
				$this->stream_start();
			}
		}

		/**
		 * Output from the stream. Overwrite me
		 * @param string $str JSON output from Twitter.
		 */
		abstract protected function output ($str);

		/**
		 * Check for data refresh
		 */
		abstract public function refresh ();

		/**
		 * Logging. Overwrite me
		 * @param string $msg debug or error message
		 * @param integer $level debug level
		 */
		abstract protected function log ($msg, $level = 1);
		
		final private function create_url () {
			$urlParts = parse_url(self::STREAM_URL);
			$url = $urlParts['scheme'].'://';
			$url .= $this->get_random_ip($urlParts['host']);
			$url .= ($urlParts['scheme'] == 'https') ? ':443' : ':80';
			$url .= $urlParts['path'];
			return $url;
		}
		
		final private function get_random_ip ($host) {
			$streamIPs = gethostbynamel($host);
			if (count($streamIPs) == 0) {
				throw new ErrorException('Unable to resolve hostname: '.$host);
			}
			$this->log('Resolved '.$host.' to '.implode(', ', $streamIPs));
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
				// no more retries
				return false;
			}
			// Increase retry/backoff up to max
			$this->tcpSleep = ($this->tcpSleep < self::TCP_BACKOFF_MAX) ? $this->tcpSleep * 2 : self::TCP_BACKOFF_MAX;
			// log
			$connectFailuresLeft = $this->connectFailuresMax - $this->connectFailures;
			$this->log('Sleeping '.$this->tcpSleep.' seconds. '.$connectFailuresLeft.' connection retries left.');
			// sleep
			sleep($this->tcpSleep);
			// retry
			return true;
		}

		/**
		* Specifies a set of bounding boxes to track.
		* @see http://dev.twitter.com/pages/streaming_api_methods#locations
		* @param array bounding boxes
		*/
		public function setLocations ($boundingBoxes) {
			$boundingBoxes = ($boundingBoxes === NULL) ? array() : $boundingBoxes;
			sort($boundingBoxes); // Non-optimal, but necessary
			// Flatten to single dimensional array
			$locationBoxes = array();
			foreach ($boundingBoxes as $boundingBox) {
				// Sanity check
				if (count($boundingBox) != 4) {
					// Invalid - Not much we can do here but log error
					$this->log('Invalid location bounding box: [' . implode(', ', $boundingBox) . ']');
					return FALSE;
				}
				// Append this lat/lon pairs to flattened array
				$locationBoxes = array_merge($locationBoxes, $boundingBox);
			}
			// Set flattened value
			$this->locationBoxes = $locationBoxes;
		}

		/**
		* Twilter::setLocationsByCircle(array(
		* 	array(Longitude, Latitude, radius in km)
		* ));
		*  
		* @param array
		*/
		public function setLocationsByCircle ($locations) {
			$boundingBoxes = array();
			foreach ($locations as $locTriplet) {
				// Sanity check
				if (count($locTriplet) != 3) {
					// Invalid - Not much we can do here but log error
					$this->log('Invalid location triplet for ' . __METHOD__ . ': [' . implode(', ', $locTriplet) . ']');
					return FALSE;
				}
				list($lon, $lat, $radius) = $locTriplet;
				// Calc bounding boxes
				$maxLat = round($lat + rad2deg($radius / self::EARTH_RADIUS_KM), 2);
				$minLat = round($lat - rad2deg($radius / self::EARTH_RADIUS_KM), 2);
				// Compensate for degrees longitude getting smaller with increasing latitude
				$maxLon = round($lon + rad2deg($radius / self::EARTH_RADIUS_KM / cos(deg2rad($lat))), 2);
				$minLon = round($lon - rad2deg($radius / self::EARTH_RADIUS_KM / cos(deg2rad($lat))), 2);
				// Add to bounding box array
				$boundingBoxes[] = array($minLon, $minLat, $maxLon, $maxLat);
				// Debugging is handy
				$this->log('Resolved location circle [' . $lon . ', ' . $lat . ', r: ' . $radius . '] -> bbox: [' . $minLon . ', ' . $minLat . ', ' . $maxLon . ', ' . $maxLat . ']');          
			}
			// Set by bounding boxes
			$this->setLocations($boundingBoxes);
		}

		private function stream_notification_callback ($notification_code, $severity, $message, $message_code, $bytes_transferred, $bytes_max) {
			switch ($notification_code) {
				case STREAM_NOTIFY_RESOLVE:
				case STREAM_NOTIFY_AUTH_REQUIRED:
				case STREAM_NOTIFY_COMPLETED:
				case STREAM_NOTIFY_FAILURE:
				case STREAM_NOTIFY_AUTH_RESULT:
					$this->log('code:'.$notification_code.', severity:'.$severity.', msg:'.$message.', msgcode:'.$message_code);
					if ($message_code == 406) {
						$this->connect = false;
					}
					break;

				case STREAM_NOTIFY_REDIRECTED:
					$this->log('Redirected to: '.$message, 2);
					break;

				case STREAM_NOTIFY_CONNECT:
					$this->log('Connected', 1);
					break;

				case STREAM_NOTIFY_FILE_SIZE_IS:
					$this->log('Filesize: '.$bytes_max, 3);
					break;

				case STREAM_NOTIFY_MIME_TYPE_IS:
					$this->log('MIME type: '.$message, 2);
					break;

				case STREAM_NOTIFY_PROGRESS:
					$this->log('Downloaded '.$bytes_transferred.' bytes so far', 3);
					break;
			}
		}

	}

?>