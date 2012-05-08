#!/bin/sh

while [ 1 ]
do
	#ps -fC php | grep -c PhpRobot.php
	php PhpRobot.php standalone >spider.log
	sleep 500;
done
