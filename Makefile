# Usage:
#   make test                      — run tests (PHP 8.2 default)
#   PHP_VERSION=8.3 make build test — rebuild for PHP 8.3 and test
#   make shell                     — interactive shell in container
#   make install                   — install Composer dependencies

.PHONY: test install update shell build clean

test:
	docker compose run --rm php vendor/bin/phpunit

install:
	docker compose run --rm php composer install

update:
	docker compose run --rm php composer update

shell:
	docker compose run --rm php sh

build:
	docker compose build --no-cache

clean:
	docker compose down -v --rmi local
