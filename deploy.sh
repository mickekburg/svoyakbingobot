#!/usr/bin/env bash
set -euo pipefail
cd /var/www/quizbot

git pull --ff-only
chown -R quizbot: .

run() { sudo -u quizbot "$@"; }

run composer install --no-dev --optimize-autoloader --no-interaction
run php bin/console doctrine:migrations:migrate --no-interaction
run php bin/console cache:clear --env=prod
run php bin/console app:quiz:import

systemctl reload php8.3-fpm
systemctl --no-pager --lines=5 status quizbot