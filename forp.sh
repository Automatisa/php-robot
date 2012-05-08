#!/bin/sh

while [ 1 ]
do
	#ps -fC php | grep -c PhpRobot.php
	php forParser.php
	sleep 500;
done
