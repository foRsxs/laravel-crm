# Docker и выпуск Krayin

Docker-конфигурация версионируется вместе с форком, отдельно от `packages/Webkul/GreenApi`. PHP 8.4 и Nginx собираются из одного коммита; `app`, `worker` и `scheduler` используют один образ приложения. `web` содержит Nginx и собранную статику той же версии. Composer устанавливает зависимости из lock, четыре Vite-приложения собираются через `npm ci`.

Scheduler вызывает штатный `schedule:run` каждую минуту для входящей почты и маркетинговых кампаний Krayin. При остановке он дожидается активного задания. MySQL и storage общие для процессов одного стенда.

## Локальная проверка без реального GREEN-API

Требуются Docker Engine и Compose с поддержкой `up --wait` (Compose 2.24+), Bash, curl и openssl.

```bash
for target in app web test; do
    docker build -f docker/Dockerfile --target "$target" -t "krayin-$target:ci" .
done
APP_IMAGE=krayin-app:ci WEB_IMAGE=krayin-web:ci TEST_IMAGE=krayin-test:ci bash docker/smoke.sh
```

Smoke создаёт собственный проект `krayin-smoke-*`, БД и storage, проверяет установку, отказ повторной установки, HTTP, статику, безопасную выдачу uploads, работу отдельного worker и сохранение файлов после пересоздания контейнеров. В test-образе запускаются тесты GREEN-API. После проверки удаляются только ресурсы этого временного проекта. `SMOKE_PORT` меняет локальный порт (по умолчанию 18081).

## Выпуск образов

После объединения workflow в основную ветку форка: **Actions → Docker CI and release → Run workflow**. Выберите проверенную ветку/коммит через ref и платформы сервера (`linux/amd64`, `linux/arm64` или обе).

1. CI собирает app/web/test и запускает smoke.
2. Только ручной запуск публикует `ghcr.io/forsxs/laravel-crm-app:sha-<commit>` и `ghcr.io/forsxs/laravel-crm-web:sha-<commit>`.
3. Опубликованные образы повторно проверяются через их digest.
4. Artifact `krayin-release-<commit>` содержит Compose, эксплуатационные скрипты, пример окружения и `release.env` с точными `image@sha256:…`.

PR и push выполняют проверки без публикации. Сервер, SSH и production-секреты в workflow не используются. Теги описывают коммит; точную неизменяемую версию задаёт digest в release.env. Для тестового стенда и production используйте один и тот же bundle.

## Первый запуск на сервере

1. Установите Docker и Compose. Разместите `compose.prod.yaml` и папку `docker/` из bundle, например в `/opt/krayin`. Все дальнейшие команды выполняются в этом каталоге.
2. Сохраните release.env из bundle как **candidate.env**. При следующих обновлениях активный release.env должен оставаться на месте до завершения deploy.
3. Подготовьте окружение:

```bash
umask 077
cp docker/.env.example .env.prod
mkdir -p secrets
openssl rand -hex 32 > secrets/db-root-password
openssl rand -base64 32
```

Последнюю строку вставьте в `.env.prod` как `APP_KEY=base64:<полученный ключ>`. Установите уникальный DB_PASSWORD, APP_URL, почтовые параметры. Права `.env.prod` и файла root-пароля — 600. APP_KEY создаётся один раз и сохраняется при всех обновлениях. DB root-пароль передаётся только контейнеру MySQL; приложение использует отдельного пользователя БД.

Если GHCR-пакеты приватные, выполните `docker login ghcr.io` с учётной записью, имеющей `read:packages`. Для публичных пакетов вход не нужен. Пароли и токены не добавляются в git, образ и release artifact.

4. Запустите установку:

```bash
bash docker/deploy.sh candidate.env --initialize
```

Скрипт запросит email и пароль администратора (минимум 12 символов, ввод скрыт). `docker/install.php` проверит APP_KEY и пустую БД, затем вызовет штатные миграции и seeders Krayin, заменит тестовые реквизиты администратора и запишет штатный признак установки. Ключ не меняется. При непустой БД установка останавливается до изменений. Частично неудачная установка требует диагностики; автоматического сброса данных нет.

