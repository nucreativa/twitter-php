<?php

/**
 * Twitter for PHP - library for sending messages to Twitter and receiving status updates.
 *
 * @author     David Grudl
 * @copyright  Copyright (c) 2008 David Grudl
 * @license    New BSD License
 * @link       http://phpfashion.com/
 * @version    1.2
 */
class Twitter
{
	/**#@+ Timeline {@link Twitter::load()} */
	const ME = 1;
	const ME_AND_FRIENDS = 2;
	const REPLIES = 3;
	const ALL = 4;
	/**#@-*/

	/**#@+ Output format {@link Twitter::load()} */
	const XML = 0;
	const JSON = 16;
	const RSS = 32;
	const ATOM = 48;
	/**#@-*/

	/** @var int */
	public static $cacheExpire = 1800; // 30 min

	/** @var string */
	public static $cacheDir;

	/** @var  user name */
	private $user;

	/** @var  password */
	private $pass;



	/**
	 * Creates object using your credentials.
	 * @param  string  user name
	 * @param  string  password
	 * @throws Exception
	 */
	public function __construct($user, $pass)
	{
		if (!extension_loaded('curl')) {
			throw new TwitterException('PHP extension CURL is not loaded.');
		}

		$this->user = $user;
		$this->pass = $pass;
	}



	/**
	 * Tests if user credentials are valid.
	 * @return boolean
	 * @throws Exception
	 */
	public function authenticate()
	{
		$xml = $this->httpRequest('http://twitter.com/account/verify_credentials.xml');
		return empty($xml->error) && !empty($xml->id);
	}



	/**
	 * Sends message to the Twitter.
	 * @param string   message encoded in UTF-8
	 * @return mixed   ID on success or FALSE on failure
	 */
	public function send($message)
	{
		$xml = $this->httpRequest(
			'https://twitter.com/statuses/update.xml',
			array('status' => $message)
		);
		return $xml->id ? (string) $xml->id : FALSE;
	}



	/**
	 * Returns the most recent statuses.
	 * @param  int    timeline (ME | ME_AND_FRIENDS | REPLIES | ALL) and optional format (XML | JSON | RSS | ATOM)
	 * @param  int    number of statuses to retrieve
	 * @param  int    page of results to retrieve
	 * @return mixed
	 * @throws TwitterException
	 */
	public function load($flags = self::ME, $count = 20, $page = 1)
	{
		static $timelines = array(self::ME => 'user_timeline', self::ME_AND_FRIENDS => 'friends_timeline', self::REPLIES => 'mentions', self::ALL => 'public_timeline');
		static $formats = array(self::XML => 'xml', self::JSON => 'json', self::RSS => 'rss', self::ATOM => 'atom');

		if (!is_int($flags)) { // back compatibility
			$flags = $flags ? self::ME_AND_FRIENDS : self::ME;

		} elseif (!isset($timelines[$flags & 0x0F], $formats[$flags & 0x30])) {
			throw new InvalidArgumentException;
		}

		$res = $this->cachedHttpRequest("http://twitter.com/statuses/" . $timelines[$flags & 0x0F] . '.' . $formats[$flags & 0x30] . "?count=$count&page=$page");
		if (isset($res->error)) {
			throw new TwitterException($res->error);
		}
		return $res;
	}



	/**
	 * Process HTTP request.
	 * @param  string  URL
	 * @param  array   of post data
	 * @return mixed
	 */
	private function httpRequest($url, $postData = NULL)
	{
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_USERPWD, "$this->user:$this->pass");
		curl_setopt($curl, CURLOPT_HEADER, FALSE);
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Expect:'));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE); // no echo, just return result
		if ($postData) {
			curl_setopt($curl, CURLOPT_POST, TRUE);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
		}

		$result = curl_exec($curl);
		if (curl_errno($curl)) {
			throw new TwitterException('Server error: ' . curl_error($curl));
		}

		$type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
		if (strpos($type, 'xml') && $xml = @simplexml_load_string($result)) { // intentionally @
			return $xml;

		} elseif (strpos($type, 'json') && $json = @json_decode($result)) { // intentionally @
			return $json;

		} else {
			throw new TwitterException('Invalid server response');
		}
	}



	/**
	 * Cached HTTP request.
	 * @param  string  URL
	 * @return mixed
	 */
	private function cachedHttpRequest($url)
	{
		if (!self::$cacheDir) {
			return $this->httpRequest($url);
		}

		$cacheFile = self::$cacheDir . '/twitter.' . md5($url);
		$cache = @file_get_contents($cacheFile); // intentionally @
		$cache = strncmp($cache, '<', 1) ? @json_decode($cache) : @simplexml_load_string($cache); // intentionally @
		if ($cache && @filemtime($cacheFile) + self::$cacheExpire > time()) { // intentionally @
			return $cache;
		}

		try {
			$result = $this->httpRequest($url);
			file_put_contents($cacheFile, $result instanceof SimpleXMLElement ? $result->asXml() : json_encode($result));
			return $result;

		} catch (TwitterException $e) {
			if ($cache) {
				return $cache;
			}
			throw $e;
		}
	}

}



/**
 * An exception generated by Twitter.
 */
class TwitterException extends Exception
{
}