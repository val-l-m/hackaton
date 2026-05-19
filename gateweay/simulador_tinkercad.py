import json
import random
import time
import requests
from datetime import datetime

API_URL = "http://localhost/hackaton/backend/insertar_lectura.php"

latitud = 19.432100
longitud = -99.133200

while True:

    latitud += 0.0001
    longitud += 0.0001

    datos = {

        "viaje_id": "CAMION_01",

        "temperatura_actual":
        round(random.uniform(2,8),2),

        "sensor_puerta":
        random.randint(0,1),

        "latitud_actual":
        round(latitud,6),

        "longitud_actual":
        round(longitud,6),

        "timestamp_lectura_real":
        datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    }

    print("\nDATOS:")
    print(datos)

    """
    GUARDAR LOCAL
    """

    with open("datos_locales.json", "a") as archivo:
        archivo.write(json.dumps(datos) + "\n")

    try:

        response = requests.post(
            API_URL,
            data=datos,
            timeout=3
        )

        print("SINCRONIZADO")

    except:

        print("SIN INTERNET")
        print("GUARDADO LOCALMENTE")

        with open("pendientes.json", "a") as archivo:
            archivo.write(json.dumps(datos) + "\n")

    time.sleep(3)