#!/usr/bin/env python3
"""
FríoSeguro — Script Puente (Bridge)
====================================
Fase 1: Simulación de pérdida de cobertura (MODO_RURAL)

Lee telemetría del simulador (Tinkercad / Wokwi via Serial, o modo simulado)
y gestiona la cola offline para sincronización batch al recuperar señal.

Uso:
    python bridge.py                  # Ruta automática Toluca→Sultepec
    python bridge.py --interactive    # Toggle manual con teclado [R]
    python bridge.py --serial COM3    # Puerto serial real de Arduino
    python bridge.py --server http://localhost:8080/api

Dependencias:
    pip install requests pyserial
"""

import argparse
import json
import math
import os
import random
import sys
import threading
import time
from datetime import datetime
from pathlib import Path
from typing import Optional

try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False
    print("[WARN] 'requests' no instalado. Instala con: pip install requests")

try:
    import serial
    HAS_SERIAL = True
except ImportError:
    HAS_SERIAL = False


# ══════════════════════════════════════════════════════════════════════════════
#  CONFIGURACIÓN
# ══════════════════════════════════════════════════════════════════════════════
SERVER_URL      = "http://localhost:8080/api"
QUEUE_FILE      = Path("offline_queue.json")
VIAJE_ID        = "VJ-2026-047"
VEHICULO_ID     = "V-102"
MEDICAMENTO     = "Vacuna BCG"
TEMP_MIN        = 2.0    # °C — umbral mínimo de la cadena de frío
TEMP_MAX        = 8.0    # °C — umbral máximo
LECTURA_SEG     = 5      # segundos entre lecturas
PING_SEG        = 10     # segundos entre pings de conectividad

# Puntos de la ruta simulada Toluca → Sultepec
ROUTE = [
    #  lat        lng       descripción
    (19.2920, -99.6570, "Central Toluca"),
    (19.2500, -99.6400, "Metepec"),
    (19.2100, -99.6200, "Calimaya"),
    (19.1700, -99.6000, "Coatepec Harinas"),
    (19.1400, -99.5800, "Inicio zona marginal"),   # índice 4
    (19.0900, -99.5600, "Sierra Sultepec"),         # índice 5 → OFFLINE START
    (19.0500, -99.5400, "Zona muerta crítica"),
    (19.0100, -99.5200, "Sierra profunda"),
    (18.9700, -99.5000, "Comunidad El Progreso"),
    (18.9400, -99.4800, "Sultepec"),                # índice 9 → OFFLINE END
]
OFFLINE_START = 5
OFFLINE_END   = 9


# ══════════════════════════════════════════════════════════════════════════════
#  COLORES ANSI PARA TERMINAL
# ══════════════════════════════════════════════════════════════════════════════
class C:
    G  = '\033[92m'   # Verde
    Y  = '\033[93m'   # Amarillo
    R  = '\033[91m'   # Rojo
    B  = '\033[94m'   # Azul
    W  = '\033[97m'   # Blanco
    M  = '\033[95m'   # Magenta
    DIM= '\033[2m'
    RST= '\033[0m'

    @staticmethod
    def ok(s):  return f"{C.G}{s}{C.RST}"
    @staticmethod
    def warn(s):return f"{C.Y}{s}{C.RST}"
    @staticmethod
    def err(s): return f"{C.R}{s}{C.RST}"
    @staticmethod
    def info(s):return f"{C.B}{s}{C.RST}"
    @staticmethod
    def dim(s): return f"{C.DIM}{s}{C.RST}"


# ══════════════════════════════════════════════════════════════════════════════
#  GESTOR DE COLA OFFLINE
# ══════════════════════════════════════════════════════════════════════════════
class QueueManager:
    """
    Almacena lecturas de telemetría en un archivo JSON local.
    Simula el almacenamiento en la computadora del vehículo durante MODO_RURAL.
    """

    def __init__(self, path: Path):
        self.path = path
        self._lock = threading.Lock()

    def save(self, record: dict) -> None:
        with self._lock:
            records = self._load()
            records.append(record)
            self.path.write_text(json.dumps(records, indent=2, ensure_ascii=False), encoding='utf-8')

    def load_all(self) -> list:
        with self._lock:
            return self._load()

    def clear(self) -> None:
        with self._lock:
            self.path.write_text('[]', encoding='utf-8')

    def count(self) -> int:
        return len(self._load())

    def is_empty(self) -> bool:
        return self.count() == 0

    def _load(self) -> list:
        if not self.path.exists():
            return []
        try:
            return json.loads(self.path.read_text(encoding='utf-8'))
        except (json.JSONDecodeError, OSError):
            return []


