<?php

namespace ZhangEtAl\Lgbot;

# require_once 'vendor/autoload.php';


class LGBot
{
	public $client;
	private $cookieJar;
	private $domDocument;

	public readonly string $username;

	private static $comments = [];

	private function loadResponseHTML(\Psr\Http\Message\ResponseInterface $res)
	{
		if(
			$res->getBody() != '' && 
			(str_contains($res->getHeaderLine('Content-Type'), 'xml') || str_contains($res->getHeaderLine('Content-Type'), 'html'))
		)
		{
			$this->domDocument->loadhtml($res->getBody());
		}
	}

	public function request(string $method, $uri = '', array $options = []) : \Psr\Http\Message\ResponseInterface // GuzzleHttp\Psr7\Response
	{
		$res = $this->client->request($method, $uri, $options);

		$this->loadResponseHTML($res);

		return $res;
	}

	public function get(string $uri, array $options = []) : \Psr\Http\Message\ResponseInterface
	{
		$res = $this->client->get($uri, $options);

		$this->loadResponseHTML($res);

		return $res;
	}

	public function post(string $uri, array $options = []) : \Psr\Http\Message\ResponseInterface
	{
		$res = $this->client->post($uri, $options);

		$this->loadResponseHTML($res);

		return $res;
	}

	public function __construct(
		private string $email,
		private string $password,
		private bool $tor,
		private ?string $workingDir = null
	) {
		$this->workingDir ??= dirname($_SERVER['PHP_SELF']);

		// Sets up the cookie jar
		if(!is_dir($this->workingDir. "/cookies"))
			mkdir($this->workingDir . "/cookies");

		# Loads the comments for spamming
		if(!count(self::$comments))
		{
			$commentsPath = __DIR__ . "/../resources/comments.txt";

			$fp = fopen($commentsPath, "r");

			if($fp) {
				while(($buffer = fgets($fp)) !== false)
					self::$comments[] = $buffer;
			}
			else
			{
				$this->log("Error: could not load comments from $commentsPath");
			}

			fclose($fp);
		}

		$this->cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($this->workingDir. "/cookies/$email.json", true);

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
		$this->get('/');
		$username = $this->xPath('//*[@class="rb-havatar"]/*[@class="rb-avatar-link"]/@href')[0]?->nodeValue;

		if($username)
		{
			$this->username = substr($username, 7);
			$this->log("Already logged in");
			return;
		}
		else
		{
			$this->log("Logging in...");

			$this->get('/login');
			$code = $this->xPath('//input[@name="code"]/@value')[0]->nodeValue;
			
			$res = $this->post('/login', [
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

			# Checks the login was successful
			$this->get('/');
			$username = $this->xPath('//*[@class="rb-havatar"]/*[@class="rb-avatar-link"]/@href')[0]?->nodeValue;

			if($username)
			{
				$this->username = substr($username, 7);
				$this->log("Login successful!");
			}
			else
			{
				$this->log("Login failed: could not find username!");
			}
		}
	}

	public function xPath(string $query, ?\DOMNode $contextNode = null)
	{
		$this->xpath = new \DOMXPath($this->domDocument);
		return $this->xpath->query($query, $contextNode);
	}


	private $sitemap = null;

	public function getSitemap(bool $forceReload = false) : string
	{
		if(!$this->sitemap || $forceReload)
		{
			#$this->sitemap = $this->client->get('/sitemap.xml')->getBody();
			$this->sitemap = file_get_contents($this->workingDir . "/sitemap.xml");
		}
		return $this->sitemap;
	}

	public function getAllVideos(bool $forceReload = false) : array
	{
		$this->domDocument->loadhtml($this->getSitemap());

		return array_map(fn($entry) => intval(substr($entry->nodeValue, 25, strpos($entry->nodeValue, '/', 25)-25)),
			iterator_to_array($this->xPath('//loc[not(contains(text(), "/user/") or contains(text(), "/tag/") or contains(text(), "/questions/") or contains(text(), "/categories"))]/text()')));
	}

	public function getAllUsers(bool $forceReload = false) : array
	{
		$this->domDocument->loadhtml($this->getSitemap());

		return array_map(fn($entry) => $entry->nodeValue, iterator_to_array($this->xPath('//loc[contains(text(), "/user/")]/text()')));
	}

	public function loadVideo(int $id) : void
	{
		$res = $this->get("/$videoId");
	}

	public function getVideoStreamURL(int $videoId) : string
	{
		$this->get("/$videoId");
		return $this->xPath('//video/source/@src')[0]->nodeValue;
	}

	public function comment(string $text, int $id) : ?int
	{
		$this->get("/$id");
		$code = $this->xPath('//form[@name="a_form"]//input[@name="code"]/@value')[0]?->nodeValue;

		if(is_null($code))
		{
			$this->log("Could not comment. Failed to find form code (likely causes: rate limit or banned account)");
			return null;
		}

		$res = $this->post("/", [
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
		
		$this->domDocument->loadhtml(substr($res, strpos($res, '<')));

		return intval($this->xPath('//div[@class="rb-a-item-content"]/a/@name')[0]->nodeValue);
	}

	public function reply(string $text, int $videoId, int $commentId) : ?int
	{
		$this->get("/$videoId");
		$code = $this->xPath("//input[@name='c{$commentId}_code']/@value")[0]?->nodeValue;

		if(is_null($code))
		{
			$this->log("Could not reply. Failed to find form code (likely causes: rate limit or banned account)");
			return null;
		}

		$res = $this->post("/", [
			'form_params' => [
				"c{$commentId}_content" => $text,
				'' => 'Add Comment',
				'docancel' => 'Cancel',
				"c{$commentId}_editor" => '',
				"c{$commentId}_doadd" => 1,
				"c{$commentId}_code" => $code,
				'c_questionid' => $videoId,
				'c_parentid' => $commentId,
				'qa' => 'ajax',
				'qa_operation' => 'comment',
				'qa_root' => '../',
				'qa_request' => $videoId
			],
			'allow_redirects' => false,
		]);

		$c = $res->getStatusCode();
		if($c != 200)
		{
			$this->log("Could not reply. Server responded with code $c");
			return null;
		}

		$body = $res->getBody();
		$lines = explode("\n", $body);

		if($lines[1] != '1')
		{
			$this->log("Could not reply. Server responded with (truncated):\n". substr($body, 0, 50));
			return null;
		}

		$this->log("Replied to $commentId on $videoId");

		return intval(str_replace('c', '', $lines[2]));
	}

	public function hideComment(int $videoId, int $commentId)
	{
		$code = $this->xPath("//div[@id='a$commentId']//form[2]//input[@name='code']/@value")[0]?->nodeValue;

		if(is_null($code))
		{
			$this->log("Could not hide. Failed to find form code (likely causes: rate limit or banned account)");
			return null;
		}

		$res = $this->post("/", [
			'form_params' => [
				'questionid' => $videoId,
				'answerid' => $commentId,
				'code' => $code,
				"a{$commentId}_dohide" => 'hide',
				'qa' => 'ajax',
				'qa_operation' => 'click_a',
				'qa_root' => '../',
				'qa_request' => $videoId
			],
			'allow_redirects' => false,
		]);

		$c = $res->getStatusCode();
		if($c != 200)
		{
			$this->log("Could not hide. Server responded with code $c");
			return null;
		}

		$body = $res->getBody();
		$lines = explode("\n", $body);

		if($lines[1] != '1')
		{
			$this->log("Could not hide. Server responded with (truncated):\n". substr($body, 0, 50));
			return null;
		}

		$this->log("Hid $commentId on $videoId");

		return intval(str_replace('c', '', $lines[2]));
	}

	public function hideReply(int $videoId, int $commentId, int $replyId)
	{
		$this->get("/$videoId");
		$code = $this->xPath("//div[@id='a$commentId']/div[@class='rb-a-item-main']/form/input[@name='code']/@value")[0]?->nodeValue;

		if(is_null($code))
		{
			$this->log("Could not hide. Failed to find form code (likely causes: rate limit or banned account)");
			return null;
		}

		$res = $this->post("/", [
			'form_params' => [
				'commentid' => $replyId,
				'questionid' => $videoId,
				'parentid' => $commentId,
				'code' => $code,
				"c{$replyId}_dohide" => 'hide',
				'qa' => 'ajax',
				'qa_operation' => 'click_c',
				'qa_root' => '../',
				'qa_request' => $videoId
			],
			'allow_redirects' => false,
		]);

		
		$c = $res->getStatusCode();
		if($c != 200)
		{
			$this->log("Could not hide. Server responded with code $c");
			return null;
		}

		$body = $res->getBody();
		$lines = explode("\n", $body);

		if($lines[1] != '1')
		{
			$this->log("Could not hide. Server responded with (truncated):\n". substr($body, 0, 50));
			return null;
		}

		$this->log("Hid reply $replyId on $videoId");

		return intval(str_replace('c', '', $lines[2]));
	}

	public function getVideoUploader(int $id) : string
	{
		return $this->xPath('//div[@class="solyan"]//span[@class="vcard author"]/a/text()')[0]->nodeValue;
	}

	public function vote(int $videoId, ?int $commentId = null)
	{
		$this->get("/$videoId");

		//if(is_null($commentId))
			$code = $this->xPath('//div[@class="sharetop"]//form//input[@name="code"]/@value')[0]->nodeValue;
		//else


		$res = $this->post("/", [
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
		$this->get('/account');

		$points = $this->xPath('//span[@class="rb-profile-point"]/text()');

		return intval(substr($points, 0, strpos($points, ' ')));
	}

	// Yes, it's uploadFile and not uploadVideo. Livegore will let you upload any file
	// as long as you set its extension to '.mp4'
	// Only problem is the server will only serve it with a video/mp4 mimetype
	public function uploadFile(string $filename) : string
	{
		if(!is_file($filename))
			throw new \Exception("Attempted to upload non-existing file $filename");

		$res = $this->post('/rb-include/videoupload.php', [
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
	public function uploadImage(string $filename) : ?string
	{
		if(!is_file($filename))
			throw new \Exception("Attempted to upload non-existing file $filename");

		# Default extension
		$payloadExt = "jpg";

		$fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		# Other allowed extensions
		if(in_array($fileExt, ['png', 'gif']))
			$payloadExt = $fileExt;

		$res = $this->post('/rb-include/processupload.php', [
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

		$imageUrl = $this->xPath('//img[1]/@src')[0]?->nodeValue;

		if(!$imageUrl)
		{
			$this->log("Could not upload: server responded with \n". $res->getBody());
			return null;
		}

		return $imageUrl;
	}

	public function message(string $user, string $message)
	{
		$this->get("https://www.livegore.com/message/$user");
		$code = $this->xPath('//input[@name="code"]/@value')[0]->nodeValue;
		
		$res = $this->post("https://www.livegore.com/message/$user", [
			'form_params' => [
				'message' => $message,
				'domessage' => 1,
				'code' => $code
			],
			'allow_redirects' => false,
		]);

		$c = $res->getStatusCode();
		if($c != 302)
		{
			$this->log("Could not message. Server responded with code $c");
			return false;
		}

		return true;
	}

	public function log(string $info, mixed... $values) : void
	{
		printf("[%s](%s) $info\n", $this->username ?? $this->email, date('d/m/Y H:i:s'), $values);
	}

	public static function randomShittyComment()
	{
		return strtolower(self::$comments[array_rand(self::$comments)]);
	}
}
