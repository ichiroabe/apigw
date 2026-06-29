#!/bin/sh
# JDK 1.8 互換でコンパイル（JDK 1.8 環境では -source/-target 不要）
set -e
DIR=$(cd "$(dirname "$0")" && pwd)
javac -source 8 -target 8 "$DIR/PomParser.java"
echo "Build done."
echo "実行例: java -cp \"$DIR\" PomParser <フォルダ> [--csv] [--props] [--strict]"
