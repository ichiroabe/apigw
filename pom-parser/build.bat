@echo off
rem JDK 1.8 互換でコンパイル
setlocal
set DIR=%~dp0
if not exist "%DIR%out" mkdir "%DIR%out"
javac -source 8 -target 8 -d "%DIR%out" "%DIR%src\com\example\pomparser\*.java"
if errorlevel 1 exit /b 1
echo Build done. -^> %DIR%out
echo 実行例: java -cp "%DIR%out" com.example.pomparser.Main ^<フォルダ^> [--csv] [--props]
endlocal
