<?php

class OpenCPU {
	
	public $session_location = null;
	public $session_token = null;
	public $http_status = null;
	public $header_size = 0;
	public $admin_usage = false;

	private $instance;
	private $user_data = '';
	private $knitr_source = null;
	private $hashes = array();
	private $hash_of_call = null;
	private $called_function = null;
	private $posted = null;

	/**
	 * This will store header information returned by curl
	 * @var Array
	 */
	private $curl_info = array();
	
	/**
	 * Additional curl options to set when making curl request
	 * @var Array 
	 */
	private $curl_opts = array(
		CURLINFO_HEADER_OUT => true,
		CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
		CURLOPT_HEADER => true,
		CURLOPT_ENCODING => ""
	);

	public function __construct($instance, $fdb = null) {
		$this->dbh = $fdb;
		$this->instance = $instance;
		$this->clearUserData();
	}

	public function clearUserData() { // reset to init state, more or less (keep cache)
		$this->user_data = '';
		$this->knitr_source = null;
		$this->admin_usage = false;
		$this->http_status = null;
		$this->called_function = null;
		$this->session_location = null;
		$this->session_token = null;
		$this->replace_var = null;
		$this->posted = null;
		$this->header_size = 0;
		$this->curl_info = array();
	}

	// receives the output from RunUnit->getUserDataInRun
	public function addUserData($data) {
		// loop through the given datasets and import them to R via JSON
		// could I check here whether the dataset contains only null and not even send it to R? but that would break for e.g. is.na(email). hm.
		if (isset($data['datasets'])) {
			foreach ($data['datasets'] as $df_name => $content) {
				$this->user_data .= $df_name . ' = as.data.frame(jsonlite::fromJSON("' . addslashes(json_encode($content, JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK)) . '"), stringsAsFactors=F)
';
			}
			unset($data['datasets']);
		}

		// add other variables in dataset
		foreach ($data as $variable => $value) {
			$this->user_data .= $variable . ' = ' . $value . '
';
		}
	}

	public function anyErrors() {
		$http_code = isset($this->curl_info['http_code']) ? $this->curl_info['http_code'] : null;
		if ($http_code !== null && ($http_code < 100 || $http_code > 302)) {
			return true;
		}

		return false;
	}

	public function responseHeaders() {
		return $this->curl_info;
	}
	
	public function getLocation() {
		return $this->session_location;
	}

	private function handleErrors($message, $result, $post, $in, $level = "alert-danger", $loud = true) {
		$error_msg = $result;
		$date = date('Y-m-d H:i:s');
		if (empty($error_msg) OR (is_string($error_msg) AND !trim($error_msg))) {
			$error_msg = "OpenCPU appears to be down.";
			if (mb_substr($this->instance, 0, 5) == 'https') {
				$error_msg .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
			}
		}
		if (is_array($error_msg) AND count($error_msg) == 1) {
			$error_msg = current($error_msg);
		} elseif (is_array($error_msg)) {
			$error_msg = print_r($error_msg, true);
		}

		if (is_array($post)) {
			$post = current($post);
		}

		if($loud) {
			if($this->admin_usage) {
				alert($date . " <pre style='background-color:transparent;border:0'>" . h($error_msg) . "</pre>", $level, true);
			} else {
				alert($date . ' ' . $message, $level, true);
			}
		}

		opencpu_log($date . " R error in $in: " . print_r($post, true) . " " . $error_msg . "\n");
	}

	private function returnParsed($result, $in = '') {

		if(isset($this->curl_info[CURL::RESPONSE_HEADERS])): // won't be set if fed from cache
			$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];
			if (isset($header_parsed['Location']) && isset($header_parsed['X-Ocpu-Session'])): # won't be there if openCPU is down
				$this->session_location = $header_parsed['Location'];
				$this->session_token = $header_parsed['X-Ocpu-Session'];
			endif;

			if (empty($result['post']) && empty($result['body'])):
				formr_log("Count not find required info in '{$in}' to parse in results: CurlInfo: " . print_r($header_parsed, 1));
				return null;
			endif;
		endif;

