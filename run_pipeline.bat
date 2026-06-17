@echo off
REM One-command pipeline wrapper. Double-click or run: run_pipeline.bat [--month 2026-06]
python "%~dp0run_pipeline.py" %*
pause
