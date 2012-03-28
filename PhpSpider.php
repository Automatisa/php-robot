<?php
class PhpSpider {
	private $exit_after_done;
	private $db;
	private $site;
	private $queue;
	private $queue_len;
	private $mh;
	private $ch_options;
	private $max_concurrent;
	private $crawler_delay;
	private $page_expires;
	private $init_queue_from_entry;
	static private $show_verbose;
	private $curl_pool;
	
	public function __construct() {
		xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
		self::$show_verbose = false;
		$this->exit_after_done = true;
		$this->db = new PDO( 
			'mysql:host=localhost;dbname=PhpSpider', 
			'root',//username
			'',//password
			array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES GBK") 
		);
		
		$this->site = array();
		$this->queue = array();
		$this->curl_pool = array();
		$this->queue_len = 0;
		
		$this->mh = null;
		$this->max_concurrent = 5;
		$this->ch_options = array(
			CURLOPT_HTTPHEADER=>array('Accept-Encoding: gzip,deflate'/*, 'Connection: close'*/),
			CURLOPT_ENCODING=>TRUE,
			CURLOPT_HEADER=>FALSE,
			CURLOPT_RETURNTRANSFER=>TRUE,
			CURLOPT_FOLLOWLOCATION=>FALSE,
			CURLOPT_DNS_USE_GLOBAL_CACHE=>FALSE,
			#CURLOPT_VERBOSE=>TRUE,
			CURLOPT_USERAGENT=>"Mozilla/5.0 (Windows; U; Windows NT 6.1; zh-CN;)"
		);
		$this->check_database();
		$this->crawler_delay = 10;
		$this->page_expires = 99999999;
		$this->init_queue_from_entry = true;
	}

	public function __destruct(){
		if ($this->mh !== null) {
			curl_multi_close($this->mh);
		}
		foreach($this->curl_pool as &$item) {
			foreach($item as &$ch) {
				curl_close($ch);
			}
		}
		$this->curl_pool = null;
		$this->db = null;
		file_put_contents('profile.txt', print_r(xhprof_disable(), true));
	}
	
