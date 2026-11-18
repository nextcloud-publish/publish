#!/bin/sh
set -e

# Only before php-fpm, so `docker compose run publish php bin/console …` does not migrate first.
# A failed migration stops the container before it serves a request.
if [ "$1" = php-fpm85 ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec "$@"
