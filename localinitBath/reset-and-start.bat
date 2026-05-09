@echo off
call "%~dp0init.bat"
if errorlevel 1 exit /b %errorlevel%
call "%~dp0start.bat"