	private function check_database() {
		$sqls = array();
		$sql = <<<____SQL
CREATE TABLE IF NOT EXISTS `ps_site` (
 `id` INT NOT NULL AUTO_INCREMENT,
 `domain` varchar(32) NOT NULL DEFAULT '',
 `stop` tinyint NOT NULL DEFAULT '0',
 `interest_url` varchar(512) NOT NULL DEFAULT '',
 `update_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 unique key (`domain`)
 ) ENGINE=MyISAM DEFAULT CHARSET=gbk;
____SQL;

		$sqls[] = $sql;
		$sql = <<<____SQL
CREATE TABLE IF NOT EXISTS `ps_entry` (
 `id` INT NOT NULL AUTO_INCREMENT,
 `site_id` INT NOT NULL DEFAULT '0',
 `depth` INT NOT NULL DEFAULT '0',
 `entry` varchar(255) NOT NULL DEFAULT '',
 `info` varchar(255) NOT NULL DEFAULT '',
 `update_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 `stop` tinyint NOT NULL DEFAULT '0',
 PRIMARY KEY (`id`),
 unique key (`entry`)
 ) ENGINE=MyISAM DEFAULT CHARSET=gbk;
____SQL;

		$sqls[] = $sql;
		$sql = <<<____SQL
CREATE TABLE IF NOT EXISTS `ps_urls` (
 `id` INT NOT NULL AUTO_INCREMENT,
 `refer` INT NOT NULL DEFAULT '0',
 `depth` INT NOT NULL DEFAULT '0',
 `uri` varchar(512) NOT NULL DEFAULT '',
 `update_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
 `umd5` varchar(32) NOT NULL,
 PRIMARY KEY (`id`),
 unique key (`umd5`)
 ) ENGINE=MyISAM DEFAULT CHARSET=gbk;
____SQL;

		$sqls[] = $sql;
		$sql = <<<____SQL
CREATE TABLE IF NOT EXISTS `ps_page` (
 `id` INT NOT NULL,
 `source` text,
 `smd5` varchar(32) NOT NULL,
 PRIMARY KEY (`id`)
 ) ENGINE=MyISAM DEFAULT CHARSET=gbk;
____SQL;

		$sqls[] = $sql;
		$sql = <<<____SQL
CREATE TABLE IF NOT EXISTS `ps_queue` (
 `id` INT NOT NULL AUTO_INCREMENT,
 `site_id` INT NOT NULL DEFAULT '0',
 `depth` INT NOT NULL DEFAULT '0',
 `refer_id` INT NOT NULL DEFAULT '0',
 `umd5` varchar(32) NOT NULL,
 `uri` varchar(512) NOT NULL,
 `refer` varchar(512) NOT NULL,
 PRIMARY KEY (`id`),
 unique key (`umd5`),
 KEY (`depth`) USING BTREE
 ) ENGINE=MyISAM DEFAULT CHARSET=gbk;
____SQL;

		foreach($sqls as $sql) {
			if ($this->db->exec($sql) === FALSE) {
				die(print_r($this->db->errorInfo(), true));
			}
		}
	}

	private function curl_push($site_id, $ch) {
		if (!isset($this->curl_pool[$site_id]) || !is_array($this->curl_pool[$site_id])) {
			$this->curl_pool[$site_id] = array($ch);
		} else {
			$this->curl_pool[$site_id][] = $ch;
		}
	}
	
	private function curl_pop($site_id) {
		if (is_array($this->curl_pool[$site_id]) && !empty($this->curl_pool[$site_id])) {
			return array_shift($this->curl_pool[$site_id]);
		}
		return curl_init();
	}
	
	static private function verbose($msg) {
		if (self::$show_verbose) {
			echo $msg, chr(10);
		}
	}
	
	static public function ShowVerbose($show) {
		self::$show_verbose = ($show & TRUE);
	}
	
	public function site_getid($domain) {
		$sth = $this->db->prepare('SELECT id FROM `ps_site` WHERE `domain`=? LIMIT 1');
		$sth->bindParam(1, $domain);
		if ($sth->execute() === FALSE) {
			echo __LINE__,':PDOStatement::errorInfo():',chr(10);
			die(print_r($sth->errorInfo(), true));
		}
		$ids = $sth->fetchAll(PDO::FETCH_COLUMN, 0);
		$sth->closeCursor();
		$sth = null;
		if (isset($ids[0])) {
			return $ids[0];
		}
		return 0;
	}

	public function site_insert($domain, $stop, $interest_url) {
		$id = $this->site_getid($domain);
		if ($id > 0) {
			return $id;
		}
		$sth = $this->db->prepare('INSERT INTO `ps_site`(`domain`,`stop`,`interest_url`) VALUES(?,?,?)');
		$sth->bindParam(1, $domain, PDO::PARAM_STR);
		$sth->bindParam(2, $stop, PDO::PARAM_INT);
		$sth->bindParam(3, $interest_url, PDO::PARAM_STR);
		$sth->execute();
		$rowCount = $sth->rowCount();

		$sth->closeCursor();
		$sth = null;
		if ($rowCount > 0) {
			$id = $this->db->lastInsertId();
			self::verbose("insert site $id $domain $stop $interest_url");
			return $id;
		}
		return 0;
	}

	private function check_site() {
		$sql = 'select id,stop,interest_url from ps_site';
		$sth = $this->db->query($sql);
		foreach ($sth->fetchAll() as $row) {
			if ($row['stop']) {
				if (isset($this->site[$row['id']]) && is_array($this->site[$row['id']]) && count($this->site[$row['id']])) {
					$this->site[$row['id']] = array();
				}
			} else {
				$this->site[$row['id']] = explode ('###', $row['interest_url']);
			}
		}
		$sth->closeCursor();
		$sth = null;
	}
	
	private function is_interest_url($site_id, $url) {
		if (!isset($this->site[$site_id]) || !is_array($this->site[$site_id])) {
			$this->check_site();
		}
		if (isset($this->site[$site_id]) && is_array($this->site[$site_id])) {
			foreach($this->site[$site_id] as $re) {
				if (preg_match($re, $url)) {
					return TRUE;
				}
			}
			return FALSE;
		} else {
			return TRUE;
		}
	}
	
	public function getUrlInfo($uri) {
		$sth = $this->db->prepare('SELECT id,TIMESTAMPDIFF(HOUR,update_time,CURRENT_TIMESTAMP) as hours,depth FROM `ps_urls` WHERE `umd5`=MD5(?) LIMIT 1');
		$sth->bindParam(1, $uri);
		if ($sth->execute() === FALSE) {
			echo __LINE__.":PDOStatement::errorInfo():\n";
			die(print_r($sth->errorInfo(), true));
		}
		$info = $sth->fetchAll();
		$sth->closeCursor();
		$sth = null;
		if (!empty($info)) {
			return $info[0];
		}
		return $info;
	}

	public function queue_copy_from_entry() {
		#$sql = 'INSERT INTO `ps_queue`(`site_id`,`depth`,`uri`,`umd5`,`refer_id`) SELECT 1,depth,uri,umd5,refer FROM `ps_urls`';
		$sql = 'INSERT INTO `ps_queue`(`site_id`,`depth`,`uri`,`umd5`) SELECT `site_id`,`depth`,`entry` as `uri`,md5(`entry`) as umd5 FROM `ps_entry`';
		return $this->db->exec($sql);
	}

	private function queue_insert($rows) {
		$rc = 0;
		foreach($rows as $row) {
			if ($row['depth'] < 0) {
				self::verbose("queueDB skip depth:{$row['depth']} {$row['uri']}");
				continue;
			}
			$page_id = $this->check_need_recrawl($row['uri']);
			if ($page_id !== TRUE) {
				continue;
			}
			$sth = $this->db->prepare('INSERT INTO `ps_queue`(`site_id`,`depth`,`uri`,`refer`,`refer_id`,`umd5`) VALUES(?,?,?,?,?,MD5(`uri`))');
			$sth->bindParam(1, $row['site_id'], PDO::PARAM_INT);
			$sth->bindParam(2, $row['depth'], PDO::PARAM_INT);
			$sth->bindParam(3, $row['uri'], PDO::PARAM_STR);
			$sth->bindParam(4, $row['refer'], PDO::PARAM_STR);
			$sth->bindParam(5, $row['refer_id'], PDO::PARAM_INT);
			#$sth->bindParam(6, $row['uri']);
			$sth->execute();
			$rc1 = $sth->rowCount();
			$sth->closeCursor();
			$sth = null;
			$rc += $rc1;
			if ($rc1) {
				self::verbose("queueDB push depth:{$row['depth']} {$row['uri']}");
			}
		}
		return $rc;
	}
	
	public function entry_insert($site_id, $depth, $entry, $info) {
		$sth = $this->db->prepare('INSERT INTO `ps_entry`(`site_id`,`depth`,`entry`,`info`) VALUES(?,?,?,?)');
		$sth->bindParam(1, $site_id, PDO::PARAM_INT);
		$sth->bindParam(2, $depth, PDO::PARAM_INT);
		$sth->bindParam(3, $entry, PDO::PARAM_STR);
		$sth->bindParam(4, $info, PDO::PARAM_STR);
		$sth->execute();
		$rc = $sth->rowCount();
		$sth->closeCursor();
		$sth = null;
		if ($rc>0) {
			return $this->queue_insert(array(array('site_id'=>$site_id, 'uri'=>$entry, 'refer'=>'', 'depth'=>$depth, 'refer_id'=>0)));
		}
		return 0;
	}
	
	public function setall_entry_depth($depth) {
		return $this->db->exec('UPDATE `ps_entry` set depth='.intval($depth));
	}
	
	private function check_need_recrawl($uri) {
		if (empty($uri)) {
			return FALSE;
		}
		$page_info = $this->getUrlInfo($uri);
		if (empty($page_info)) {
			return TRUE;
		}
		$page_id = $page_info['id'];
		if ($page_info['hours'] > $this->page_expires) {
			return TRUE;
		}
		return $page_id;
	}
	
	private function check_queue() {
		$sql = 'select id,site_id,depth,refer_id,uri,refer from ps_queue order by id asc limit 500';
		do {
			$sth = $this->db->query($sql);
			$rows = $sth->fetchAll();
			$sth->closeCursor();
			$sth = null;
			if (empty($rows)) {
				return FALSE;
			}
			foreach ($rows as $row) {
				if (!isset($this->queue[$row['site_id']])) {
					$this->queue[$row['site_id']] = array('next_time'=>0,'queue'=>array());
				}
				$page_id = $this->check_need_recrawl($row['uri']);
				if (is_numeric($page_id)) {
					$source = $this->getPage($page_id);
					if (!empty($source)) {
						$this->remove_from_queue($row['id']);
						$rc = $this->newUrlAddQueue($row['uri'], $source, $row['depth'], $row['site_id'], $page_id);
						#self::verbose("reparse {$row['id']} {$rc} {$row['uri']}");
					} else {
						$page_id = TRUE;
					}
				}
				if ($page_id === TRUE) {
					if (!isset($this->queue[$row['site_id']]['queue'][$row['id']])) {
						$this->queue[$row['site_id']]['queue'][$row['id']] = $row;
						++$this->queue_len;
						self::verbose("push queue {$row['site_id']} {$row['id']} {$this->queue_len}");
					}
				}
			}
			unset($rows);
		} while ($this->queue_len < 1);
	}
	
	private function remove_from_queue($id) {
		return $this->db->exec('DELETE FROM `ps_queue` where `id`='.intval($id));
	}
	
	private function savePage($uri, $src, $refer_id, $depth) {
		$sth = $this->db->prepare('INSERT INTO `ps_urls`(`uri`, `umd5`, `refer`, `depth`) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), update_time=CURRENT_TIMESTAMP');
		$sth->bindParam(1, $uri);
		$sth->bindParam(2, md5($uri));
		$sth->bindParam(3, $refer_id, PDO::PARAM_INT);
		$sth->bindParam(4, $depth, PDO::PARAM_INT);
		$sth->execute();
		$rc = $sth->rowCount();
		$sth->closeCursor();
		$sth = null;
		if ($rc>0) {
			$id = $this->db->lastInsertId();
			$sth = $this->db->prepare('INSERT INTO `ps_page`(`id`, `smd5`, `source`) VALUES(?,?,?) ON DUPLICATE KEY UPDATE smd5=?, source=?');
			$smd5 = md5($src);
			$sth->bindParam(1, $id);
			$sth->bindParam(2, $smd5);
			$sth->bindParam(3, $src);
			$sth->bindParam(4, $smd5);
			$sth->bindParam(5, $src);
			$sth->execute();
			$sth->closeCursor();
			$sth = null;
			return $id;
		}
		return 0;
	}
	
	public function getPage($page_id) {
		$sth = $this->db->prepare('SELECT `source` FROM `ps_page` WHERE `id`=? LIMIT 1');
		$sth->bindParam(1, $page_id, PDO::PARAM_INT);
		if ($sth->execute() === FALSE) {
			echo __LINE__,':PDOStatement::errorInfo():',chr(10);
			die(print_r($sth->errorInfo(), true));
		}
		$ids = $sth->fetchAll(PDO::FETCH_COLUMN, 0);
		$sth->closeCursor();
		$sth = null;
		if (isset($ids[0])) {
			return $ids[0];
		}
		return '';
	}
	
	public static function parse_url($url, $base_url) {
		$base = parse_url(trim($base_url));
		if (isset($base['scheme']) && stripos($base['scheme'], 'script') != FALSE) {
			$base = FALSE;
		}
		$target = parse_url(trim($url));
		if ($target === FALSE) {
			return FALSE;
		}
		if (isset($target['scheme']) && stripos($target['scheme'], 'script') != FALSE) {
			return FALSE;
		}
		if (!isset($target['host'])) {
			foreach(array('scheme','host','user','pass') as $i) {
				if (isset($base[$i])) {
					$target[$i] = $base[$i];
				}
			}
		}
		/**
		 * 1. /base  /target
		 * 2. /base  target
		 * 3. /base  ../
		 */
		$obj = array();
		if (isset($base['path'][0])) {
			while (strpos($base['path'], '//') !== FALSE) {
				$base['path'] = str_replace('//','/', $base['path']);
			}
			if (!isset($target['path'][0])) {
				$target['path'] = $base['path'];
			} else if ($target['path'][0] != '/') {
				$len = strlen($base['path']);
				if ($base['path'][$len-1] == '/') {
					$target['path'] = $base['path'] . $target['path'];
				} else {
					$target['path'] = dirname($base['path']) .'/'. $target['path'];
				}
			}
		}
		if (!isset($target['path'][0])) {
			$target['path'] = '/';
		}
		$tpath = explode ("/", $target['path']);
		$obj = array();
		$last = array_pop($tpath);
		foreach($tpath as $v) {
			if (empty($v) || $v=='.') {
			} else if ($v == '..') {
				array_pop($obj);
			} else {
				$obj[] = $v;
			}
		}
		$isdir = true;
		if (empty($last) || $last=='.') {
		} else if ($last == '..') {
			array_pop($obj);
		} else {
			$obj[] = $last;
			$isdir = false;
		}
		if ($isdir) {
			if (empty($obj)) {
				$target['path'] = '/';
			} else {
				$target['path'] = '/' . implode ('/', $obj) . '/';
			}
		} else {
			$target['path'] = '/' . implode ('/', $obj);
		}
		return $target;
	}
	
	public static function toUrl($array, $drop_fragment = FALSE) {
		if (!is_array($array)) {
			return FALSE;
		}
		$url = array();
		if (isset($array['scheme'])) { $url[] = $array['scheme']; $url[] = '://'; }
		if (isset($array['user'])) { $url[] = $array['user']; $url[] = ':'; }
		if (isset($array['pass'])) { $url[] = $array['pass']; $url[] = '@'; }
		if (isset($array['host'])) { $url[] = $array['host']; }
		if (isset($array['port'])) { $url[] = ':'; $url[] = $array['port']; }
		if (isset($array['path'])) { $url[] = $array['path']; }
		if (isset($array['query'])) { $url[] = '?'; $url[] = $array['query']; }
		if ($drop_fragment && isset($array['fragment'])) { $url[] = '#'; $url[] = $array['fragment']; }
		return implode($url);
	}
	
	public static function is_same_url($a, $b) {
		if (is_string($a)) {
			$a = $this->parse_url($a, '');
		}
		if (is_string($b)) {
			$b = $this->parse_url($b, '');
		}
		if (is_array($a) && is_array($b)) {
			foreach(array('scheme','host','user','pass','path','query') as $i) {
				if (isset($a[$i]) && isset($b[$i])) {
					if (strcasecmp($a[$i], $b[$i])) {
						return FALSE;
					}
				} else {
					return FALSE;
				}
			}
			return TRUE;
		}
		return FALSE;
	}
	
	public static function parse_web($base, $src) {
		$space = array(' '=>true,"\r"=>true,"\n"=>true,"\t"=>true);
		$queue = array();
		$offset = 0;
		while ($offset !== FALSE && ($offset=strpos($src, '<', $offset)) !== FALSE) {
			++$offset;
			switch($src[$offset]) {
			case '!':
				++$offset;
				if ($src[$offset] == '-' && $src[($offset+1)] == '-') {
					$offset = strpos($src, '-->', $offset+2);
					if ($offset === FALSE) {
						break;
					}
					$offset += 3;
				}
				break;
			case '/':
				++$offset;
				break;
			default:
				while(isset($space[$src[$offset]])){
					++$offset;
				}
				$tag = FALSE;
				switch ($src[$offset]) {
				case 'a':case 'A':
					++$offset;
					if (isset($src[$offset]) && isset($space[$src[$offset]])) {
						$tag = 'a';
					}
					break;
				case 'b':case 'B':
					++$offset;
					if (isset($src[$offset+3]) && isset($space[$src[$offset+3]]) 
					&& ($src[$offset] == 'a' || $src[$offset] == 'A')
					&& ($src[$offset+1] == 's' || $src[$offset+1] == 'S')
					&& ($src[$offset+2] == 'e' || $src[$offset+2] == 'E')) {
						$tag = 'base';
						$offset += 2;
					}
					break;
				default:
					break;
				}
				if ($tag === FALSE) {
					break;
				}
				$begin = $offset;
				$offset = strpos($src, '>', $offset);
				if ($offset === FALSE) {
					break;
				}
				$info = substr($src, $begin, $offset-$begin);
				++$offset;
				
				$begin = stripos($info, 'href');
				if ($begin === FALSE || !isset($space[$info[$begin-1]])) {
					break;
				}
				$begin += 4;
				while (isset($info[$begin]) && (isset($space[$info[$begin]]) || $info[$begin]=='=')) {
					++$begin;
				}
				if (!isset($info[$begin])) {
					break;
				}
				$endchr = $info[$begin];
				if ($endchr == '"' || $endchr == '\'') {
					++$begin;
					$end = $begin;
					while (isset($info[$end]) && $info[$end] != $endchr) ++$end;
					$href = substr($info, $begin, $end-$begin);
				} else {
					$end = $begin;
					while (isset($info[$end]) && !isset($space[$info[$end]])) ++$end;
					$href = substr($info, $begin, $end-$begin);
				}
				if ($tag == 'base') {
					$base = self::toUrl(self::parse_url($href, $base), TRUE);
				} else if ($tag == 'a') {
					$href = self::toUrl(self::parse_url($href, $base), TRUE);
					if ($href != FALSE) {
						$queue[$href] = TRUE;
					}
				}
			}
		}
		return $queue;
	}
	
	private function newUrlAddQueue($uri, $src, $depth, $site_id, $id) {
		if ($depth < 1) {
			return FALSE;
		}
		$urls = self::parse_web($uri, $src);
		if (empty($urls)) {
			return 0;
		}
		$queue = array();
		foreach($urls as $url => $v) {
			if ($this->is_interest_url($site_id, $url)) {
				$queue[] = array('site_id'=>$site_id, 'uri'=>$url, 'refer'=>$uri, 'depth'=>($depth-1), 'refer_id'=>$id);
			}
		}
		if (!empty($queue)) {
			return $this->queue_insert($queue);
		}
		return 0;
	}
	
	public function setCrawlerDelay($delay) {
		$this->crawler_delay = $delay;
	}
	
	public function setConcurrent($max_concurrent) {
		$this->max_concurrent = $max_concurrent;
	}
	
	private function getOneFromQueue() {
		$cur_time = time();
		foreach ($this->queue as &$site) {
			if ($site['next_time'] < $cur_time) {
				$site['next_time'] = $cur_time + $this->crawler_delay;
				$job = array_shift($site['queue']);
				if ($job !== NULL) {
					--$this->queue_len;
				}
				return $job;
			}
		}
		return NULL;
	}

	private function runQueue() {
		$ch = array();
		$jobs = array();

		if ($this->mh === null) {
			$this->mh = curl_multi_init();
		}
		
		$running = true;
		$concurrent = 0;
		do {
			// start a new request
			if (!file_exists("phpspider.stop")) {
				for(; $concurrent < $this->max_concurrent; ++$concurrent){
					$job = $this->getOneFromQueue();
					if ($job === NULL) {
						break;
					}
					//job = array(id,site_id,depth,refer_id,uri,refer)
					$jobs[$job['id']] = $job;
					$ch[$job['id']] = $this->curl_pop($job['site_id']);
					$this->ch_options[CURLOPT_REFERER] = $job['refer'];
					$this->ch_options[CURLOPT_URL] = $job['uri'];
					curl_setopt_array($ch[$job['id']], $this->ch_options);
					curl_multi_add_handle($this->mh, $ch[$job['id']]);
				}
				if ($concurrent < 1) {
					return FALSE;
				}
			}
			
			$mrc = 0;
			$rc = curl_multi_select($this->mh, 1.0);
			if ($rc > -1){
				while (($mrc = curl_multi_exec($this->mh, $running)) == CURLM_CALL_MULTI_PERFORM);
				if ($mrc === CURLM_OK) {
					// a request was just completed -- find out which one
					while (($done = curl_multi_info_read($this->mh))!==FALSE) {
						$id = array_search($done['handle'], $ch);
						$info = curl_getinfo($done['handle']);
						if (intval(intval($info['http_code'])/100) == 2){
							$source = curl_multi_getcontent($done['handle']);
							// request successful. process output using the callback function.
						} else {
							// request failed. 
							$source = '';
						}
						self::verbose("code:{$info['http_code']} url:{$info['url']} total:{$info['total_time']}");
						$page_id = $this->savePage($jobs[$id]['uri'], $source, $jobs[$id]['refer_id'], $jobs[$id]['depth']);
						$this->remove_from_queue($jobs[$id]['id']);
						$this->newUrlAddQueue($jobs[$id]['uri'], $source, $jobs[$id]['depth'], $jobs[$id]['site_id'], $page_id);
						--$concurrent;
						
						//Removes a given ch handle from the given mh handle. 
						curl_multi_remove_handle($this->mh, $done['handle']);
						$this->curl_push($jobs[$id]['site_id'], $done['handle']);
						unset($ch[$id]);
						unset($jobs[$id]);
					}
				}
			}
		} while ($running);
		return TRUE;
	}
	
	public function run() {
		if ($this->queue_len < 1 && $this->init_queue_from_entry) {
			$this->queue_copy_from_entry();
		}
		while (true) {
			if ($this->queue_len < 1) {
				$this->check_queue();
			}
			if ($this->queue_len < 1) {
				if ($this->exit_after_done) {
					self::verbose("Done");
					return;
				}
			}

			$status = $this->runQueue();
			if ($status === FALSE) {
				usleep(10000);
			}
			if (file_exists('phpspider.stop')) {
				unlink('phpspider.stop');
				self::verbose('Exit because phpspider.stop');
				return;
			}
		}
	}
}

