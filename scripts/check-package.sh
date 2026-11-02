#!/usr/bin/env bash
# El paquete tal como lo baja Composer. Packagist sirve el zip que arma GitHub con `git archive`,
# que deja afuera lo marcado export-ignore en .gitattributes. Este script:
#   1. arma ese zip con tu árbol de trabajo tal como quedaría en un commit (lo commiteado, lo
#      cambiado y lo nuevo que git no ignora) y comprueba que traiga solo composer.json, src/ y
#      los docs;
#   2. lo instala en un proyecto vacío, sin Packagist ni red, con el autoload de producción;
#   3. carga cada clase y hace una request con un transporte falso (scripts/check-package.php).
#
#   bash scripts/check-package.sh      (CI; en tu máquina: scripts/dev.sh package)
set -euo pipefail
export LC_ALL=C

cd "$(dirname "$0")/.."
version=$(php scripts/check-version.php --print)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# Un índice aparte (el tuyo no se toca): HEAD más todo lo que git no ignora. Así, un archivo
# nuevo sin export-ignore hace fallar el chequeo antes del commit, no después del release.
export GIT_INDEX_FILE="$work/index"
if git rev-parse --verify --quiet HEAD >/dev/null; then git read-tree HEAD; fi
git add --all .
tree=$(git write-tree)
unset GIT_INDEX_FILE
git archive --format=tar --prefix=package/ "$tree" | tar -x -C "$work"

contents=$(find "$work/package" -mindepth 1 -maxdepth 1 -exec basename {} \; | sort | tr '\n' ' ')
expected='CHANGELOG.md LICENSE README.md composer.json src '
if [ "$contents" != "$expected" ]; then
  echo "El paquete trae: $contents" >&2
  echo "Tendría que traer: $expected(lo demás, con export-ignore en .gitattributes)" >&2
  exit 1
fi

mkdir "$work/app"
cat >"$work/app/composer.json" <<JSON
{
    "require": {"rayacash/rayacash-php": "$version"},
    "repositories": [
        {
            "type": "path",
            "url": "../package",
            "options": {"symlink": false, "versions": {"rayacash/rayacash-php": "$version"}}
        },
        {"packagist.org": false}
    ],
    "config": {"lock": false}
}
JSON
composer install --working-dir="$work/app" --no-interaction --no-progress --no-autoloader
# Solo el classmap: una clase cuyo namespace no coincide con su ruta no carga (Composer no avisa
# de eso en las dependencias; check-package.php carga cada una).
composer dump-autoload --working-dir="$work/app" --classmap-authoritative

cp scripts/check-package.php "$work/app/"
php "$work/app/check-package.php" "$version"
