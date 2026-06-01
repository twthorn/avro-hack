FROM hhvm/hhvm:4.153-latest

WORKDIR /app
COPY . /app

CMD ["bash", "-c", "hh_client . --from vim && hhvm lib_test/test.php && hhvm test/test.php && hhvm test/test_resolution.php && hhvm test/test_container.php && hhvm test/test_logical.php && hhvm test/test_fingerprint.php"]
