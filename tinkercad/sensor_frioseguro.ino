/*
 * FríoSeguro — Sensor de Cadena de Frío
 * =========================================
 * Circuito Tinkercad / Arduino Uno
 * HACKATEC LOCAL 2026 — Tecnologías Emergentes
 * TESI Cuautitlán Izcalli
 *
 * ── Componentes del circuito ─────────────────────────────────────────────────
 *   • TMP36          → A0        (sensor de temperatura analógico)
 *   • Sensor puerta  → D2        (interruptor, INPUT_PULLUP; LOW = puerta abierta)
 *   • Botón RURAL    → D3        (toggle MODO_RURAL, INPUT_PULLUP; LOW = activo)
 *   • LED Verde      → D8 + R330 (sistema en línea)
 *   • LED Rojo       → D9 + R330 (MODO_RURAL activo — sin señal)
 *   • LED Amarillo   → D10 + R330(puerta abierta detectada)
 *
 * ── Formato de trama Serial (9600 baud, 1 trama cada 5 s) ────────────────────
 *   {"temp":5.20,"puerta":0,"lat":19.2920,"lng":-99.6570,"ts":1748000100}
 *
 * El script bridge.py lee esta trama y gestiona la cola offline cuando
 * MODO_RURAL está activo (pérdida de señal simulada en zonas marginales).
 *
 * ── Diagrama de conexiones ───────────────────────────────────────────────────
 *   TMP36    : Vcc→5V, GND→GND, SALIDA→A0
 *   SW Puerta: Un extremo→D2, otro extremo→GND  (INPUT_PULLUP interno)
 *   Btn Rural: Un extremo→D3, otro extremo→GND  (INPUT_PULLUP interno)
 *   LED Verde : D8→R330Ω→LED→GND
 *   LED Rojo  : D9→R330Ω→LED→GND
 *   LED Amrll : D10→R330Ω→LED→GND
 */

// ─── Pines ────────────────────────────────────────────────────────────────────
const int PIN_TMP36        = A0;
const int PIN_PUERTA       = 2;
const int PIN_BTN_RURAL    = 3;
const int PIN_LED_VERDE    = 8;
const int PIN_LED_ROJO     = 9;
const int PIN_LED_AMARILLO = 10;

// ─── Parámetros de cadena de frío ─────────────────────────────────────────────
const float TEMP_MIN   = 2.0;   // °C — límite inferior seguro (vacunas/insulina)
const float TEMP_MAX   = 8.0;   // °C — límite superior seguro
const float DRIFT_PASO = 0.25;  // °C de deriva por lectura cuando refrigeración falla

// ─── Ruta simulada Toluca → Sultepec (10 waypoints) ──────────────────────────
const int NUM_WP = 10;
const float ROUTE_LAT[NUM_WP] = {
  19.2920, 19.2500, 19.2100, 19.1700, 19.1400,
  19.0900, 19.0500, 19.0100, 18.9700, 18.9400
};
const float ROUTE_LNG[NUM_WP] = {
  -99.6570, -99.6400, -99.6200, -99.6000, -99.5800,
  -99.5600, -99.5400, -99.5200, -99.5000, -99.4800
};

// ─── Timestamp base (Unix epoch, referencia mayo 2026) ───────────────────────
const unsigned long TS_OFFSET = 1748000000UL;

// ─── Estado global ────────────────────────────────────────────────────────────
bool          modoRural      = false;
bool          btnRuralPrev   = HIGH;
int           waypointIdx    = 0;
float         tempBase       = 5.0;
unsigned long ultimaLectura  = 0;
int           contLecturas   = 0;

// ─────────────────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(9600);

  pinMode(PIN_PUERTA,       INPUT_PULLUP);
  pinMode(PIN_BTN_RURAL,    INPUT_PULLUP);
  pinMode(PIN_LED_VERDE,    OUTPUT);
  pinMode(PIN_LED_ROJO,     OUTPUT);
  pinMode(PIN_LED_AMARILLO, OUTPUT);

  // Secuencia de arranque: parpadeo triple verde
  for (int i = 0; i < 3; i++) {
    digitalWrite(PIN_LED_VERDE, HIGH);
    delay(200);
    digitalWrite(PIN_LED_VERDE, LOW);
    delay(200);
  }
  digitalWrite(PIN_LED_VERDE, HIGH);  // Sistema listo → LED verde fijo
}

