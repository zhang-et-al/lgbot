<?php

namespace ZhangEtAl\Lgbot;

# require_once 'vendor/autoload.php';
require 'comments.php';

class LGBot
{
	public $client;
	private $cookieJar;
	private $domDocument;

	private $username;

	public function request(string $method, $uri = '', array $options = []) : Psr\Http\Message\ResponseInterface // GuzzleHttp\Psr7\Response
	{
		$res = $this->client->request($method, $uri, $options);

		if(in_array($res->getHeaderLine('Content-Type'), ['application/xml', 'text/xml', 'text/html', 'application/xhtml+xml']))
			$this->domDocument->loadhtml($res->getBody());

		return $res;
	}

	public function __construct(
		private string $email,
		private string $password,
		private bool $tor,
		private ?string $workingDir = null
	) {
		if(is_null($workingDir)) $workingDir = dirname($_SERVER['PHP_SELF']);

		// Sets up the cookie jar
		if(!is_dir("$workingDir/cookies")) mkdir("$workingDir/cookies");

		$this->cookieJar = new \GuzzleHttp\Cookie\FileCookieJar("$workingDir/cookies/$email.json", true);

		// Initialises the xPath engine
		libxml_use_internal_errors(true);
		$this->domDocument = new \DOMDocument;

		// Initialises the Guzzle client
		$this->client = new \GuzzleHttp\Client([
			'base_uri' => 'https://www.livegore.com/',
			'cookies' => $this->cookieJar,
			'proxy' => $tor ? 'socks5://127.0.0.1:9050' : '',
			'headers' => [
				'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
				'Accept-Encoding' => 'gzip, deflate, sdch, br'
			],
			'debug' => false
		]);

		// If the session cookies are valid, skip login
		if($this->getUsername())
		{
			$this->log("Already logged in");
			return;
		}

		$this->log("Logging in...");

		$res = $this->client->get('/login');
		$code = $this->runXPath($res, '//input[@name="code"]/@value')[0]->nodeValue;
		
		$res = $this->client->post('/login', [
			'form_params' => [
				'emailhandle' => $this->email,
				'password' => $this->password,
				'dologin' => 1,
				'code' => $code
			],
			'allow_redirects' => false
		]);

		$status = $res->getStatusCode();
		if($status != 302)
		{
			$this->log("Got code $status. Could not log in!");
			return;
		}

		$this->getUsername();
	}

	private function runXPath(string|\GuzzleHttp\Psr7\Response $xml, string $query)
	{
		if($xml) $this->domDocument->loadhtml(is_string($xml) ? $xml : $xml->getBody());
		$this->xpath = new \DOMXPath($this->domDocument);
		return $this->xpath->query($query);
	}

	// Returns the account's username or null if not logged it
	public function getUsername(bool $forceCheck = false) : string|null
	{
		if($this->username == null || $forceCheck)
		{
			$res = $this->client->get('/');
			$this->username = $this->runXPath($res, '//*[@class="rb-havatar"]/*[@class="rb-avatar-link"]/@href')[0]?->nodeValue;

			if($this->username) $this->username = substr($this->username, 7);
		}
		return $this->username;
	}
	//public function login(string $email, string $password)

	private $sitemap = null;

	public function getSitemap(bool $forceReload = false) : string
	{
		if(!$this->sitemap || $forceReload)
		{
			#$this->sitemap = $this->client->get('/sitemap.xml')->getBody();
			$this->sitemap = file_get_contents("$workingDir/sitemap.xml");
		}
		return $this->sitemap;
	}

	public function getAllVideos(bool $forceReload = false) : array
	{

		return array_map(fn($entry) => intval(substr($entry->nodeValue, 25, strpos($entry->nodeValue, '/', 25)-25)),
			iterator_to_array($this->runXPath($this->getSitemap(), '//loc[not(contains(text(), "/user/") or contains(text(), "/tag/") or contains(text(), "/questions/") or contains(text(), "/categories"))]/text()')));
	}

	public function getAllUsers(bool $forceReload = false) : array
	{
		return array_map(fn($entry) => $entry->nodeValue, iterator_to_array($this->runXPath($this->getSitemap(), '//loc[contains(text(), "/user/")]/text()')));
	}

	public function loadVideo(int $id) : void
	{
		$res = $this->client->get("/$videoId");
	}

	public function getVideoStreamURL(int $videoId) : string
	{
		$res = $this->client->get("/$videoId");
		return $this->runXPath($res, '//video/source/@src')[0]->nodeValue;
	}

	public function comment(string $text, int $id) : ?int
	{
		$res = $this->client->get("/$id");
		$code = $this->runXPath($res, '//form[@name="a_form"]//input[@name="code"]/@value')[0]->nodeValue;

		$res = $this->client->post("/", [
			'form_params' => [
				'a_content' => $text,
				'' => 'Add Comment',
				'a_editor' => '',
				'a_doadd' => 1,
				'code' => $code,
				'a_questionid' => $id,
				'qa' => 'ajax',
				'qa_operation' => 'answer',
				'qa_root' => '../',
				'qa_request' => $id
			],
			'allow_redirects' => false,
		]);

		$c = $res->getStatusCode();
		if($c != 200)
		{
			$this->log("Could not comment. Server responded with code $c");
			return null;
		}

		$body = $res->getBody();
		if(explode("\n", $body)[1] != '1')
		{
			$this->log("Could not comment. Server responded with (truncated):\n". substr($body, 0, 50));
			return null;
		}

		$this->log("Commented on $id");

		$res = $res->getBody();
		$res = substr($res, strpos($res, '<'));

		return intval($this->runXPath($res, '//div[@class="rb-a-item-content"]/a/@name')[0]->nodeValue);
	}