# ══════════════════════════════════════════════════════════════════════════════
#  SIMULADOR DE SENSOR (reemplazable por Serial real)
# ══════════════════════════════════════════════════════════════════════════════
class SensorSimulator:
    """
    Simula la salida serial del circuito Tinkercad/Wokwi.
    En producción, reemplazar read() con lectura de pyserial.

    Formato de trama JSON que se espera del Arduino:
        {"temp": 5.2, "puerta": 0, "lat": 19.292, "lng": -99.657, "ts": 1716000000}
    """

    def __init__(self):
        self._base_temp    = 5.0
        self._wp_index     = 0
        self._reading_cnt  = 0
        self._door_counter = 0

    def read(self, modo_rural: bool, wp_index: int) -> dict:
        """Retorna una lectura de telemetría simulada."""
        self._reading_cnt += 1
        self._door_counter += 1

        # Posición GPS con ruido mínimo
        lat, lng, _ = ROUTE[min(wp_index, len(ROUTE) - 1)]
        lat += random.uniform(-0.0008, 0.0008)
        lng += random.uniform(-0.0008, 0.0008)

        # Temperatura
        if modo_rural:
            # Sin monitoreo activo → temperatura deriva hacia arriba
            drift = random.uniform(0.03, 0.28)
            self._base_temp += drift
        else:
            # Refrigeración activa → temperatura estable en rango seguro
            delta = random.uniform(-0.18, 0.18)
            self._base_temp += delta
            self._base_temp = max(TEMP_MIN + 0.8, min(self._base_temp, TEMP_MAX - 0.8))

        temp = round(max(0.0, min(20.0, self._base_temp)), 2)

        # Evento de puerta abierta (infrecuente, ~1 cada 50 lecturas)
        door_open = 1 if (self._door_counter % 51 == 0) else 0
        if door_open:
            self._base_temp += 1.8  # apertura causa pico térmico

        return {
            "viaje_id":              VIAJE_ID,
            "vehiculo_id":           VEHICULO_ID,
            "temperatura_actual":    temp,
            "sensor_puerta":         door_open,
            "latitud_actual":        round(lat, 6),
            "longitud_actual":       round(lng, 6),
            "timestamp_lectura_real": int(time.time()),
        }


class SerialReader:
    """Lee tramas JSON del puerto serial de Arduino/ESP32."""

    def __init__(self, port: str, baudrate: int = 9600):
        if not HAS_SERIAL:
            raise ImportError("pyserial requerido: pip install pyserial")
        self._ser = serial.Serial(port, baudrate, timeout=2)
        time.sleep(2)  # Espera al reset de Arduino

    def read(self, *args, **kwargs) -> Optional[dict]:
        line = self._ser.readline().decode('utf-8', errors='replace').strip()
        if not line:
            return None
        try:
            raw = json.loads(line)
            return {
                "viaje_id":               VIAJE_ID,
                "vehiculo_id":            VEHICULO_ID,
                "temperatura_actual":     float(raw.get('temp', 0)),
                "sensor_puerta":          int(raw.get('puerta', 0)),
                "latitud_actual":         float(raw.get('lat', 0)),
                "longitud_actual":        float(raw.get('lng', 0)),
                "timestamp_lectura_real": int(raw.get('ts', time.time())),
            }
        except (json.JSONDecodeError, KeyError, ValueError) as e:
            print(C.err(f"[SERIAL] Trama inválida: {line!r} → {e}"))
            return None


# ══════════════════════════════════════════════════════════════════════════════
#  GESTOR DE RED
# ══════════════════════════════════════════════════════════════════════════════
class NetworkManager:

    def __init__(self, server_url: str, timeout: int = 5):
        self.url     = server_url.rstrip('/')
        self.timeout = timeout

    def ping(self) -> bool:
        if not HAS_REQUESTS:
            return False
        try:
            r = requests.get(f"{self.url}/dashboard", timeout=self.timeout)
            return r.status_code == 200
        except Exception:
            return False

    def post_record(self, record: dict) -> bool:
        if not HAS_REQUESTS:
            return False
        try:
            r = requests.post(f"{self.url}/telemetria", json=record, timeout=self.timeout)
            return r.status_code == 200
        except Exception:
            return False

    def post_batch(self, records: list) -> Optional[dict]:
        if not HAS_REQUESTS:
            return None
        try:
            r = requests.post(
                f"{self.url}/telemetria/batch",
                json={"records": records},
                timeout=max(30, self.timeout * 6)
            )
            if r.status_code == 200:
                return r.json()
        except Exception as e:
            print(C.err(f"[SYNC] Batch POST falló: {e}"))
        return None