Контейнеры не запускают установку/миграции самостоятельно. В startup нет `migrate:fresh`, seed или генерации APP_KEY. Стандартный `krayin-crm:install` для обновления этой установки не используется: он пересоздаёт БД и ключ.

## HTTPS и GREEN-API

`web` по умолчанию доступен только на `127.0.0.1:8080`. TLS завершается reverse proxy на хосте. В его HTTPS server block:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Прокси перезаписывает X-Forwarded-Proto. Контейнерный Nginx передаёт PHP правильную схему HTTPS; доступ к исходному IP клиента отдельно не настраивается. Сохраните `HTTP_BIND=127.0.0.1`, установите `APP_URL=https://crm.example.com`, `SESSION_SECURE_COOKIE=true`. Для локального HTTP-стенда задайте false.

Настройте GREEN_API_* из [README интеграции](../packages/Webkul/GreenApi/README.md). Пакет выключен по умолчанию. Публичный webhook: `https://crm.example.com/green-api/webhook`. Worker обслуживает GREEN_API_QUEUE, транспорт — database. Исходящие API-вызовы идут из PHP-контейнера, входящие — через HTTPS-прокси.

## Обновление

Скопируйте из нового bundle Compose и папку docker, а его release.env — как candidate.env. Сохраните `.env.prod`, secrets, активный release.env и постоянное COMPOSE_PROJECT_NAME.

```bash
bash docker/deploy.sh candidate.env
```

Порядок: скачать app/web по digest → проверить MySQL без пересоздания его контейнера → maintenance → graceful stop worker/scheduler/app → backup → миграции новым образом → запуск app/web с healthchecks → открыть CRM → HTTP `/up` → запуск worker/scheduler → сохранить active и previous release.env. Начатым запросам/заданиям даётся время завершиться перед backup. Ожидается короткая недоступность CRM. Версия MySQL обновляется отдельно от релиза приложения.

При ошибке backup, миграции или проверки приложение остаётся в maintenance, worker и scheduler остановлены. Автоматического отката миграций нет. Сначала изучите `docker compose ... logs`, состояние БД и сохранённую копию. Успешный deploy сохраняет предыдущий образ в release.previous.env; для совместимой схемы можно передать этот файл как следующий candidate. Миграции планируйте с обратной совместимостью.

## Данные и восстановление

Постоянные volumes: `<COMPOSE_PROJECT_NAME>_database` и `<COMPOSE_PROJECT_NAME>_storage`. Storage общий для app/worker; web видит его только для чтения, выдаёт исключительно `storage/app/public`. Сессии и Laravel cache сохраняются, `bootstrap/cache` создаётся отдельно в каждом контейнере. Не удаляйте volumes при обновлении.

Backup находится в `backups/<UTC-время>/`: database.sql, storage.tar.gz, **app.env с APP_KEY и секретами**, release.env. Каталог закрыт umask077; копируйте резервные копии в защищённое внешнее хранилище. Ручной `bash docker/backup.sh release.env` на работающей CRM даёт SQL transaction snapshot, но согласованный снимок БД и файлов обеспечивается остановкой записи, как в deploy.

Для восстановления на чистом сервере подготовьте Compose, secret root-пароля и восстановите app.env как .env.prod, release.env как candidate.env. Запустите только MySQL, импортируйте SQL приложенческим пользователем, восстановите архив storage в соответствующий volume, затем запустите app/web/worker из сохранённых digest и выполните `php artisan up` в app. **Не запускайте --initialize на восстановленной БД.** Верните активный release.env. Проверьте вход, uploads, очередь и webhook перед открытием доступа.

Тестовый и рабочий стенды используют разные COMPOSE_PROJECT_NAME, порты, БД, storage, APP_KEY и GREEN-API-реквизиты. Изменение production-секретов и инфраструктуры выполняется отдельно от автоматического выпуска образов.
