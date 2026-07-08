@echo off
echo Installing requirements...
py -m pip install -r requirements.txt -q
echo.
echo Starting LinkedIn Scheduler...
echo Open http://localhost:5000 in your browser (opening automatically)
echo Press Ctrl+C to stop.
echo.
py app.py
pause