	public function getVideoUploader(int $id) : string
	{
		$res = $this->client->get("/$id");
		return $this->runXPath($res, '//div[@class="solyan"]//span[@class="vcard author"]/a/text()')[0]->nodeValue;
	}

	public function vote(int $videoId, ?int $commentId = null)
	{
		$res = $this->client->get("/$videoId");

		//if(is_null($commentId))
			$code = $this->runXPath($res, '//div[@class="sharetop"]//form//input[@name="code"]/@value')[0]->nodeValue;
		//else


		$res = $this->client->post("/", [
			'form_params' => [
				'postid' => $commentId ?? $videoId,
				'vote' => 1,
				'code' => $code,
				'qa' => 'ajax',
				'qa_operation' => 'vote',
				'qa_root' => '../',
				'qa_request' => $videoId
			],
			'allow_redirects' => false,
		]);

		$c = $res->getStatusCode();
		if($c != 200)
		{
			$this->log("Could not vote. Server responded with code $c");
			return;
		}

		$body = $res->getBody();
		if(explode("\n", $body)[1] != '1')
		{
			$this->log("Could not vote. Server responded with (truncated):\n". substr($body, 0, 50));
			return;
		}

		$this->log("Voted " . (is_null($commentId) ? '' : "comment $commentId ") . "on $videoId");
	}

	public function getPoints() : int
	{
		$res = $this->client->get('/account');

		$points = $this->runXPath($res, '//span[@class="rb-profile-point"]/text()');

		return intval(substr($points, 0, strpos($points, ' ')));
	}

	// Yes, it's uploadFile and not uploadVideo. Livegore will let you upload any file
	// as long as you set its extension to '.mp4'
	// Only problem is the server will only serve it with a video/mp4 mimetype
	public function uploadFile(string $filename) : string
	{
		if(!is_file($filename))
			throw new \Exception("Attempted to upload non-existing file $filename");

		$res = $this->client->post('/rb-include/videoupload.php', [
			'headers' => [
				'Referer' => 'https://www.livegore.com/video',
				'X-Requested-With' => 'XMLHttpRequest',
				'Sec-GPC' => '1',
				'Sec-Fetch-Dest' => 'empty',
				'Sec-Fetch-Mode' => 'cors',
				'Sec-Fetch-Site' => 'same-origin'
			],
			'multipart' => [
				[
					'name' => 'myfile',
					'filename' => pathinfo($filename, PATHINFO_FILENAME) . '.mp4',
					'contents' => fopen($filename, 'r'),
				]
			],
			'allow_redirects' => false
		]);

		$responseBody = $res->getBody();
		$jsonResponse = json_decode($responseBody, true);

		if(!$jsonResponse)
		{
			$this->log("Could not upload: server responded with $responseBody");
			return "";
		}

		return 'https://xxx.livegore.com/rb-include/videos/' . $jsonResponse[0] . '.mp4';
	}


	// Since the Livegore backend does manipulate the images you post (unlike the videos), it will typically
	// respond with a "resize error" if you post a non-image file
	// It seems the 2mb file size limit is only checked on the frontend
	// Use this method instead of uploadFile() to post images that you want to be displayed as such
	public function uploadImage(string $filename) : string
	{
		if(!is_file($filename))
			throw new \Exception("Attempted to upload non-existing file $filename");

		# Default extension
		$payloadExt = "jpg";

		$fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		# Other allowed extensions
		if(in_array($fileExt, ['png', 'gif']))
			$payloadExt = $fileExt;

		$res = $this->client->post('/rb-include/processupload.php', [
			'headers' => [
				'Referer' => 'https://www.livegore.com/video',
				'X-Requested-With' => 'XMLHttpRequest',
				'Sec-GPC' => '1',
				'Sec-Fetch-Dest' => 'empty',
				'Sec-Fetch-Mode' => 'cors',
				'Sec-Fetch-Site' => 'same-origin'
			],
			'multipart' => [
				[
					'name' => 'ImageFile',
					'filename' => pathinfo($filename, PATHINFO_FILENAME) . ".$payloadExt",
					'contents' => fopen($filename, 'r'),
				]
			],
			'allow_redirects' => false
		]);

		$imageUrl = $this->runXPath($res, '//img[1]/@src')[0]?->nodeValue;

		if(!$imageUrl)
		{
			$this->log("Could not upload: server responded with \n". $res->getBody());
			return "";
		}

		return $imageUrl;
	}

	public function log(string $info, mixed... $values) : void
	{
		printf("[%s](%s) $info\n", $this->getUsername(), date('d/m/Y H:i:s'), $values);
	}

	public static function randomShittyComment()
	{
		global $comments;
		return strtolower($comments[array_rand($comments)]);
	}
}