# FríoSeguro — Cadena de Frío Resiliente

Sistema de monitoreo de temperatura para distribución de insumos médicos en zonas rurales con cobertura intermitente. Desarrollado para **HACKATEC 2026 — Estado de México**.

---

## El problema que resuelve

Los vehículos que distribuyen vacunas e insulinas pierden señal celular en zonas marginadas. Sin monitoreo durante esos apagones, las desviaciones de temperatura pasan desapercibidas hasta que el daño ya ocurrió. FríoSeguro sigue registrando aunque no haya internet, y cuando el vehículo recupera señal sube todo el historial de golpe para análisis retroactivo.

---

## Arquitectura general

```
[Arduino / Tinkercad]
       ↓  Serial JSON (9600 baud, cada 5 s)
[bridge.py  — Gateway]
       ↓  HTTP POST  (cuando hay señal)
       ↓  offline_queue.json  (cuando no hay señal — MODO RURAL)
[api.php  — Backend REST]
       ↓
[frioseguro.db  — SQLite]
       ↑
[frioseguro.html  — Dashboard]
```

---

## Requisitos previos

| Herramienta | Versión mínima | Para qué |
|------------|---------------|---------|
| PHP | 8.3 | Servidor backend |
| Python | 3.10 | Gateway bridge |
| pip install requests pyserial | — | Dependencias Python |
| Ollama *(opcional)* | cualquiera | Análisis de riesgo con IA |

> **Sin Ollama** el sistema funciona igual; usa cálculo matemático de daño térmico como respaldo.

---

## Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/val-l-m/hackaton.git
cd hackaton
```

### 2. Instalar dependencias Python

```bash
pip install requests pyserial
```

### 3. (Opcional) Instalar Ollama con el modelo de IA

Descarga Ollama desde https://ollama.com/download, luego:

```bash
ollama pull gemma3:4b
ollama serve          # déjalo corriendo en una terminal aparte
```

---

## Cómo ejecutar el sistema completo

Necesitas **3 terminales** abiertas al mismo tiempo.

### Terminal 1 — Servidor PHP (Backend + Frontend)

```bash
cd hackaton
php -S localhost:8080 router.php
```

Verifica que funciona abriendo en el navegador:
```
http://localhost:8080
```

Deberías ver la pantalla de login de FríoSeguro.

---

### Terminal 2 — Gateway Python (Sensor + Cola Offline)

```bash
cd hackaton
python bridge.py --interactive --server http://localhost:8080/api
```

El bridge inicia la simulación automáticamente. Verás algo así:

```
[BRIDGE] Servidor: http://localhost:8080/api
[BRIDGE] Iniciando simulación de ruta Toluca → Sultepec
[ONLINE] Lectura enviada: 4.2°C | Lat: 19.292 Lng: -99.653
[ONLINE] Lectura enviada: 4.5°C | ...
```

**Controles del bridge (teclado):**
| Tecla | Acción |
|-------|--------|
| `R` | Activar/desactivar MODO RURAL (simula pérdida de señal) |
| `Q` | Salir |

---

### Terminal 3 — (Opcional) Ollama para análisis IA

```bash
ollama serve
```

> Si ya lo arrancaste en el paso de instalación, no hace falta volver a hacerlo.

---

## Credenciales de prueba

| Usuario | Contraseña | Rol |
|---------|-----------|-----|
| `admin` | `admin123` | Administrador |

---

## Cómo probar cada funcionalidad

### Dashboard en tiempo real
1. Abre `http://localhost:8080` e inicia sesión.
2. Con el bridge corriendo verás cómo suben las lecturas de temperatura en la gráfica y el mapa se actualiza.

### MODO RURAL (cola offline)
1. En la terminal del bridge, presiona **`R`**.
2. El bridge dejará de enviar al servidor y guardará en `offline_queue.json`.
3. En el dashboard aparecerá el vehículo como "Sin señal".
4. Presiona **`R`** de nuevo para recuperar señal.
5. El bridge hace batch sync automático y verás todas las lecturas aparecer de golpe en el mapa y en las alertas.

