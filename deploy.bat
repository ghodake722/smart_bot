@echo off
git add .
git commit -m "Deploying latest updates"
git push origin main
ssh -p 22 mytptd_c1@103.174.102.76 "cd ~/smart_bot && git pull origin main"
echo Deployment Complete!