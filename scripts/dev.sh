#!/usr/bin/env bash
# Desarrollo de rayacash-php con Docker: no hace falta PHP ni Composer en tu máquina.
#
# Cada versión de PHP tiene su imagen (rayacash-php-dev:8.x) y su vendor/ en un volumen propio
# (rayacash-php-vendor-8.x): las versiones no se pisan. Solo build (Docker), update (Composer) y
# smoke (la API real) salen a la red; lo demás corre con --network none (si le falta la imagen o
# el vendor/, antes hace build o update), así que los tests nunca salen a la red, y acá ni pueden.
#
#   scripts/dev.sh test [8.x] [args de phpunit]   tests en una versión (por defecto, 8.4)
#   scripts/dev.sh matrix                         tests en 8.1, 8.2, 8.3, 8.4 y 8.5
#   scripts/dev.sh lint                           php-cs-fixer (PER-CS, en 8.1) y 100 columnas
#   scripts/dev.sh fix                            aplica el formato de php-cs-fixer
#   scripts/dev.sh analyse                        PHPStan y las aserciones de tipos
#   scripts/dev.sh coverage [pcov]                cobertura mínima (Xdebug; con pcov, rápida)
#   scripts/dev.sh readme                         los bloques del README, examples/ y scripts/
#   scripts/dev.sh package                        validate, versión y el paquete instalado limpio
#   scripts/dev.sh all                            lint, analyse, matrix, coverage y package
#   scripts/dev.sh update [8.x|all]               composer update en esa versión (con red)
#   scripts/dev.sh build [8.x|all]                reconstruye la imagen con el último PHP 8.x
#   scripts/dev.sh shell [8.x]                    una shell en el contenedor (sin red)
#   scripts/dev.sh clean                          borra las imágenes y los volúmenes
#   scripts/dev.sh smoke                          GET /v1/balance contra la API REAL: solo vos
#
# smoke es el único comando que le pasa algo de tu entorno al contenedor (RAYA_SECRET_KEY) y el
# único que habla con la API: RAYA_SECRET_KEY=raya_sk_live_… scripts/dev.sh smoke
set -eo pipefail # sin set -u: la bash 3.2 de macOS lo rompe con arrays vacíos

cd "$(dirname "$0")/.."
ROOT=$PWD
VERSIONS="8.1 8.2 8.3 8.4 8.5"
TOOLS=8.4 # PHPStan, cobertura y README: la misma versión que el CI
# El piso de PHP: el paquete se prueba como lo instala quien usa el SDK, y php-cs-fixer corre acá
# porque así lo recomienda (en un PHP más nuevo podría meter sintaxis que 8.1 no tiene).
FLOOR=8.1

# La ayuda es el comentario del principio de este archivo.
usage() {
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$ROOT/scripts/dev.sh"
}

is_version() { case "$1" in 8.[0-9]) return 0 ;; *) return 1 ;; esac; }

need_version() {
  if ! is_version "$1"; then
    echo "No conozco la versión de PHP «${1}»: usá 8.1, 8.2, 8.3, 8.4, 8.5 o 8.6." >&2
    exit 2
  fi
}

# 8.6 es una RC hasta el 19-11-2026: después, 8.6-cli (y sumala a VERSIONS).
php_tag() { if [ "$1" = 8.6 ]; then echo 8.6-rc-cli; else echo "$1-cli"; fi; }

build() {
  local extensions="bcmath pcov xdebug"
  # 8.6: PCOV y Xdebug pueden no existir todavía, y php:8.6-rc-cli viene sin mbstring, que PHPUnit
  # pide (el SDK no). Si la imagen ya la trae, install-php-extensions la saltea.
  if [ "$1" = 8.6 ]; then extensions="bcmath mbstring"; fi
  docker build --pull --tag "rayacash-php-dev:$1" \
    --build-arg "PHP_TAG=$(php_tag "$1")" --build-arg "EXTENSIONS=$extensions" docker
}

# run <versión> net|offline <comando…>. No le pasa nada de tu entorno al contenedor.
run() {
  local version=$1 network=$2
  shift 2
  docker image inspect "rayacash-php-dev:$version" >/dev/null 2>&1 || build "$version"
  local flags=(--rm --interactive)
  if [ -t 0 ] && [ -t 1 ]; then flags+=(--tty); fi
  if [ "$network" = offline ]; then flags+=(--network none); fi
  docker run "${flags[@]}" --volume "$ROOT:/app" \
    --volume "rayacash-php-vendor-$version:/app/vendor" \
    --volume rayacash-php-composer-cache:/composer-cache --workdir /app \
    "rayacash-php-dev:$version" "$@"
}

