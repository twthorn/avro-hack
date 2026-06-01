#!/bin/bash
set -e

cd "$(dirname "$0")"

echo "Building avro-hack test container..."
docker build -t avro-hack-test .

echo ""
echo "Running tests..."
docker run --rm avro-hack-test