		return $this->handleJSON($result['body']);
	}
	
	private function handleJSON($body) {
		$parsed = json_decode($body, true);

		if ($parsed === null):
		elseif (array_key_exists(0,$parsed) && is_string($parsed[0])): // dont change type by accident!
			$parsed = str_replace('/usr/local/lib/R/site-library/', $this->instance . '/ocpu/library/', $parsed[0]);
		elseif (array_key_exists(0,$parsed)):
				$parsed = $parsed[0];
		endif;
		return $parsed;
	}

	public function get($url) {
		return CURL::HttpRequest($url, array(), CURL::HTTP_METHOD_GET, $this->curl_opts, $this->curl_info);
	}
	public function getOld($url) {
		return $this->handleJSON($this->get($url . 'R/.val/json'));
	}
	public function r_function($function, array $post) {

		used_opencpu();
//		pr($function . "<br>" . current($post));
		$hash_of_call = hash("md5", $function . json_encode($post));
		if (($ret = $this->inCache($hash_of_call))) {
			return $ret;
		}

		$this->called_function = $this->instance . '/ocpu/' . $function;
		$method = CURL::HTTP_METHOD_GET;
		$params = array();

		if ($post !== null) {
			$method = CURL::HTTP_METHOD_POST;
			$this->posted = $post;
			$params = http_build_query(array_map('cr2nl', $this->posted));
			$curl_opts = $this->curl_opts + array(CURLOPT_HTTPHEADER => array(
				'Content-Length: ' . strlen($params),
			));
		}

		$result = CURL::HttpRequest($this->called_function, $params, $method, $curl_opts, $this->curl_info);

		$this->http_status = $this->curl_info['http_code'];
		$this->header_size = $this->curl_info['header_size'];

		if ($this->anyErrors()) {
			$this->handleErrors("There were problems with openCPU. Please try again later.", $result, $post, $function, "alert-danger", true);
		}

		$header = $this->curl_info['raw_header'];
		$body = $result;

		$ret = compact('header', 'body', 'post');
		
		$this->addToCache($hash_of_call, $ret);
		return $ret;
	}

	public function identity($post, $return = '/json') {
		return $this->r_function('library/base/R/identity' . $return, $post);
	}

	private function inCache($hash_of_call) {
		if (array_key_exists($hash_of_call, $this->hashes)) { // caching at the lowest level for where I forgot it elsewhere
			used_cache();
			return $this->hashes[$hash_of_call];
		}

		return false;
	}

	private function addToCache($hash_of_call, $result) {
		$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];

		if (isset($header_parsed['X-Ocpu-Cache']) AND $header_parsed['X-Ocpu-Cache'] == "HIT") {
			used_nginx_cache();
		}

		if (!isset($header_parsed['Cache-Control'])) {
			return false;
		}

		// Won't be there if openCPU is down i.e buggus request
		if (!isset($header_parsed['X-Ocpu-Session'])) {
			return false;
		}

		// If we are here then we can cache
		$this->hashes[$hash_of_call] = $result;
		return true;
	}

	public function evaluate($source, $return = '/json') {
		$post = array('x' => '{ 
(function() {
	library(formr)
	' . $this->user_data . '
	' . $source . '
})() }');

		$result = $this->identity($post, $return);

		return $this->returnParsed($result, "evaluate");
	}

	public function evaluateWith($name, $source, $return = '/json') {
		$post = array('x' => '{ 
(function() {
	library(formr)
	' . $this->user_data . '
	with(tail(' . $name . ',1), { ## by default evaluated in the most recent results row
	' . $source . '
	})
})() }');

		$result = $this->identity($post, $return);

		return $this->returnParsed($result, "evaluateWith");
	}

	public function evaluateAdmin($source, $return = '') {
		$this->admin_usage = true;
		$post = array('x' => '{
(function() {
library(formr)
' . $this->user_data . '
' . $source . '
})() }');

		$result = $this->identity($post, $return);

		return $this->debugCall($result);
	}

	public function knit($source, $return = '/json') {
		$post = array('text' => "'" . addslashes($source) . "'");
		$result = $this->r_function('library/knitr/R/knit' . $return, $post);

		return $this->returnParsed($result, "knit");
	}

	public function knit2html($source, $return = '/json', $self_contained = 1) {
		$post = array('text' => "'" . addslashes($source) . "'", 'self_contained' => $self_contained);
		return $this->r_function('library/formr/R/formr_render' . $return, $post);
	}

	public function knitForUserDisplay($source = '') {
		$source = '```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=F,message=F,echo=F)
' . $this->user_data . '
```
'.
$source;

		$result = $this->knit2html($source, '/json');

		return $this->returnParsed($result, "knit");
	}

	public function knitEmail($source) {
		$source = '```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=F,message=F,echo=F)
opts_knit$set(upload.fun=function(x) { paste0("cid:", basename(x)) })
' . $this->user_data . '
```
'.
$source;

		$result = $this->knit2html($source, '', 0);
		if ($this->anyErrors()):
			$response = array(
				'Response' => '<pre>' . htmlspecialchars($result['body']) . '</pre>',
				'HTTP headers' => '<pre>' . htmlspecialchars($result['header']) . '</pre>',
			);
		else:
			$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];
			if (isset($header_parsed['X-Ocpu-Session'])) {
				$session = '/ocpu/tmp/' . $header_parsed['X-Ocpu-Session'] . '/';
			} else {
				return "Error, no session header.";
			}

			$available = explode("\n", $result['body']);

			$response = array();
			$response['images'] = array();

			$rmarkdown_fig_path = '/files/file';
			foreach ($available as $part):
				$upto = mb_strpos($part, $rmarkdown_fig_path);
				$is_figure = mb_strpos($part, "/figure-html/");
				if ($is_figure !== false):
					$image_id = basename($part);
					$response['images'][$image_id] = $this->instance . $part;
				endif;
			endforeach;

			// info/text stdout/text console/text R/.val/text

			if (in_array($session . 'R/.val', $available)):
				$response['body'] = $this->returnParsed( array(
					"post" => $result['post'],
					"header" => $result['header'],
					"body" => $this->get($this->instance . $session . 'R/.val/json'),
				));
			endif;
		endif;

		return $response;
	}

	public function knitEmailForAdminDebug($source) {
		$this->admin_usage = true;

		$source = '```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=F,message=F,echo=F)
