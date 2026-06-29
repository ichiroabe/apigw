#!/bin/sh
# JDK 1.8 互換でコンパイル（JDK 1.8 環境では -source/-target 不要）
set -e
DIR=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$DIR/out"
javac -source 8 -target 8 -d "$DIR/out" "$DIR"/src/com/example/pomparser/*.java
echo "Build done. -> $DIR/out"
echo "実行例: java -cp \"$DIR/out\" com.example.pomparser.Main <フォルダ> [--csv] [--props]"
