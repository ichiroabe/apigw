@echo off
rem JDK 1.8 互換でコンパイル
setlocal
set DIR=%~dp0
javac -source 8 -target 8 "%DIR%PomParser.java"
if errorlevel 1 exit /b 1
echo Build done.
echo 実行例: java -cp "%DIR%" PomParser ^<フォルダ^> [--csv] [--props] [--strict]
endlocal
