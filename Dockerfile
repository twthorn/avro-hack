FROM hhvm/hhvm:4.153-latest

WORKDIR /app
COPY . /app

CMD ["bash", "-c", "hh_client . --from vim && hhvm tests/AvroTest.hack"]
