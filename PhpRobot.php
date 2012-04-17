<?php
/*

Copyright (c) <2012>, <DJ Xu   hsujian@qq.com>
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/
class PhpRobot {
	private $db;
	private $config;
	private $site_config;
	private $queue;
	private $queue_len;
	private $mh;
	private $ch_options;
	static private $show_verbose;
	private $curl_pool;
	private $cur_time;
	
	public function __construct($configfile = 'site.config') {
		#xhprof_enable(/*XHPROF_FLAGS_CPU +*/ XHPROF_FLAGS_MEMORY);
		$this->cur_time = 0;
		$this->queue = array();
		$this->curl_pool = array();
		$this->queue_len = 0;
		
		$this->mh = null;
		$this->config = null;
		$this->site_config = null;
		$this->init_from_config ($configfile);
	}
	
	private function init_from_config ($configfile = 'site.config') {
		if (!file_exists($configfile)) {
			echo __LINE__, ' config file no exists: ', $configfile;
			die (chr(10));
		}
		$config_str = file_get_contents ($configfile);
		if (strncmp($config_str, "\xEF\xBB\xBF", 3) === 0) {
			$config_str = substr($config_str, 3);
		}
		
		$this->config = json_decode ($config_str);
		if (!is_object($this->config)) {
			echo __LINE__,' config format error: ', $configfile, chr(10);
			if (function_exists('json_last_error')) {
				switch(json_last_error()) {
					case JSON_ERROR_DEPTH:
						echo ' - Maximum stack depth exceeded';
					break;
					case JSON_ERROR_CTRL_CHAR:
						echo ' - Unexpected control character found';
					break;
					case JSON_ERROR_SYNTAX:
						echo ' - Syntax error, malformed JSON';
					break;
					case JSON_ERROR_NONE:
						echo ' - No errors';
					break;
				}
			}
			die (chr(10));
		}
		$config_str = null;
		self::$show_verbose = ((isset($this->config->verbose) ? $this->config->verbose : FALSE) & TRUE);
		
		if (!isset($this->config->crawler_delay)) {
			$this->config->crawler_delay = 10;
		}
		$this->config->crawler_delay -= 0.01;
		echo 'default crawler_delay: ', $this->config->crawler_delay, chr(10);
		
		if (!isset($this->config->max_concurrent)) {
			$this->config->max_concurrent = 10;
		}
		echo 'max_concurrent: ', $this->config->max_concurrent, chr(10);
		
		if (!isset($this->config->page_expires)) {
			$this->config->page_expires = 999;
		}
		echo 'page_expires: ', $this->config->page_expires, chr(10);
		
		if (!isset($this->config->recrawl_sleep)) {
			$this->config->recrawl_sleep = 3600;
		}
		echo 'recrawl_sleep: ', $this->config->recrawl_sleep, chr(10);
		
		if (!isset($this->config->exit_after_done)) {
			$this->config->exit_after_done = TRUE;
		}
		echo 'exit_after_done: ', $this->config->exit_after_done, chr(10);
		
		foreach ($this->config->site as $domain=>$info) {
			echo 'domain: ', $domain, chr(10);
			if (!isset($info->site_id)) {
				echo __LINE__, ' Config site[', $domain, '] no site id';
				die (chr(10));
			}
			$id = $info->site_id;
			if (isset($this->site_config[$id])) {
				echo __LINE__, ' Site ID:', $id, ' existe, domain: ', $domain;
				die (chr(10));
			}
			if (!isset($info->info_re)) {
				echo __LINE__, ' NO Info re, domain: ', $domain;
				die (chr(10));
			}
			if (!isset($info->crawler_delay)) {
				$info->crawler_delay = $this->config->crawler_delay;
			}
			echo 'crawler_delay: ', $info->crawler_delay, chr(10);
			foreach ($info->info_re as $re) {
				echo 'info_re: ', $re, chr(10);
			}
			if (isset($info->list_re)) {
				foreach ($info->list_re as $re) {
					echo 'list_re: ', $re, chr(10);
				}
			} else {
				if (!isset($info->domain_limit)) {
					echo __LINE__, ' Need domain_limit, domain: ', $domain;
					die (chr(10));
				}
			}
			$this->site_config[$id] = $info;
		}
		
		if (!isset($this->config->useragent)) {
			$this->config->useragent = "Robot(hsujian@qq.com)";
		}
		echo 'useragent: ', $this->config->useragent, chr(10);
		$this->ch_options = array(
			CURLOPT_HTTPHEADER=>array('Accept-Encoding: gzip,deflate'),
			CURLOPT_ENCODING=>TRUE,
			CURLOPT_HEADER=>FALSE,
			CURLOPT_RETURNTRANSFER=>TRUE,
			CURLOPT_FOLLOWLOCATION=>FALSE,
			CURLOPT_USERAGENT=>$this->config->useragent
		);
		
		if (!isset($this->config->db_dsn) || !isset($this->config->db_username) || !isset($this->config->db_password)) {
			echo 'undefine db info';
			die (chr(10));
		}
		try {
			$this->db = new PDO($this->config->db_dsn, $this->config->db_username, $this->config->db_password, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES GBK"));
		} catch (PDOException $e) {
			echo 'Connection failed: ', $e->getMessage();
			die (chr(10));
		}
		$this->check_database();
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
		$this->config = null;
		#file_put_contents('profile.txt', print_r(xhprof_disable(), true));
	}
	
	private function check_database() {
		$sqls = array();
		$sql = <<<____SQL
CREATE TABLE IF NOT EXISTS `ps_urls` (
 `id` INT NOT NULL AUTO_INCREMENT,
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
 `umd5` varchar(32) NOT NULL,
 `uri` varchar(512) NOT NULL,
 `refer` varchar(512) NOT NULL,
 PRIMARY KEY (`id`),
 unique key (`umd5`)
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
	
	static public function urlDiffCount($strA, $strB, $separator='/.-_?&=#') {
		$bstrA = $strA;
		$bstrB = $strB;
		$lenA = strlen($strA);
		$lenB = strlen($strB);
		if ($lenA == 0 && $lenB == 0) return 0;
		if ($lenA == 0) return 1;
		if ($lenB == 0) return 1;
		
		if (!empty($separator)) {
			if (is_string($separator)) {
				$sepArray=array();
				for ($i=strlen($separator); $i--;) {
					$sepArray[$separator[$i]] = TRUE;
				}
				$separator = $sepArray;
			}
			$strAA = array();
			$j = 0;
			for ($i=0; $i<$lenA; ++$i) {
				if (isset($separator[$strA[$i]])) {
					if (isset($strAA[$j])) {
						$strAA[$j] = implode ($strAA[$j]);
						++$j;
					}
					$strAA[$j] = $strA[$i];
					++$j;
				} else {
					$strAA[$j][] = $strA[$i];
				}
			}
			if (isset($strAA[$j]) && is_array($strAA[$j])) {
				$strAA[$j] = implode ($strAA[$j]);
			}
			$strA = $strAA;
			$lenA = count($strA);
			
			$strAA = array();
			$j = 0;
			for ($i=0; $i<$lenB; ++$i) {
				if (isset($separator[$strB[$i]])) {
					if (isset($strAA[$j])) {
						$strAA[$j] = implode ($strAA[$j]);
						++$j;
					}
					$strAA[$j] = $strB[$i];
					++$j;
				} else {
					$strAA[$j][] = $strB[$i];
				}
			}
			if (isset($strAA[$j]) && is_array($strAA[$j])) {
				$strAA[$j] = implode ($strAA[$j]);
			}
			$strB = $strAA;
			$lenB = count($strB);
		}
		
		for($i = 0; $i < $lenA; ++$i) $c[$i][$lenB] = $lenA - $i;
		for($j = 0; $j < $lenB; ++$j) $c[$lenA][$j] = $lenB - $j;
		$c[$lenA][$lenB] = 0;
		for($i = $lenA; $i--;)
			for($j = $lenB; $j--;)
			{
				if($strB[$j] == $strA[$i])
					$c[$i][$j] = $c[$i+1][$j+1];
				else {
					#$c[$i][$j] = minValue($c[$i][$j+1], $c[$i+1][$j], $c[$i+1][$j+1]) + 1;
					if($c[$i][$j+1] < $c[$i+1][$j] && $c[$i][$j+1] < $c[$i+1][$j+1]) $c[$i][$j] = $c[$i][$j+1];
					else if($c[$i+1][$j] < $c[$i][$j+1] && $c[$i+1][$j] < $c[$i+1][$j+1]) $c[$i][$j] = $c[$i+1][$j];
					else $c[$i][$j] = $c[$i+1][$j+1];
					++$c[$i][$j];
				}
			}
		
		return $c[0][0];
	}
	
	private function is_info_url($site_id, $url) {
		if (isset($this->site_config[$site_id]->info_re)) {
			foreach($this->site_config[$site_id]->info_re as $re) {
				if (preg_match($re, $url)) {
					return TRUE;
				}
			}
		}
		return FALSE;
	}
	
	private function is_list_url($site_id, $url) {
		if (isset($this->site_config[$site_id])) {
			if (isset($this->site_config[$site_id]->list_re)) {
				foreach($this->site_config[$site_id]->list_re as $re) {
					if (preg_match($re, $url)) {
						return TRUE;
					}
				}
			} else if (isset($this->site_config[$site_id]->domain_limit)) {
				foreach($this->site_config[$site_id]->domain_limit as $domain) {
					if (strpos($url, $domain) != FALSE) {
						return TRUE;
					}
				}
			}
		}
		return FALSE;
	}
	
	private function list_filter($list, $site_id, $refer_url) {
		if (empty($list)) {
			return NULL;
		}
		if (isset($this->site_config[$site_id]) && !isset($this->site_config[$site_id]->list_re)) {
			$c = count($list);
			$min = 10;
			for ($i = 0; $i < $c; ++$i) {
				$list[$i]['diff'] = self::urlDiffCount ($refer_url, $list[$i]['uri']);
				if ($list[$i]['diff'] < $min) {
					$min = $list[$i]['diff'];
				}
			}
			for ($i = 0; $i < $c; ++$i) {
				if ($list[$i]['diff'] > $min) {
					unset($list[$i]);
				}
			}
			return $list;
		}
		return false;
	}
	
	public function getUrlInfo($uri) {
		$sth = $this->db->prepare('SELECT id,TIMESTAMPDIFF(HOUR,update_time,CURRENT_TIMESTAMP) as hours FROM `ps_urls` WHERE `umd5`=MD5(?) LIMIT 1');
		$sth->bindParam(1, $uri);
		if ($sth->execute() === FALSE) {
			echo __LINE__,':PDOStatement::errorInfo():',chr(10);
			die(print_r($sth->errorInfo(), true));
		}
		$info = $sth->fetchAll(PDO::FETCH_ASSOC);
		$sth = null;
		if (!empty($info)) {
			return $info[0];
		}
		return $info;
	}

	public function queue_copy_from_entry() {
		$entry = array();
		foreach ($this->config->site as $name=>$info) {
			if (!isset($info->site_id)) {
				die (__LINE__ .' Config site['.$name.'] no site id');
			}
			if (isset($info->stop) && $info->stop) {
				continue;
			}
			$depth = (isset($info->depth) ? $info->depth : 2);
			if (isset($info->entry)) foreach ($info->entry as $url) {
				$entry[] = array('site_id'=>$info->site_id, 'uri'=>$url, 'refer'=>'', 'depth'=>$depth);
			}
		}
		return $this->queue_insert($entry);
	}

	private function queue_insert($rows) {
		$rc = 0;
		$sth = $this->db->prepare('INSERT INTO `ps_queue`(`site_id`,`depth`,`uri`,`refer`,`umd5`) VALUES(?,?,?,?,MD5(`uri`))');
		foreach($rows as $row) {
			if ($row['depth'] < 0) {
				self::verbose("queueDB skip depth:{$row['depth']} {$row['uri']}");
				continue;
			}
			$page_id = $this->check_need_recrawl($row['uri']);
			if ($page_id !== TRUE) {
				continue;
			}
			
			$sth->bindParam(1, $row['site_id'], PDO::PARAM_INT);
			$sth->bindParam(2, $row['depth'], PDO::PARAM_INT);
			$sth->bindParam(3, $row['uri'], PDO::PARAM_STR);
			$sth->bindParam(4, $row['refer'], PDO::PARAM_STR);
			$sth->execute();
			$rc1 = $sth->rowCount();
			$sth->closeCursor();
			$rc += $rc1;
			//if ($rc1) { self::verbose("queueDB push depth:{$row['depth']} {$row['uri']}"); }
		}
		$sth = null;
		return $rc;
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
		if ($page_info['hours'] > $this->config->page_expires) {
			return TRUE;
		}
		return $page_id;
	}
	
	private function check_queue($site_id=FALSE) {
		if (file_exists('spider.stop')) {
			return;
		}
		foreach ($this->config->site as $name=>$info) {
			if (isset($info->stop) && $info->stop) {
				continue;
			}
			if (!isset($info->site_id)) {
				continue;
			}
			if ($site_id != FALSE && $info->site_id != $site_id) {
				continue;
			}
			$base_id = 0;
			$qlen = 0;
			do {
				$sql = 'select id,site_id,depth,uri,refer from ps_queue where id>'.$base_id.' and site_id='.$info->site_id.' order by id asc limit 50';
				$rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
				if (empty($rows)) {
					break;
				}
				foreach ($rows as $row) {
					$page_id = $this->check_need_recrawl($row['uri']);
					if (is_numeric($page_id)) {
						$source = $this->getPage($page_id);
						if (!empty($source)) {
							$this->remove_from_queue($row['id']);
							$rc = $this->newUrlAddQueue($row['uri'], $source, $row['depth'], $row['site_id']);
						} else {
							$page_id = TRUE;
						}
					}
					if ($page_id === TRUE) {
						if (!isset($this->queue[$row['site_id']])) {
							$this->queue[$row['site_id']] = array('next_time'=>0,'queue'=>array());
						}
						if (!isset($this->queue[$row['site_id']]['queue'][$row['id']])) {
							$this->queue[$row['site_id']]['queue'][$row['id']] = $row;
							++$this->queue_len;
							++$qlen;
						}
					}
					$base_id = $row['id'];
				}
				unset($rows);
			} while ($qlen < 1);
		}
	}
	
	private function remove_from_queue($id) {
		return $this->db->exec('DELETE FROM `ps_queue` where `id`='.intval($id));
	}
	
	private function savePage($uri, $src) {
		$sth = $this->db->prepare('INSERT INTO `ps_urls`(`uri`, `umd5`) VALUES(?,MD5(uri)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), update_time=CURRENT_TIMESTAMP');
		$sth->bindParam(1, $uri);
		$sth->execute();
		$rc = $sth->rowCount();
		$sth = null;
		if ($rc>0) {
			$id = $this->db->lastInsertId();
			$sth = $this->db->prepare('INSERT INTO `ps_page`(`id`,`source`,`smd5`) VALUES(?,?,MD5(source)) ON DUPLICATE KEY UPDATE source=VALUES(source), smd5=VALUES(smd5)');
			$sth->bindParam(1, $id);
			$sth->bindParam(2, $src);
			$sth->execute();
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
		$sth = null;
		if (isset($ids[0])) {
			return $ids[0];
		}
		return '';
	}
	
	public static function parse_url($url, $base_url) {
		if (!isset($url[8])) {
			return FALSE;
		}
		$target = parse_url(trim($url));
		if ($target === FALSE) {
			return FALSE;
		}
		if (isset($target['scheme']) && stripos($target['scheme'], 'script') != FALSE) {
			return FALSE;
		}
		
		$base = parse_url(trim($base_url));
		if (isset($base['scheme']) && stripos($base['scheme'], 'script') != FALSE) {
			$base = FALSE;
		}
		
		if (!isset($target['host'])) {
			static $need_reset = array('scheme','host','user','pass');
			foreach($need_reset as $i) {
				if (isset($base[$i])) {
					$target[$i] = $base[$i];
				}
			}
		} else if (!isset($target['path'][0])) {
			$target['path'] = '/';/* if target has host but don't have path, set it '/' */
		}
		
		/**
		 * 1. /base  /target
		 * 2. /base  target
		 * 3. /base  ../
		 */
		 
		if (isset($base['path'][0])) {
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
		/* if target url don't have path, set it '/' */
		if (!isset($target['path'][0])) {
			$target['path'] = '/';
		} else {
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
		}
		
		static $may_need_reset = array('query','fragment');
		foreach ($may_need_reset as $i) {
			if (isset($base[$i]) && !isset($target[$i])) {
				$target[$i] = $base[$i];
			}
		}
		return $target;
	}
	
	public static function toUrl($array, $drop_query = FALSE, $drop_fragment = FALSE) {
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
		if (!$drop_query && isset($array['query'])) { $url[] = '?'; $url[] = $array['query']; }
		if (!$drop_fragment && isset($array['fragment'])) { $url[] = '#'; $url[] = $array['fragment']; }
		return implode($url);
	}
	
	public static function parse_web($base, $src, $drop_query = FALSE) {
		static $space = array(' '=>true,"\r"=>true,"\n"=>true,"\t"=>true);
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
					$base = self::toUrl(self::parse_url($href, $base), $drop_query, TRUE);
				} else if ($tag == 'a') {
					$href = self::toUrl(self::parse_url($href, $base), $drop_query, TRUE);
					if ($href != FALSE) {
						$queue[$href] = TRUE;
					}
				}
			}
		}
		return $queue;
	}
	
	private function newUrlAddQueue($uri, $src, $depth, $site_id) {
		if ($depth < 1) {
			return FALSE;
		}
		$drop_query = FALSE;
		if (isset($this->site_config[$site_id]->drop_query) && $this->site_config[$site_id]->drop_query) {
			$drop_query = TRUE;
		}
		$queue = array();
		$list = array();
		$urls = self::parse_web($uri, $src, $drop_query);
		if (empty($urls)) {
			return 0;
		}
		if ($this->is_info_url($site_id, $uri)) {
			foreach($urls as $url => $v) {
				if ($this->is_info_url($site_id, $url)) {
					$queue[] = array('site_id'=>$site_id, 'uri'=>$url, 'refer'=>$uri, 'depth'=>($depth-1));
				}
			}
		} else {
			foreach($urls as $url => $v) {
				if ($this->is_info_url($site_id, $url)) {
					$queue[] = array('site_id'=>$site_id, 'uri'=>$url, 'refer'=>$uri, 'depth'=>($depth-1));
				} else if ($this->is_list_url($site_id, $url)) {
					$list[] = array('site_id'=>$site_id, 'uri'=>$url, 'refer'=>$uri, 'depth'=>($depth-1));
				}
			}
			$flist = $this->list_filter($list, $site_id, $uri);
			if ($flist!= false) {
				$list = $flist;
				$flist = null;
			}
		}

		$num = $this->queue_insert($queue);
		if ($num == 0) {
			foreach ($list as &$u) {
				$u['depth'] = intval($u['depth']/10);
			}
		}
		$num += $this->queue_insert($list);
		return $num;
	}
	
	private function getOneFromQueue() {
		$cur_time = $this->cur_time;
		foreach ($this->queue as $site_id=>$site) {
			if (empty($site['queue'])) {
				$this->check_queue($site_id);
			}
			if ($site['next_time'] < $cur_time) {
				$this->queue[$site_id]['next_time'] = $cur_time + $this->site_config[$site_id]->crawler_delay;
				$job = array_shift($this->queue[$site_id]['queue']);
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
		
		$running = 0;
		$concurrent = 0;
		$mrc = 0;
		do {
			// start a new request
			if ($concurrent < $this->config->max_concurrent) {
				$this->cur_time = time();
				for(; $concurrent < $this->config->max_concurrent; ++$concurrent){
					$job = $this->getOneFromQueue();
					if ($job === NULL) {
						break;
					}
					//job = array(id,site_id,depth,uri,refer)
					$jobs[$job['id']] = $job;
					$ch[$job['id']] = $this->curl_pop($job['site_id']);
					$this->ch_options[CURLOPT_REFERER] = $job['refer'];
					$this->ch_options[CURLOPT_URL] = $job['uri'];
					curl_setopt_array($ch[$job['id']], $this->ch_options);
					curl_multi_add_handle($this->mh, $ch[$job['id']]);
				}
			}
			if ($concurrent < 1) {
				return FALSE;
			}
			
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
					self::verbose("code:{$info['http_code']} total:{$info['total_time']} {$info['url']}");
					$page_id = $this->savePage($jobs[$id]['uri'], $source);
					$this->remove_from_queue($jobs[$id]['id']);
					$this->newUrlAddQueue($jobs[$id]['uri'], $source, $jobs[$id]['depth'], $jobs[$id]['site_id']);
					--$concurrent;

					//Removes a given ch handle from the given mh handle. 
					curl_multi_remove_handle($this->mh, $done['handle']);
					$this->curl_push($jobs[$id]['site_id'], $done['handle']);
					unset($ch[$id]);
					unset($jobs[$id]);
				}
				if ($running && $concurrent) {
					if ($concurrent >= $this->config->max_concurrent) {
						while (curl_multi_select($this->mh, 60) == 0);
					}
				}
			}
		} while (TRUE);
		return TRUE;
	}
	
	public function run() {
		if ($this->queue_len < 1) {
			$this->queue_copy_from_entry();
		}
		while (true) {
			if ($this->queue_len < 1) {
				$this->check_queue();
			}
			if ($this->queue_len < 1) {
				if ($this->config->exit_after_done) {
					self::verbose("Done");
					return;
				} else {
					sleep($this->config->recrawl_sleep);
					$this->queue_copy_from_entry();
				}
			}

			$status = $this->runQueue();
			if ($status === FALSE) {
				usleep(100000);
			}
			if (file_exists('spider.stop')) {
				unlink('spider.stop');
				self::verbose('Exit because spider.stop');
				return;
			}
		}
	}
	
}

if (isset($argv[1]) && $argv[1] == 'standalone') {
	$spider = new PhpRobot;
	$spider->run();
}