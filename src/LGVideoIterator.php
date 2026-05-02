<?php

namespace ZhangEtAl\Lgbot;

class LGVideoIterator implements \Iterator
{
	private $position = 0;

	private $ids = [];

	private $doneFetching = false;

	private function fetchNext()
	{
		if($this->doneFetching || $this->position < count($this->ids)) return;

		if(is_null($this->user))
			$this->bot->get("/?start=".($this->position+$this->offset));
		else
			$this->bot->get('/user/'.urlencode($this->user)."/allmedia?start=".($this->position+$this->offset));

		
		$nodes = iterator_to_array($this->bot->xPath('//div[@id="container"]/div[@class="box rb-q-list-item"]/@id'));


		if(is_null($this->user))
		{
			if($this->position+$this->offset >= 20000)
			{
				$this->bot->log("Reached end");
				$this->doneFetching = true;
			}
		}
		else
		{
			if(count($nodes) < 20) 
			{
				$this->bot->log("Reached end");
				$this->doneFetching = true;
			}
		}

		$this->ids = array_merge($this->ids, array_map(
			fn($node) => intval(str_replace('q', '', $node->nodeValue)),
			$nodes
		));

	}

	public function __construct(
		public LGBot $bot,
		public ?string $user = null,
		public $offset = 0
	) {	}

	public function key()
	{
		return $this->position;
	}

	public function current()
	{
		return $this->ids[$this->position];
	}

	public function next()
	{
		$this->position++;
		$this->fetchNext();
	}

	public function rewind()
	{
		$this->position = 0;
		$this->ids = [];
		$this->doneFetching = false;
		$this->fetchNext();
	}

	public function valid()
	{
		# When there is no more to fetch, if the position has reached the end of 
		# the ids array, then we're done iterating!
		return !$this->doneFetching || $this->position < count($this->ids);
	}
}