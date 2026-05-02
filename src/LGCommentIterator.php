<?php

namespace ZhangEtAl\Lgbot;

class LGCommentIterator implements \Iterator
{
	private $position = 0;

	private $comments = [];

	private $doneFetching = false;

	private function getCommentData(\DOMNode $node, bool $reply = false) : array
	{
		$username = $this->bot->xPath('.//a[@class="rb-user-link url nickname"][1]/text()', $node)[0]?->nodeValue;

		$anonymous = false;

		if(is_null($username))
		{
			$anonymous = true;
			$username = $this->bot->xPath('.//span[@class="meta-who-data"][1]/text()', $node)[0]?->nodeValue;
		}

		$date = $this->bot->xPath('.//span[@class="published updated"][1]/span/@title', $node)[0]?->nodeValue;
		$timestamp = strtotime($date);

		if($reply)
		{
			$data = [
				'id' => intval(str_replace('c', '', $node->getAttribute('id'))),
				'username' => $username,
				'anonymous' => $anonymous,
				'date' => $date,
				'timestamp' => $timestamp,
				'text' => $this->bot->xPath('.//div[@class="entry-content"][1]/text()', $node)[0]?->nodeValue
			];	
		}
		else
		{
			$data = [
				'id' => intval(str_replace('a', '', $node->getAttribute('id'))),
				'username' => $username,
				'anonymous' => $anonymous,
				'votes' => intval($this->bot->xPath('.//span[@class="rb-netvote-count-data"][1]/text()', $node)[0]?->nodeValue),
				'date' => $date,
				'timestamp' => $timestamp,
				'text' => $this->bot->xPath('.//div[@class="entry-content"][1]/text()', $node)[0]?->nodeValue
			];

			
			$replies = $this->bot->xPath('.//div[@class="rb-c-list-item  hentry comment"]', $node);

			if(count($replies) > 0) $data['replies'] = [];

			foreach($replies as $rep)
			{
				$data['replies'][] = $this->getCommentData($rep, true);
			}
			
		}

		return $data;
	}

	private function fetchNext()
	{
		if($this->doneFetching || $this->position < count($this->comments)) return;

		$this->bot->get("$this->video/?start=".($this->position+$this->offset))->getBody();

		$nodes = iterator_to_array($this->bot->xPath('//div[@id="comments"]//div[@class="rb-a-list-item  hentry answer"]'));
	
		if(count($nodes) < 20) 
		{
			#$this->bot->log("Reached end");
			$this->doneFetching = true;
		}

		$this->comments = array_merge($this->comments, array_map(
			fn($node) => $this->getCommentData($node),
			$nodes
		));
	}

	public function __construct(
		public LGBot $bot,
		public int $video,
		public $offset = 0
	) {	}

	public function key()
	{
		return $this->position;
	}

	public function current()
	{
		return $this->comments[$this->position];
	}

	public function next()
	{
		$this->position++;
		$this->fetchNext();
	}

	public function rewind()
	{
		$this->position = 0;
		$this->comments = [];
		$this->doneFetching = false;
		$this->fetchNext();
	}

	public function valid()
	{
		# When there is no more to fetch, if the position has reached the end of 
		# the comments array, then we're done iterating!
		return !$this->doneFetching || $this->position < count($this->comments);
	}
}