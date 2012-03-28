<?php
require_once "PhpSpider.php";

class PhpSpiderSite {
	
	public function init_360buy() {
		$spider = new PhpSpider;
		$site_id = $spider->site_insert('www.360buy.com', 0, '#^http\://www\.360buy\.com/products?/.*\.html$#i');
		echo "site_insert: $site_id \n";
		if ($site_id > 0) {
			$handle = fopen("../Config/categories_360buy.txt", "r");
			if ($handle !== false) {
				while (!feof($handle)) {
					$line = trim(fgets($handle, 4096));
					if (empty($line)) {
						continue;
					}
					if ($line{0} == '#') {
						continue;
					}
					$items = explode(",", $line);
					if (count($items) != 5) {
						continue;
					}
					list ($id, $topcat, $cat, $subcat, $url) = $items;
					$info = $topcat . ','. $cat . ',' . $subcat;
					$rv = $spider->entry_insert($site_id, 2, $url, $info);
					if ($rv) {
						echo "entry insert $rv $url $info \n";
					}
				}
				fclose($handle);
			}
		}
		unset($spider);
	}

	public function run() {
		$spider = new PhpSpider;
		$spider->setCrawlerDelay(0);
		$spider->setConcurrent(10);
		$spider->ShowVerbose(true);
		#$spider->setall_entry_depth(99999999);
		$spider->run();
	}
}

$demo = new PhpSpiderSite;
#$demo->init_360buy();
$demo->run();