// ─────────────────────────────────────────────────────────────────────────────
void loop() {

  // ── 1. Detectar toggle MODO_RURAL (flanco descendente del botón) ─────────
  bool btnAhora = digitalRead(PIN_BTN_RURAL);
  if (btnRuralPrev == HIGH && btnAhora == LOW) {
    modoRural = !modoRural;
    delay(50);  // anti-rebote
  }
  btnRuralPrev = btnAhora;

  // ── 2. Actualizar LEDs de estado ──────────────────────────────────────────
  digitalWrite(PIN_LED_VERDE, modoRural ? LOW  : HIGH);
  digitalWrite(PIN_LED_ROJO,  modoRural ? HIGH : LOW);

  // ── 3. Emitir trama cada 5 segundos ──────────────────────────────────────
  unsigned long ahora = millis();
  if (ahora - ultimaLectura < 5000UL) return;
  ultimaLectura = ahora;

  // --- Lectura física del TMP36 ---
  // Voltaje: V = raw * (5.0 / 1023.0)
  // Temperatura TMP36: T(°C) = (V - 0.5) * 100
  int   raw     = analogRead(PIN_TMP36);
  float voltaje = raw * (5.0f / 1023.0f);
  float tempFisica = (voltaje - 0.5f) * 100.0f;

  // --- Modelo de temperatura simulada ---
  // MODO_RURAL activo: refrigeración sin monitoreo → deriva térmica hacia arriba
  // MODO_RURAL inactivo: sistema controlado → temperatura estable en rango seguro
  if (modoRural) {
    tempBase += DRIFT_PASO + (random(0, 30) / 100.0f);
  } else {
    float delta = (float)random(-18, 18) / 100.0f;
    tempBase   += delta;
    tempBase    = constrain(tempBase, TEMP_MIN + 0.6f, TEMP_MAX - 0.6f);
  }
  tempBase = constrain(tempBase, 0.0f, 28.0f);

  // Combinar: 70% modelo + 30% lectura real del TMP36
  float tempFinal = (tempBase * 0.7f) + (tempFisica * 0.3f);
  tempFinal = constrain(tempFinal, 0.0f, 28.0f);

  // --- Sensor de puerta ---
  int puertaAbierta = (digitalRead(PIN_PUERTA) == LOW) ? 1 : 0;
  digitalWrite(PIN_LED_AMARILLO, puertaAbierta ? HIGH : LOW);
  if (puertaAbierta) tempBase += 1.5f;  // apertura causa pico térmico

  // --- Posición GPS simulada a lo largo de la ruta ---
  // Un waypoint nuevo cada 4 lecturas (~20 s por punto de la ruta)
  contLecturas++;
  if (contLecturas % 4 == 0 && waypointIdx < NUM_WP - 1) {
    waypointIdx++;
  }
  float lat = ROUTE_LAT[waypointIdx] + (float)random(-5, 5) / 10000.0f;
  float lng = ROUTE_LNG[waypointIdx] + (float)random(-5, 5) / 10000.0f;

  // --- Timestamp Unix simulado ---
  unsigned long ts = TS_OFFSET + (ahora / 1000UL);

  // ── 4. Emitir JSON por Serial ─────────────────────────────────────────────
  // Formato esperado por bridge.py (SerialReader):
  // {"temp":5.20,"puerta":0,"lat":19.2920,"lng":-99.6570,"ts":1748000100}
  Serial.print(F("{\"temp\":"));
  Serial.print(tempFinal, 2);
  Serial.print(F(",\"puerta\":"));
  Serial.print(puertaAbierta);
  Serial.print(F(",\"lat\":"));
  Serial.print(lat, 4);
  Serial.print(F(",\"lng\":"));
  Serial.print(lng, 4);
  Serial.print(F(",\"ts\":"));
  Serial.print(ts);
  Serial.println(F("}"));
}
