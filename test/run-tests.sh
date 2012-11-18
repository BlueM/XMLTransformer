#!/bin/bash

DIR=$(dirname "$0")
phpunit --testdox "$DIR/XMLTransformerTest.php"
