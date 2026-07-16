@echo off
REM ============================================================
REM  Genera stylelauri-order-flow.zip listo para subir a WordPress.
REM  Doble clic y ya. No comprimas la carpeta a mano.
REM ============================================================
cd /d "%~dp0"

REM Limpiar zips viejos (incluido cualquiera que haya quedado DENTRO
REM de la carpeta del plugin -- si se cuela, se empaca a si mismo).
del /q stylelauri-order-flow.zip 2>nul
del /q stylelauri-order-flow\stylelauri-order-flow.zip 2>nul

REM tar de Windows (bsdtar) genera el zip con separadores / correctos,
REM que es lo que el servidor Linux de Hostinger necesita.
tar -a -cf stylelauri-order-flow.zip stylelauri-order-flow

if errorlevel 1 (
    echo.
    echo ERROR generando el zip.
    pause
    exit /b 1
)

echo.
echo Listo: stylelauri-order-flow.zip generado.
echo Subelo en WordPress: Plugins ^> Anadir nuevo ^> Subir plugin.
echo Si ya esta instalado, WordPress preguntara: usa "Reemplazar el actual con el subido".
pause