update() {
  run "$1" net sh -ec \
    'composer update --no-progress && sha256sum composer.json > vendor/.composer-json.sha256'
}

# Instala las dependencias si el volumen está vacío o si composer.json cambió.
deps() {
  run "$1" offline sh -c 'sha256sum --check --status vendor/.composer-json.sha256 2>/dev/null' \
    || update "$1"
}

command=${1:-help}
if [ $# -gt 0 ]; then shift; fi

case "$command" in
  test)
    version=$TOOLS
    if is_version "$1"; then version=$1; shift; fi
    deps "$version"
    run "$version" offline php vendor/bin/phpunit "$@"
    ;;
  matrix)
    failed=""
    for version in $VERSIONS; do
      echo "=== PHP $version"
      deps "$version"
      run "$version" offline sh -ec \
        'php scripts/check-phpunit-config.php && php vendor/bin/phpunit' \
        || failed="$failed $version"
    done
    if [ -n "$failed" ]; then echo "Fallaron los tests en PHP$failed." >&2; exit 1; fi
    echo "Tests en verde en PHP $VERSIONS."
    ;;
  lint) deps "$FLOOR"; run "$FLOOR" offline composer lint ;;
  fix) deps "$FLOOR"; run "$FLOOR" offline composer fix ;;
  analyse) deps "$TOOLS"; run "$TOOLS" offline composer analyse ;;
  coverage)
    deps "$TOOLS"
    if [ "$1" = pcov ]; then # solo líneas y funciones: PCOV no mide ramas
      run "$TOOLS" offline sh -ec 'php -d pcov.enabled=1 -d pcov.directory=/app/src \
          vendor/bin/phpunit --coverage-cobertura=build/coverage/cobertura.xml \
          --coverage-html=build/coverage/html
        php scripts/check-coverage.php build/coverage/cobertura.xml --lines=95 --functions=95'
    else
      run "$TOOLS" offline composer test:coverage
    fi
    ;;
  readme) deps "$TOOLS"; run "$TOOLS" offline php vendor/bin/phpunit --filter ReadmeTest ;;
  package)
    # No usa el vendor/ del repo: instala el paquete en un proyecto vacío.
    run "$FLOOR" offline sh -ec \
      'composer validate --strict && php scripts/check-version.php && bash scripts/check-package.sh'
    ;;
  all)
    for step in lint analyse matrix coverage package; do
      echo "=== scripts/dev.sh $step"
      bash "$ROOT/scripts/dev.sh" "$step"
    done
    ;;
  update)
    if [ "${1:-$TOOLS}" = all ]; then
      for version in $VERSIONS; do update "$version"; done
    else
      need_version "${1:-$TOOLS}"
      update "${1:-$TOOLS}"
    fi
    ;;
  build)
    if [ "${1:-$TOOLS}" = all ]; then
      for version in $VERSIONS 8.6; do build "$version"; done
    else
      need_version "${1:-$TOOLS}"
      build "${1:-$TOOLS}"
    fi
    ;;
  shell)
    need_version "${1:-$TOOLS}"
    run "${1:-$TOOLS}" offline bash
    ;;
  clean)
    for version in $VERSIONS 8.6; do
      docker image rm --force "rayacash-php-dev:$version" >/dev/null 2>&1 || true
      docker volume rm --force "rayacash-php-vendor-$version" >/dev/null 2>&1 || true
    done
    docker volume rm --force rayacash-php-composer-cache >/dev/null 2>&1 || true
    ;;
  smoke)
    # Contra la API REAL (solo GET /v1/balance). La clave viaja por el entorno, nunca en la línea
    # de comandos.
    if [ -z "$RAYA_SECRET_KEY" ]; then
      echo 'Definí RAYA_SECRET_KEY con tu clave (raya_sk_live_…): esto consulta tu saldo real.' >&2
      exit 2
    fi
    deps "$TOOLS"
    docker run --rm --env RAYA_SECRET_KEY --volume "$ROOT:/app" \
      --volume "rayacash-php-vendor-$TOOLS:/app/vendor" --workdir /app \
      "rayacash-php-dev:$TOOLS" php scripts/smoke-balance.php
    ;;
  help | -h | --help) usage ;;
  *)
    echo "No conozco el comando «${command}»." >&2
    usage >&2
    exit 2
    ;;
esac