# ══════════════════════════════════════════════════════════════════════════════
#  BRIDGE SCRIPT PRINCIPAL
# ══════════════════════════════════════════════════════════════════════════════
class BridgeScript:

    def __init__(
        self,
        sensor,
        interactive: bool = False,
        server_url:  str  = SERVER_URL,
    ):
        self.sensor       = sensor
        self.interactive  = interactive
        self.network      = NetworkManager(server_url)
        self.queue        = QueueManager(QUEUE_FILE)
        self.modo_rural   = False
        self.wp_index     = 0
        self.reading_cnt  = 0
        self.synced_total = 0
        self._stop        = threading.Event()

    # ── MODO RURAL ────────────────────────────────────────────────────────────
    def set_rural(self, active: bool) -> None:
        if active == self.modo_rural:
            return
        self.modo_rural = active
        if active:
            print(f"\n  {C.warn('⚡ MODO RURAL ACTIVADO')} — Cortando HTTP. Cola local activa.")
        else:
            print(f"\n  {C.ok('✓ SEÑAL RECUPERADA')} — Iniciando sincronización batch...")
            self._sync_queue()

    def toggle_rural(self) -> None:
        self.set_rural(not self.modo_rural)

    # ── SYNC ──────────────────────────────────────────────────────────────────
    def _sync_queue(self) -> None:
        if self.queue.is_empty():
            print(f"  {C.dim('[SYNC] Cola vacía.')}")
            return

        records = self.queue.load_all()
        total   = len(records)

        print(f"  {C.info(f'[SYNC] Enviando {total} registros al servidor...')}")

        if not self.network.ping():
            print(C.err("  [SYNC] Servidor no alcanzable. Cola conservada."))
            return

        result = self.network.post_batch(records)
        if result and result.get('ok'):
            stored     = result.get('stored', total)
            analysis   = result.get('analysis', {})
            comprometido = analysis.get('comprometido', False)
            alertas    = analysis.get('alertas_generadas', 0)
            fuera_rango= analysis.get('minutos_fuera_rango', 0)

            self.synced_total += stored
            self.queue.clear()

            print(f"  {C.ok(f'[SYNC] ✓ {stored}/{total} registros sincronizados')}")
            print(f"  [SYNC] Temp máxima registrada: {analysis.get('temp_maxima','?')}°C")
            print(f"  [SYNC] Minutos fuera de rango: {fuera_rango}")

            if comprometido:
                print(C.err(f"  [SYNC] ⚠  LOTE COMPROMETIDO — {alertas} alertas térmicas detectadas"))
            else:
                print(C.ok(f"  [SYNC] ✓ Cadena de frío preservada"))
        else:
            print(C.err("  [SYNC] ✗ Falló. Datos conservados en cola."))

    # ── AVANCE DE RUTA ────────────────────────────────────────────────────────
    def _advance_waypoint(self) -> None:
        """Avanza el waypoint y activa MODO_RURAL automáticamente por posición."""
        if self.reading_cnt % 6 == 0:
            self.wp_index = min(self.wp_index + 1, len(ROUTE) - 1)

        if not self.interactive:
            if self.wp_index == OFFLINE_START and not self.modo_rural:
                print(f"\n  {C.warn('[AUTO] Waypoint → ' + ROUTE[OFFLINE_START][2] + ' — activando MODO_RURAL')}")
                self.set_rural(True)
            elif self.wp_index >= OFFLINE_END and self.modo_rural:
                print(f"\n  {C.ok('[AUTO] Waypoint → ' + ROUTE[OFFLINE_END][2] + ' — señal recuperada')}")
                self.set_rural(False)

    # ── LOG ───────────────────────────────────────────────────────────────────
    def _log(self, record: dict, stored_online: bool) -> None:
        ts     = datetime.fromtimestamp(record['timestamp_lectura_real']).strftime('%H:%M:%S')
        temp   = record['temperatura_actual']
        lat    = record['latitud_actual']
        lng    = record['longitud_actual']
        door   = C.err("ABIERTA ⚠") if record['sensor_puerta'] else C.dim("cerrada")
        q_size = self.queue.count()
        wp_name= ROUTE[min(self.wp_index, len(ROUTE)-1)][2]

        mode_str = C.err("🔴 OFFLINE") if self.modo_rural else C.ok("🟢 ONLINE")

        if   temp > TEMP_MAX: t_str = C.err(f"{temp:.1f}°C ⚠↑")
        elif temp < TEMP_MIN: t_str = C.warn(f"{temp:.1f}°C ⚠↓")
        else:                 t_str = C.ok(f"{temp:.1f}°C ✓")

        dest = C.dim(f"→ COLA [{q_size}]") if not stored_online else C.ok("→ SERVIDOR")

        print(
            f"  [{C.dim(ts)}] {mode_str} | {t_str} | "
            f"Puerta:{door} | "
            f"GPS:({lat:.4f},{lng:.4f}) | "
            f"{C.dim(wp_name[:20])} {dest}"
        )

    # ── LOOP PRINCIPAL ────────────────────────────────────────────────────────
    def run(self) -> None:
        self._print_banner()

        if self.interactive:
            self._start_keyboard_thread()

        try:
            while not self._stop.is_set():
                self._advance_waypoint()

                record = self.sensor.read(self.modo_rural, self.wp_index)
                if record is None:
                    time.sleep(1)
                    continue

                self.reading_cnt += 1

                if self.modo_rural:
                    # Sin señal: guardar localmente
                    self.queue.save(record)
                    self._log(record, stored_online=False)
                else:
                    # Con señal: intentar flush de cola primero
                    if not self.queue.is_empty():
                        self._sync_queue()

                    # Enviar lectura actual
                    ok = self.network.post_record(record)
                    if not ok:
                        self.queue.save(record)
                    self._log(record, stored_online=ok)

                time.sleep(LECTURA_SEG)

        except KeyboardInterrupt:
            pass
        finally:
            self._print_summary()

    # ── BANNER / SUMMARY ─────────────────────────────────────────────────────
    def _print_banner(self) -> None:
        mode = "INTERACTIVO [R]=toggle [Q]=salir" if self.interactive else "AUTOMÁTICO (ruta Toluca→Sultepec)"
        print()
        print("  " + "═" * 64)
        print(f"  {C.ok('FríoSeguro')} — Script Puente v1.0")
        print(f"  Modo    : {C.info(mode)}")
        print(f"  Viaje   : {VIAJE_ID}  |  Vehículo: {VEHICULO_ID}")
        print(f"  Servidor: {self.network.url}")
        print(f"  Cola    : {QUEUE_FILE}")
        print(f"  Umbrales: {TEMP_MIN}°C – {TEMP_MAX}°C  ({MEDICAMENTO})")
        print(f"  Zona sin señal: waypoints {OFFLINE_START}–{OFFLINE_END}")
        print("  " + "═" * 64)
        print()

    def _print_summary(self) -> None:
        print(f"\n\n  {C.dim('──── Resumen de sesión ────')}")
        print(f"  Lecturas totales  : {self.reading_cnt}")
        print(f"  Sincronizados     : {self.synced_total}")
        print(f"  En cola (sin sync): {self.queue.count()}")
        print()

    def _start_keyboard_thread(self) -> None:
        def listener():
            while not self._stop.is_set():
                try:
                    key = input().strip().lower()
                    if   key == 'r': self.toggle_rural()
                    elif key == 'q': self._stop.set()
                except EOFError:
                    break
        threading.Thread(target=listener, daemon=True).start()


