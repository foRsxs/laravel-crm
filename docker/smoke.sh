#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
: "${APP_IMAGE:?Build the app image first}"
: "${WEB_IMAGE:?Build the web image first}"
project="krayin-smoke-$$"
scratch=$(mktemp -d "$PWD/.docker-smoke.XXXXXX")
export APP_ENV_FILE="$scratch/app.env"
export COMPOSE_PROJECT_NAME="$project"
export HTTP_PORT=${SMOKE_PORT:-18081}
key=$(openssl rand -base64 32)
export DB_ROOT_PASSWORD_FILE=${SMOKE_ROOT_PASSWORD_FILE:-"$scratch/root-password"}
if [[ -n "${SMOKE_ROOT_PASSWORD_FILE:-}" ]]; then
    test -r "$DB_ROOT_PASSWORD_FILE"
else
    openssl rand -hex 32 > "$DB_ROOT_PASSWORD_FILE"
fi
cat > "$APP_ENV_FILE" <<EOF
APP_NAME="Krayin smoke"
APP_URL=http://127.0.0.1:$HTTP_PORT
APP_KEY=base64:$key
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_CURRENCY=USD
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=krayin_smoke
DB_USERNAME=krayin
DB_PASSWORD=smoke-only-password
QUEUE_CONNECTION=database
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=false
MAIL_MAILER=array
LOG_CHANNEL=stderr
GREEN_API_ENABLED=false
EOF
dc() { docker compose --env-file "$APP_ENV_FILE" -f compose.prod.yaml "$@"; }
cleanup() {
    code=$?
    if (( code != 0 )); then dc logs --tail 80 app web worker >&2 || true; fi
    dc down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$scratch"
    exit "$code"
}
trap cleanup EXIT
dc config --quiet
dc up -d --wait --wait-timeout 180 db
if dc run --rm --no-deps -e APP_KEY=invalid -e ADMIN_EMAIL=admin@example.invalid -e ADMIN_PASSWORD=smoke-only-admin-password app php docker/install.php; then
    echo 'Installer unexpectedly accepted an invalid application key.' >&2
    exit 1
fi
dc run --rm --no-deps -e ADMIN_EMAIL=admin@example.invalid -e ADMIN_PASSWORD=smoke-only-admin-password app php docker/install.php
dc run --rm --no-deps app sh -c 'test ! -w composer.json && test ! -w public && test -w storage && test -w bootstrap/cache'
dc run --rm --no-deps app php -r 'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!Illuminate\Support\Facades\Hash::check("smoke-only-admin-password",Webkul\User\Models\User::findOrFail(1)->password)||config("app.key")!==getenv("APP_KEY")){throw new RuntimeException("Administrator/key initialization failed");}'
if dc run --rm --no-deps -e ADMIN_EMAIL=admin@example.invalid -e ADMIN_PASSWORD=smoke-only-admin-password app php docker/install.php; then
    echo 'Installer unexpectedly accepted an existing database.' >&2
    exit 1
fi
dc up -d --wait --wait-timeout 180 app web worker scheduler
curl --fail --silent "http://127.0.0.1:$HTTP_PORT/up" > /dev/null
curl --fail --silent "http://127.0.0.1:$HTTP_PORT/admin/login" > "$scratch/login.html"
grep -qi 'password' "$scratch/login.html"
curl --fail --silent -H 'Host: crm.example.invalid' -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$HTTP_PORT/admin/login" > "$scratch/https.html"
grep -q 'https://crm.example.invalid/' "$scratch/https.html"
asset=$(dc exec -T app php -r '$m=json_decode(file_get_contents("public/admin/build/manifest.json"),true);foreach($m as $v){if(str_ends_with($v["file"],".css")){echo $v["file"];break;}}')
test -n "$asset"
curl --fail --silent "http://127.0.0.1:$HTTP_PORT/admin/build/$asset" > /dev/null
dc exec -T app sh -c 'printf retained > storage/app/public/smoke.txt; printf secret > storage/app/private-smoke.txt; printf "<?php echo 1;" > storage/app/public/smoke.php'
[[ $(curl --fail --silent "http://127.0.0.1:$HTTP_PORT/storage/smoke.txt") == retained ]]
[[ $(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$HTTP_PORT/storage/smoke.php") == 404 ]]
[[ $(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:$HTTP_PORT/storage/private-smoke.txt") == 404 ]]
dc exec -T app php -r 'require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$e=Webkul\GreenApi\Models\WebhookEvent::create(["event_key"=>hash("sha256","docker-smoke"),"instance_id"=>"7103000000","external_id"=>"docker-smoke","type"=>"incomingMessageReceived","payload"=>["instanceData"=>["idInstance"=>7103000000],"idMessage"=>"docker-smoke","senderData"=>["chatId"=>"77010009999@c.us"],"messageData"=>["typeMessage"=>"textMessage","textMessageData"=>["textMessage"=>"Docker queue check"]]]]);Illuminate\Support\Facades\Queue::push(new Webkul\GreenApi\Jobs\ProcessWebhook($e->id));'
# The separate worker must consume the real DB job and persist its message.
for attempt in $(seq 1 45); do
    count=$(dc exec -T app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php";$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();echo Webkul\GreenApi\Models\Message::where("external_id","docker-smoke")->count();')
    [[ "$count" == 1 ]] && break
    sleep 2
done
[[ "$count" == 1 ]]
dc exec -T app php -r 'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();Webkul\GreenApi\Models\WebhookEvent::where("event_key",hash("sha256","docker-smoke"))->delete();Webkul\GreenApi\Models\Conversation::where("chat_id","77010009999@c.us")->delete();'
printf 'APP_IMAGE=%s\nWEB_IMAGE=%s\n' "$APP_IMAGE" "$WEB_IMAGE" > "$scratch/release.env"
dc exec -T app php artisan down
dc stop worker scheduler app
bash docker/backup.sh "$scratch/release.env" "$scratch/backup"
test -s "$scratch/backup/database.sql"
gzip -t "$scratch/backup/storage.tar.gz"
cmp "$APP_ENV_FILE" "$scratch/backup/app.env"
dc up -d --force-recreate --wait --wait-timeout 180 app web
dc exec -T app php artisan up
[[ $(curl --fail --silent "http://127.0.0.1:$HTTP_PORT/storage/smoke.txt") == retained ]]
if [[ -n "${TEST_IMAGE:-}" ]]; then
    docker run --rm --network "${project}_default" --volume "${project}_storage:/var/www/html/storage" --env-file "$APP_ENV_FILE" -e APP_ENV=testing -e QUEUE_CONNECTION=sync "$TEST_IMAGE" php artisan test --compact --do-not-cache-result --cache-directory=storage/framework/testing/phpunit --display-warnings --fail-on-warning tests/Feature/GreenApi
fi
echo 'Docker smoke passed: installation, reinstall protection, HTTP, assets, uploads, queue and persistence.'
