@echo off
git add .
set /p msg="Opis izmjene: "
git commit -m "%msg%"
git push
echo.
echo Gotovo! Sad idi u cPanel i klikni Update from Remote.
pause