# ══════════════════════════════════════════════════════════════════════════════
#  ENTRY POINT
# ══════════════════════════════════════════════════════════════════════════════
def main() -> None:
    global SERVER_URL, VIAJE_ID  # declarar antes de cualquier uso en esta función
    parser = argparse.ArgumentParser(
        description='FríoSeguro Bridge Script — Fase 1',
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('-i', '--interactive', action='store_true',
                        help='Toggle manual de MODO_RURAL con tecla [R]')
    parser.add_argument('-s', '--server',  default=SERVER_URL,
                        help=f'URL del servidor API (default: {SERVER_URL})')
    parser.add_argument('--serial',
                        help='Puerto serial del Arduino (ej: COM3 o /dev/ttyUSB0)')
    parser.add_argument('--baud', type=int, default=9600,
                        help='Baudrate del puerto serial (default: 9600)')
    parser.add_argument('--viaje', default=VIAJE_ID,
                        help=f'ID del viaje (default: {VIAJE_ID})')
    args = parser.parse_args()

    # Override globals desde CLI
    SERVER_URL = args.server
    VIAJE_ID   = args.viaje

    # Seleccionar sensor (serial real o simulado)
    if args.serial:
        if not HAS_SERIAL:
            print(C.err("Error: pyserial no instalado. Ejecuta: pip install pyserial"))
            sys.exit(1)
        print(C.info(f"[SENSOR] Usando puerto serial: {args.serial} @ {args.baud} baud"))
        sensor = SerialReader(args.serial, args.baud)
    else:
        print(C.dim("[SENSOR] Usando simulación (sin Serial real)"))
        sensor = SensorSimulator()

    bridge = BridgeScript(
        sensor=sensor,
        interactive=args.interactive,
        server_url=args.server,
    )
    bridge.run()


if __name__ == '__main__':
    main()
