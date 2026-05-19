import serial
import json
import requests
from datetime import datetime

PUERTO = 'COM3'
BAUDIOS = 9600

API_URL = "http://localhost/hackaton/backend/insertar_lectura.php"

ser = serial.Serial(PUERTO, BAUDIOS)

print("Escuchando datos...\n")

while True:

    try:

        linea = ser.readline().decode().strip()

        print("RECIBIDO:")
        print(linea)

        partes = linea.split(",")

        datos = {

            "viaje_id": partes[0],
            "temperatura_actual": partes[1],
            "sensor_puerta": partes[2],
            "latitud_actual": partes[3],
            "longitud_actual": partes[4],
            "timestamp_lectura_real": partes[5]

        }

        """
        GUARDAR LOCAL JSON
        """

        with open("datos_locales.json", "a") as archivo:
            archivo.write(json.dumps(datos) + "\n")

        """
        ENVIAR AL BACKEND
        """

        response = requests.post(API_URL, data=datos)

        print("ENVIADO A PHP:")
        print(response.text)

    except Exception as e:

        print("SIN CONEXIÓN O ERROR")
        print(e)

        """
        GUARDAR PENDIENTE
        """

        with open("pendientes.json", "a") as archivo:
            archivo.write(json.dumps(datos) + "\n")