<?php
require_once "PhpSpider.php";

class TestPhpSpider {
	
	public static function DEBUG_check_url($expect, $url, $base) {
		$u = PhpSpider::parse_url($url, $base);
		$to = PhpSpider::toUrl($u);
		if (strcmp($expect, $to)) {
			echo "FAIL expect:", $expect, " != ", $to, chr(10);
		} else {
			echo "OK ", $expect, chr(10);
		}
	}

	public static function TEST_check_url() {
		self::DEBUG_check_url("http://www.google.cn/", "../../", "http://www.google.cn");
		self::DEBUG_check_url("http://www.google.cn/test", "test", "http://www.google.cn/");
		self::DEBUG_check_url("http://www.google.cn/test", "../test", "http://www.google.cn/");
		self::DEBUG_check_url("http://www.google.cn/test", "./test", "http://www.google.cn");
		self::DEBUG_check_url("http://www.google.cn/test/test2", "../test/test2", "http://www.google.cn");
		self::DEBUG_check_url("http://www.google.cn/test/test2/test3/", "test/test2/test3////", "http://www.google.cn");
		self::DEBUG_check_url("http://www.google.cn/test/", "test/test2/test3/../../", "http://www.google.cn");
		self::DEBUG_check_url("http://www.google.cn/test/test2", "/test/test2", "http://www.google.cn/test2/");
		self::DEBUG_check_url("http://www.google.cn/test/test2", "/test////test2", "http://www.google.cn///test2/");
		self::DEBUG_check_url("http://www.google.cn/test/test2", "/test////test2", "http://www.google.cn////test2/");
		self::DEBUG_check_url("http://www.google.cn/test/test2", "/test/test2", "http://www.google.cn/test2////");
		self::DEBUG_check_url('http://www.360buy.com/products/737-794-798-0-0-0-0-0-0-0-1-1-3.html', '737-794-798-0-0-0-0-0-0-0-1-1-3.html', 'http://www.360buy.com/products/737-794-798.html');
	}

	public static function DEBUG_url_match($line, $expect) {
		if (preg_match('#^<\s*(a|base)\s+[^>]*href\s*=\s*("|\')(.+)\2#iUs', $line, $m)) {
			if (strcmp($expect, $m[3])) {
				echo "1FAIL expect:", $expect, " != ", $m[3], chr(10), $line, chr(10);
			} else {
				echo "1OK ", $expect, chr(10);
			}
		} else if (preg_match('#^<\s*(a|base)\s+[^>]*href\s*=\s*([^\s|>]+)[\s|>]{1}#iUs', $line, $m)) {
			if (strcmp($expect, $m[2])) {
				echo "2FAIL expect:", $expect, " != ", $m[2], chr(10), $line, chr(10);
			} else {
				echo "2OK ", $expect, chr(10);
			}
		}
	}

	public static function TEST_url_match() {
		self::DEBUG_url_match("<a href='http://www.google.com/'>", 'http://www.google.com/');
		self::DEBUG_url_match("<a href= http://www.google.com/ title='google'>", 'http://www.google.com/');
		self::DEBUG_url_match("<a href=http://www.google.com/ title='google'>", 'http://www.google.com/');
		self::DEBUG_url_match("<a href=http://www.google.com/test test title='google'>", 'http://www.google.com/test');
		self::DEBUG_url_match("<a href=http://www.google.com/>", 'http://www.google.com/');
		self::DEBUG_url_match("<a href= http://www.google.com/>", 'http://www.google.com/');
	}
	
	public static function TEST_parse_web() {
		$web = file_get_contents('test.html');
		$urls = PhpSpider::parse_web('http://www.360buy.com/products/737-794-798.html', $web);
		foreach($urls as $url=>$v) {
			echo $url,chr(10);
		}
	}

	public static function TEST() {
		self::TEST_url_match();
		self::TEST_check_url();
		self::TEST_parse_web();
	}

}

$str = "/hello/";
echo $str[-1], chr(10);
TestPhpSpider::TEST();