### Análisis de riesgo con IA
1. Entra al dashboard → menú lateral **"IA Predictiva"**.
2. Haz clic en **"Analizar con IA"**.
3. Ollama evaluará el historial térmico y devolverá nivel de riesgo, recomendaciones y predicción de estado del lote.
4. Si Ollama no está corriendo, el sistema usa el índice matemático de daño térmico igualmente.

### CRUD de Usuarios y Vehículos
- Menú lateral → **"Usuarios"** o **"Vehículos"** para registrar, editar o eliminar.

---

## Probar con Arduino real (opcional)

Si tienes un Arduino Uno con sensor TMP36:

1. Carga `tinkercad/sensor_frioseguro.ino` en tu Arduino.
2. Conecta:
   - TMP36 → pin **A0**
   - Botón puerta → pin **D2** (con pull-up)
   - Botón MODO RURAL → pin **D3** (con pull-up)
3. Corre el bridge indicando el puerto serial:

```bash
python bridge.py --serial COM3 --server http://localhost:8080/api
# En Linux/Mac: --serial /dev/ttyUSB0
```

---

## Probar la API directamente (Postman / curl)

El servidor corre en `http://localhost:8080/api`. Algunos endpoints:

```bash
# Dashboard (stats generales)
GET  http://localhost:8080/api/dashboard

# Crear un viaje
POST http://localhost:8080/api/viaje
Content-Type: application/json
{ "id": "VJ-001", "vehiculo": "ECO-01", "medicamento": "Vacuna BCG",
  "origen": "Toluca", "destino": "Sultepec",
  "temp_min": 2.0, "temp_max": 8.0 }

# Enviar una lectura de telemetría
POST http://localhost:8080/api/telemetria
Content-Type: application/json
{ "viaje_id": "VJ-001", "temperatura_actual": 5.2,
  "latitud_actual": 19.292, "longitud_actual": -99.653,
  "puerta_abierta": false, "modo_rural": false }

# Ver alertas recientes
GET  http://localhost:8080/api/alertas

# Análisis de riesgo IA para un viaje
POST http://localhost:8080/api/ia/analizar-riesgo
Content-Type: application/json
{ "viaje_id": "VJ-001" }
```

---

## Estructura del proyecto

```
hackaton/
├── frioseguro.html          # Dashboard principal (frontend)
├── api.php                  # Backend REST (PHP 8.3, SQLite, Ollama)
├── bridge.py                # Gateway Python (sensor → API, cola offline)
├── router.php               # Enrutador para el servidor de desarrollo PHP
├── frioseguro.db            # Base de datos SQLite (se crea automáticamente)
├── offline_queue.json       # Cola de lecturas sin enviar (MODO RURAL)
├── INSTRUCTIVO.md           # Guía de instalación detallada
├── tinkercad/
│   └── sensor_frioseguro.ino    # Código Arduino (TMP36 + Serial JSON)
├── gateweay/                    # Implementaciones alternativas (legacy)
│   ├── lector_serial.py         # Lector serial para hardware real
│   ├── simulador_tinkercad.py   # Simulador de prueba
│   ├── datos_locales.json       # Caché local de lecturas
│   └── pendientes.json          # Cola de reintentos
└── backend/                     # Backend legacy (no usar)
    ├── conexion.php             # Conexión MySQL (obsoleto)
    └── insertar_lectura.php     # Endpoint antiguo (obsoleto)
```

---

## Niveles de riesgo térmico

| Nivel | Color | Significado |
|-------|-------|-------------|
| `seguro` | Verde | Sin excursiones de temperatura |
| `vigilancia` | Azul | Excursión leve, monitorear |
| `preventivo` | Amarillo | Requiere revisión del responsable |
| `alto` | Naranja | Riesgo real de daño al lote |
| `critico` | Rojo | Probable daño, no distribuir sin análisis |
| `comprometido` | Rojo oscuro | Lote comprometido, no apto |

---

## Tecnologías usadas

- **Backend**: PHP 8.3 · PDO · SQLite · OOP estricto
- **Frontend**: HTML5 · CSS3 · Bootstrap 5.3 · Leaflet.js · Chart.js
- **Gateway**: Python 3.10 · requests · pyserial
- **IA local**: Ollama · gemma3:4b
- **Sensor**: Arduino C++ · TMP36
- **DB**: SQLite con WAL mode (sin instalación de servidor de base de datos)
