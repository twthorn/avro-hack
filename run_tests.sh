#!/bin/bash
set -e

cd "$(dirname "$0")"

echo "Running Avro-Hack tests..."
hhvm test/test.php
