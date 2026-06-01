@echo off
chcp 65001 >nul
echo ============================================
echo   apigw Deploy - FTP Upload
echo ============================================
echo.

set FTP_HOST=ftp.deskwing.net
set FTP_USER=hm-apijp.03@deskwing.net
set FTP_PASS=useridapi#3

echo Creating FTP command file...
(
echo open %FTP_HOST%
echo %FTP_USER%
echo %FTP_PASS%
echo binary
echo cd /
echo put "E:\project\apigw\php.ini" php.ini
echo put "E:\project\apigw\api_chat.php" api_chat.php
echo put "E:\project\apigw\board.php" board.php
echo put "E:\project\apigw\files.php" files.php
echo put "E:\project\apigw\groups.php" groups.php
echo put "E:\project\apigw\thread.php" thread.php
echo put "E:\project\apigw\users.php" users.php
echo cd includes
echo put "E:\project\apigw\includes\auth.php" auth.php
echo put "E:\project\apigw\includes\config.php" config.php
echo put "E:\project\apigw\includes\db.php" db.php
echo cd /
echo put "E:\project\apigw\migrate_timezone.php" migrate_timezone.php
echo quit
) > "%TEMP%\ftp_deploy.txt"

echo Uploading files via FTP...
ftp -s:"%TEMP%\ftp_deploy.txt"

del "%TEMP%\ftp_deploy.txt"

echo.
echo ============================================
echo   Upload complete!
echo ============================================
echo.
echo Next steps:
echo   1. Open migrate_timezone.php in browser
echo      to convert existing DB timestamps to JST
echo   2. DELETE migrate_timezone.php from server
echo      after migration completes
echo.
pause