#opts_knit$set(upload.fun=formr::email_image)
' . $this->user_data . '
```
'.
$source;

		$this->knitr_source = $source;
		$result = $this->knit2html($source, '', 0);
		return $this->debugCall($result);
	}

	public function knitForAdminDebug($source) {
		$this->admin_usage = true;

		$source = '```{r settings,message=FALSE,warning=F,echo=F}
library(knitr); library(formr)
opts_chunk$set(warning=T,message=T,echo=T)
' . $this->user_data . '
```
'.
$source;

		$this->knitr_source = $source;
		$result = $this->knit2html($source, '');

		return $this->debugCall($result);
	}

	public function debugCall($result) {
		if ($this->http_status === 0):
			$response = array(
				'Response' => 'OpenCPU at ' . $this->instance . ' is down.'
			);
			if (mb_substr($this->instance, 0, 5) == 'https'):
				$response["Response"] .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
			endif;
		elseif ($this->http_status < 303):
			$header_parsed = $this->curl_info[CURL::RESPONSE_HEADERS];
			$available = explode("\n", $result['body']);

			$response = array();

			if (isset($header_parsed['X-Ocpu-Session'])): # won't be there if openCPU is down
				$session = '/ocpu/tmp/' . $header_parsed['X-Ocpu-Session'] . '/';
				#			$session = explode('/',$available[0]);
				#			$session = '/'.$session[1].'/'.$session[2] .'/'.$session[3] . '/';
				// info/text stdout/text console/text R/.val/text

				if (in_array($session . 'R/.val', $available)):
					$response['Result'] = $this->get($this->instance . $session . 'R/.val/text');
				endif;

				$locations = '';
				foreach ($available AS $segment):
					$href = $this->instance . $segment;
					$path = substr($segment, strlen("/ocpu/tmp/" . $header_parsed['X-Ocpu-Session'] . "/"));
					$locations .= "<a href='$href'>$path</a><br>";
				endforeach;
				$response['Locations'] = $locations;
				$response['Function called'] = substr($this->called_function, strlen($this->instance));
				$response['Posted data'] = '<pre>' . print_r($this->posted, true) . '</pre>';


				if ($this->knitr_source === NULL) {
					$response['Call'] = '<pre>' . htmlspecialchars(current($result['post'])) . '</pre>';
				} else {
					$response['Knitr doc'] = '<pre>' . htmlspecialchars($this->knitr_source) . '</pre>';
				}

				$response['HTTP headers'] = '<pre>' . htmlspecialchars($result['header']) . '</pre>';
				$response['Headers sent'] = '<pre>' . htmlspecialchars($this->curl_info['request_header']) . '</pre>';

				if (in_array($session . 'info', $available)):
					$response['Session info'] = '<pre>' . htmlspecialchars($this->get($this->instance . $session . 'info/print')) . '</pre>';
				endif;

				if (in_array($session . 'console', $available)):
					$response['Console'] = '<pre>' . htmlspecialchars($this->get($this->instance . $session . 'console/print')) . '</pre>';
				endif;
				if (in_array($session . 'stdout', $available)):
					$response['Stdout'] = '<pre>' . htmlspecialchars($this->get($this->instance . $session . 'stdout/print')) . '</pre>';
				endif;

			else:
				$response = array(
					'Response' => 'OpenCPU at ' . $this->instance . ' is down.'
				);
				if (mb_substr($this->instance, 0, 5) == 'https'):
					$response['Response'] .= " Maybe check your server's certificates to use encrypted connection to openCPU.";
				endif;
			endif;
		else:

			$response = array(
				'Response' => '<pre>' . htmlspecialchars($result['body']) . '</pre>',
				'HTTP headers' => '<pre>' . htmlspecialchars($result['header']) . '</pre>',
				'Call' => '<pre>' . htmlspecialchars(current($result['post'])) . '</pre>',
				'Headers sent' => '<pre><code class="r hljs">' . htmlspecialchars($this->curl_info['request_header']) . '</code></pre>',
			);
		endif;

		return array_to_accordion($response);
	}

}
