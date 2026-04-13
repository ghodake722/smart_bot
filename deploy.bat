@echo off
setlocal

git add -A
git diff --cached --quiet
if errorlevel 1 (
    git commit -m "Deploy latest updates"
) else (
    echo No local changes to commit.
)

git push origin main
if errorlevel 1 goto :fail

ssh -p 22 mytptd_c1@103.174.102.76 ^
    "cd ~/smart_bot && git fetch --prune origin main && git reset --hard origin/main && git clean -fdx && composer install --no-dev --optimize-autoloader"
if errorlevel 1 goto :fail

echo Deployment Complete!
exit /b 0

:fail
echo Deployment Failed!
exit /b 1
