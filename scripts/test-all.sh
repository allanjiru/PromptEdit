
#!/usr/bin/env bash
set -euo pipefail

echo "==> Checking PHP syntax"

find app tests database -name '*.php' -type f -print0 |
    xargs -0 -n1 php -l > /dev/null

echo "==> Running Laravel tests"

php artisan test

echo "==> Checking frontend types"

npm run types:check --if-present

echo "==> Running frontend tests"

npm run test:unit --if-present

echo "==> Building production assets"

npm run build

echo "==> All configured checks passed"
