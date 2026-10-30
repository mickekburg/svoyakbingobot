#!/bin/sh
# Container start: bring the database up to date, load the themes, then run the command (the bot by default).
set -e
cd /app

if [ -z "$SKIP_BOOT" ]; then
    echo "[quizbot] applying database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

    echo "[quizbot] importing themes from data/themes..."
    # An import is all-or-nothing, so a broken xlsx leaves the previous data untouched: do not stop the bot for it
    php bin/console app:quiz:import \
        || echo "[quizbot] WARNING: theme import failed (see the messages above); continuing with the data already in the database"
fi

exec "$@"
