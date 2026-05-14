<?php

namespace ZhangEtAl\Lgbot;

class LGVideoIterator implements \Iterator
{
	private $position = 0;

	private $buffer = [];

	const bufferSize = 20;

	const maxFetchIndex = 19999; # server won't talk to us beyong ?start=19999

	private $doneFetching = false;

	private function bufferIndex() : int
	{
		return ($this->position-$this->offset) % self::bufferSize;
	}

	private function fetchNext()
	{
		if($this->doneFetching || $this->bufferIndex() != 0) return;

		if(is_null($this->user)){
			$this->bot->get("/?start=$this->position");
			$this->bot->log("Fetched");
		}
		else
			$this->bot->get('/user/'.urlencode($this->user)."/allmedia?start=$this->position");
		
		$nodes = iterator_to_array($this->bot->xPath('//div[@id="container"]/div[@class="box rb-q-list-item"]'));


		# Fetch all the data and populate the buffer with it
		$this->buffer = [];
		foreach($nodes as $node)
		{
			$id =       intval(str_replace('q', '', $node->getAttribute('id')));
			$title = $this->bot->xPath('.//div[@class="rb-q-item-title"]/a/text()', $node)[0]?->nodeValue;
			$views =    intval(str_replace(',', '', $this->bot->xPath('.//span[@class="rb-view-count-data"]/text()', $node)[0]?->nodeValue));
			$comments = intval(str_replace(',', '', $this->bot->xPath('.//span[@class="rb-a-count-data"]/text()', $node)[0]?->nodeValue));
			$votes =    intval(str_replace(',', '', $this->bot->xPath('.//span[@class="rb-netvote-count-data"]/text()', $node)[0]?->nodeValue));

			#$this->bot->log("founc $title");

			$this->buffer[] = [
				'id' => $id,
				'title' => $title,
				'views' => $views,
				'comments' => $comments,
				'votes' => $votes
			];
		}


		if(is_null($this->user))
		{
			if($this->position >= self::maxFetchIndex)
			{
				$this->bot->log("Reached end");
				$this->doneFetching = true;
				$this->buffer = array_slice($this->buffer, $this->position-self::maxFetchIndex, self::bufferSize - ($this->position-self::maxFetchIndex));
			}
		}
		else
		{
			if(count($nodes) < self::bufferSize) 
			{
				$this->bot->log("Reached end");
				$this->doneFetching = true;
			}
		}
	}

	public function __construct(
		public LGBot $bot,
		public ?string $user = null,
		public $offset = 0
	) {
		$this->position = $offset;
	}

	public function key()
	{
		return $this->position;
	}

	public function current()
	{
		return $this->buffer[$this->bufferIndex()];
	}

	public function next()
	{
		$this->position++;
		$this->fetchNext();
	}

	public function rewind()
	{
		$this->position = $this->offset;
		$this->buffer = [];
		$this->doneFetching = false;
		$this->fetchNext();
	}

	public function valid()
	{
		# When there is no more to fetch, if the position has reached the end of 
		# the ids array, then we're done iterating!
		return !$this->doneFetching || $this->bufferIndex() < count($this->buffer);
	}
}