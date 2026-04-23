# Arguments
PHPSTAN_ARGS ?= -v
PACKAGE ?=
DOCKER_EXEC_FLAGS ?= -it

# Targets
composer-install:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev composer install
composer-update:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev composer update
composer-require:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev composer require $(PACKAGE)
composer-require-dev:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev composer require --dev $(PACKAGE)
phpstan:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev vendor/bin/phpstan analyse $(PHPSTAN_ARGS)
cs-fix:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev vendor/friendsofphp/php-cs-fixer/php-cs-fixer fix --config=.php-cs-fixer.dist.php
cs-check:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev vendor/friendsofphp/php-cs-fixer/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
phpunit:
	docker exec $(DOCKER_EXEC_FLAGS) vici-php-dev vendor/bin/phpunit --testdox
up:
	docker compose up -d --build
down:
	docker compose down
restart:
	docker compose down
	docker compose up -d
enter:
	docker exec -it vici-php-dev bash

# Alias or shorthand
ci: composer-install phpstan cs-check
pre-commit: cs-fix phpstan phpunit
install: composer-install
test: phpunit
