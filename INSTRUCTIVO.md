# FríoSeguro — Instructivo de Instalación y Prueba
**HACKATEC LOCAL 2026 · TESI Cuautitlán Izcalli**

---

## Requisitos previos

| Herramienta | Versión mínima | Descarga |
|-------------|---------------|---------|
| Windows 10/11 | — | — |
| PHP | 8.3+ | se instala en el Paso 1 |
| Python | 3.10+ | python.org |
| Google Chrome / Edge | cualquiera | — |

---

## Paso 1 — Instalar PHP (una sola vez)

Abre **PowerShell como administrador** y ejecuta:

```powershell
Set-ExecutionPolicy RemoteSigned -Scope CurrentUser
irm get.scoop.sh | iex
scoop install php
```

Verifica:
```powershell
php --version
```
Debe mostrar `PHP 8.x.x`.

### Activar extensión SQLite (una sola vez)

```powershell
$phpDir = "C:\Users\$env:USERNAME\scoop\apps\php\current"
Copy-Item "$phpDir\php.ini-production" "$phpDir\php.ini"
$ini = "$phpDir\php.ini"
(Get-Content $ini) -replace ';extension=pdo_sqlite', 'extension=pdo_sqlite' | Set-Content $ini
(Get-Content $ini) -replace ';extension=sqlite3',    'extension=sqlite3'    | Set-Content $ini
```

Verifica:
```powershell
php -m | Select-String "sqlite|pdo"
```
Debe mostrar `PDO`, `pdo_sqlite` y `sqlite3`.

---

## Paso 2 — Instalar Python y dependencias (una sola vez)

Descarga Python desde [python.org](https://python.org) si no lo tienes.  
Luego instala las dependencias del proyecto:

```powershell
pip install requests pyserial
```

---

## Paso 3 — Obtener el proyecto

Descarga o clona la carpeta del proyecto en tu PC.  
La estructura debe verse así:

```
hackaton/
├── api.php
├── bridge.py
├── frioseguro.html
├── router.php
├── gateway/
├── tinkercad/
└── backend/
```

---

## Paso 4 — Ejecutar el sistema

Necesitas **3 terminales abiertas** al mismo tiempo.

### Terminal 1 — Servidor PHP (backend)

```powershell
cd "C:\ruta\a\tu\carpeta\hackaton"
php -S localhost:8080 router.php
```

Debe decir:
```
PHP 8.x.x Development Server (http://localhost:8080) started
```
⚠️ **Deja esta terminal abierta todo el tiempo.**

---

### Terminal 2 — Simulador Python (sensor + bridge)

```powershell
cd "C:\ruta\a\tu\carpeta\hackaton"
python bridge.py --interactive
```

Verás lecturas en tiempo real:
```
[19:30:10] 🟢 ONLINE | 5.2°C ✓ | Puerta:cerrada | GPS:(19.2920,-99.6570) | Toluca → SERVIDOR
```

---

### Terminal 3 — Solo para comandos de prueba (opcional)

```powershell
cd "C:\ruta\a\tu\carpeta\hackaton"
# Verificar API
Invoke-RestMethod -Uri "http://localhost:8080/api/dashboard" -Method GET | ConvertTo-Json
```

---

## Paso 5 — Abrir el dashboard

Abre tu navegador y ve a:

```
http://localhost:8080/frioseguro.html
```

**Credenciales de acceso:**
- Usuario: `admin`
- Contraseña: `admin123`

---

## Paso 6 — Demostración completa (flujo del jurado)

Sigue estos pasos en orden para ver el sistema funcionar de extremo a extremo:

### Fase 1 — Sistema en línea
1. Con el bridge corriendo, observa el mapa: el vehículo **V-102** aparece y se mueve
2. Los contadores del dashboard suben en tiempo real (viajes activos, temp máxima)
3. La ruta se dibuja en **verde** (conexión activa)

### Fase 2 — Activar MODO RURAL (zona sin señal)
4. En la Terminal 2, presiona la tecla **`R`**
5. Observa en el dashboard:
   - Botón cambia a **"DESACTIVAR MODO RURAL"** (rojo)
   - Conectividad muestra **"Sin señal"**
   - El contador de cola empieza a subir (datos acumulados)
   - La ruta del mapa se dibuja en **azul punteado**
6. Espera **30–60 segundos** (la temperatura sube al no haber refrigeración activa)

### Fase 3 — Recuperar señal y sincronización
7. Vuelve a presionar **`R`** en la Terminal 2
8. Observa en el dashboard:
   - Aparece el **overlay de sincronización** con barra de progreso
   - El mapa actualiza con la ruta completa (verde online + azul offline)
   - Aparecen **marcadores ⚠ rojos** en las coordenadas exactas donde subió la temperatura
   - El panel de alertas se llena con las alertas térmicas detectadas

---

## Reiniciar para una demo limpia

Si quieres empezar desde cero (contadores en 0, mapa vacío):

```powershell
# En la carpeta del proyecto:
Remove-Item "frioseguro.db" -ErrorAction SilentlyContinue
```

Luego recarga el navegador y vuelve a correr `python bridge.py --interactive`.

---

## Solución de problemas frecuentes

| Error | Causa | Solución |
|-------|-------|---------|
| `php : no se reconoce` | PHP no está en PATH | Cierra y vuelve a abrir PowerShell |
| Error 404 en la API | Servidor corriendo sin `router.php` | Usa `php -S localhost:8080 router.php` |
| Error 500 en la API | SQLite no activado | Repite el Paso 1 (activar extensión) |
| `SyntaxError` en bridge.py | Python desactualizado | Actualiza a Python 3.10+ |
| El mapa no carga | Sin conexión a internet | Leaflet necesita internet para los tiles del mapa |
| Mapa vacío sin vehículo | Bridge no está corriendo | Ejecuta el Paso 4 Terminal 2 |

---

## Arquitectura resumida

```
Tinkercad (Arduino simulado)
        ↓ JSON Serial
   bridge.py  ←→  offline_queue.json  (MODO RURAL)
        ↓ HTTP
   api.php + SQLite  (localhost:8080)
        ↓ fetch() cada 5s
   frioseguro.html  (dashboard)
```

---

*FríoSeguro — Cadena de frío resiliente en zonas marginales del Estado de México*
