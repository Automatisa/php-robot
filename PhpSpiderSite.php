<?php
require_once "PhpSpider.php";

class PhpSpiderSite {
	
	public function init_entry() {
		$spider = new PhpSpider;
		$site_id = $spider->site_insert('www.360buy.com', 0, '|^http://www\.360buy\.com/products?/.*\.html$|i');
		echo "site_insert: $site_id \n";
		if ($site_id > 0) {
			$spider->entry_insert($site_id, 2, "http://www.360buy.com/", '');
		}
		unset($spider);
	}

	public function run() {
		$spider = new PhpSpider;
		$spider->setCrawlerDelay(0.5);
		$spider->setConcurrent(5);
		$spider->ShowVerbose(true);
		#$spider->setall_entry_depth(99999999);
		$spider->run();
	}
}

$demo = new PhpSpiderSite;
#$demo->init_entry();
$demo->run();

