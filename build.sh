#!/bin/bash
set -e
cd "$(dirname "$0")"

case "${1:-test}" in
  test)
    docker build -q -t avro-hack-test .
    docker run --rm avro-hack-test
    ;;
  clean)
    docker rmi avro-hack-test 2>/dev/null || true
    ;;
  *)
    echo "Usage: $0 {test|clean}"
    ;;
esac
