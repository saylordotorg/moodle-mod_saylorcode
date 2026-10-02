#!/usr/bin/env bash
# Rebuild amd/src/codemirror-lazy.js from the published CodeMirror packages.
#
# The same recipe core uses for tiny_html: install the packages without
# recording them, roll the entry up into one ES module, and commit the result
# whole. Afterwards run grunt amd so amd/build is regenerated, and update the
# versions in thirdpartylibs.xml from the list this prints.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

npm install --no-save --no-package-lock \
    codemirror \
    @codemirror/state \
    @codemirror/view \
    @codemirror/commands \
    @codemirror/language \
    @codemirror/legacy-modes \
    @codemirror/lang-javascript \
    @codemirror/lang-html \
    @codemirror/lang-css \
    @lezer/highlight \
    rollup \
    @rollup/plugin-node-resolve

npx rollup ./codemirror.mjs -f esm -o ../amd/src/codemirror-lazy.js -p @rollup/plugin-node-resolve

for package in codemirror @codemirror/legacy-modes @codemirror/lang-javascript @codemirror/lang-html @codemirror/lang-css; do
    echo "$package $(node -p "require('./node_modules/$package/package.json').version")"
done

rm -rf node_modules
